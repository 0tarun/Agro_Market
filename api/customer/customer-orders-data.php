<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Please login first',
    ]);
    exit;
}

try {
    $userStmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'User not found or inactive',
        ]);
        exit;
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));
    if ($role === 'customer') {
        $role = 'consumer';
    }

    if ($role !== 'consumer') {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'Only customers can access this',
        ]);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $filter = strtolower(trim((string)($input['filter'] ?? 'all')));

    $where = 'o.consumer_id = :consumer_id';
    $params = [':consumer_id' => $userId];

    if ($filter === 'to_receive') {
        $where .= ' AND o.status IN ("pending", "to_receive")';
    } elseif (in_array($filter, ['refund_return', 'completed', 'cancelled'], true)) {
        $where .= ' AND o.status = :status_filter';
        $params[':status_filter'] = $filter;
    }

    $itemsStmt = $pdo->prepare(
        'SELECT
            oi.id AS order_item_id,
            o.id AS order_id,
            o.order_code,
            o.status,
            o.payment_method,
            o.placed_at,
            oi.product_name_snapshot,
            oi.qty,
            oi.line_total
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.id
         WHERE ' . $where . '
         ORDER BY o.placed_at DESC, oi.id DESC
         LIMIT 250'
    );
    $itemsStmt->execute($params);

    $rows = $itemsStmt->fetchAll() ?: [];
    $items = [];

    foreach ($rows as $row) {
        $statusRaw = strtolower((string)($row['status'] ?? 'pending'));
        $statusClass = 'processing';
        $statusLabel = 'Processing';

        if ($statusRaw === 'completed') {
            $statusClass = 'completed';
            $statusLabel = 'Completed';
        } elseif ($statusRaw === 'refund_return') {
            $statusClass = 'return';
            $statusLabel = 'Refund/Return';
        } elseif ($statusRaw === 'cancelled') {
            $statusClass = 'cancelled';
            $statusLabel = 'Cancelled';
        }

        $items[] = [
            'order_item_id' => (int)($row['order_item_id'] ?? 0),
            'order_id' => (int)($row['order_id'] ?? 0),
            'order_code' => (string)($row['order_code'] ?? ''),
            'product_name' => (string)($row['product_name_snapshot'] ?? 'Product'),
            'qty' => (float)($row['qty'] ?? 0),
            'placed_at' => (string)($row['placed_at'] ?? ''),
            'line_total' => (float)($row['line_total'] ?? 0),
            'payment_method' => ucwords(str_replace('_', ' ', (string)($row['payment_method'] ?? 'cash'))),
            'status_class' => $statusClass,
            'status_label' => $statusLabel,
        ];
    }

    $summaryStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN status IN ("pending", "to_receive") THEN 1 ELSE 0 END) AS processing_count,
            SUM(CASE WHEN status = "refund_return" THEN 1 ELSE 0 END) AS refund_count,
            SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) AS completed_count,
            COUNT(*) AS total_orders
         FROM orders
         WHERE consumer_id = :consumer_id'
    );
    $summaryStmt->execute([':consumer_id' => $userId]);
    $summary = $summaryStmt->fetch() ?: [];

    echo json_encode([
        'ok' => true,
        'summary' => [
            'processing_count' => (int)($summary['processing_count'] ?? 0),
            'refund_count' => (int)($summary['refund_count'] ?? 0),
            'completed_count' => (int)($summary['completed_count'] ?? 0),
            'total_orders' => (int)($summary['total_orders'] ?? 0),
        ],
        'items' => $items,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to load orders',
    ]);
}
