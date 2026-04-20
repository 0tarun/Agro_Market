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

    if ($role !== 'farmer') {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'Only farmers can access this',
        ]);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $filter = strtolower(trim((string)($input['filter'] ?? 'all')));

    $where = 'o.farmer_id = :farmer_id';
    $params = [':farmer_id' => $userId];

    if ($filter === 'to_receive') {
        $where .= ' AND o.status IN ("pending", "to_receive")';
    } elseif (in_array($filter, ['refund_return', 'completed', 'cancelled'], true)) {
        $where .= ' AND o.status = :status_filter';
        $params[':status_filter'] = $filter;
    }

    $sql =
        'SELECT
            oi.id AS order_item_id,
            o.id AS order_id,
            o.order_code,
            o.status,
            o.payment_status,
            o.payment_method,
            o.placed_at,
            oi.product_name_snapshot,
            oi.qty,
            oi.line_total,
            COALESCE(p.image_path, "/figma/images (2).jpg") AS image_path,
            COALESCE(u.full_name, "Customer") AS consumer_name
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.id
         LEFT JOIN products p ON p.id = oi.product_id
         LEFT JOIN users u ON u.id = o.consumer_id
         WHERE ' . $where . '
         ORDER BY o.placed_at DESC, oi.id DESC
         LIMIT 250';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $items = [];
    foreach ($rows as $row) {
        $statusRaw = strtolower((string)($row['status'] ?? 'pending'));
        $statusClass = 'receive';
        $statusLabel = 'To receive';

        if ($statusRaw === 'completed') {
            $statusClass = 'complete';
            $statusLabel = 'Complete';
        } elseif ($statusRaw === 'refund_return') {
            $statusClass = 'return';
            $statusLabel = 'Refund/Return';
        } elseif ($statusRaw === 'cancelled') {
            $statusClass = 'cancelled';
            $statusLabel = 'Cancelled';
        }

        $paymentStatusRaw = strtolower((string)($row['payment_status'] ?? 'pending'));
        $paymentLabel = ucwords(str_replace('_', ' ', $paymentStatusRaw));

        $items[] = [
            'order_item_id' => (int)($row['order_item_id'] ?? 0),
            'order_id' => (int)($row['order_id'] ?? 0),
            'order_code' => (string)($row['order_code'] ?? ''),
            'product_name' => (string)($row['product_name_snapshot'] ?? 'Product'),
            'qty' => (float)($row['qty'] ?? 0),
            'line_total' => (float)($row['line_total'] ?? 0),
            'payment_label' => $paymentLabel,
            'status_label' => $statusLabel,
            'status_class' => $statusClass,
            'consumer_name' => (string)($row['consumer_name'] ?? 'Customer'),
            'image_path' => toPublicAssetPath((string)($row['image_path'] ?? '')),
        ];
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to load farmer orders',
    ]);
}

function toPublicAssetPath(string $rawPath): string
{
    $path = trim(str_replace('\\\\', '/', $rawPath));
    if ($path === '') {
        $path = '/figma/images (2).jpg';
    }

    if (!preg_match('#^(https?:)?//#i', $path) && strpos($path, 'data:') !== 0) {
        if ($path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        $basePath = trim(str_replace('\\\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''), 3)), '/');
        if ($basePath !== '') {
            $prefix = '/' . $basePath;
            if (strpos($path, $prefix . '/') !== 0 && $path !== $prefix) {
                $path = $prefix . $path;
            }
        }
    }

    return $path;
}
