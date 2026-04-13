<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/database.php';

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

    $statsStmt = $pdo->prepare(
        'SELECT
            COUNT(DISTINCT o.consumer_id) AS total_customers,
            COALESCE(SUM(CASE WHEN o.status = "completed" THEN oi.qty ELSE 0 END), 0) AS sold_items,
            COUNT(DISTINCT o.id) AS total_orders,
            COALESCE(SUM(CASE WHEN o.status = "completed" THEN o.total_amount ELSE 0 END), 0) AS total_revenue
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id
         WHERE o.farmer_id = :farmer_id'
    );
    $statsStmt->execute([':farmer_id' => $userId]);
    $stats = $statsStmt->fetch() ?: [];

    $unreadStmt = $pdo->prepare(
        'SELECT COUNT(*) AS unread_messages
         FROM messages m
         INNER JOIN message_threads t ON t.id = m.thread_id
         WHERE t.farmer_id = :farmer_id
           AND m.sender_id <> :sender_farmer_id
           AND m.is_read = 0'
    );
    $unreadStmt->execute([
        ':farmer_id' => $userId,
        ':sender_farmer_id' => $userId,
    ]);
    $unread = $unreadStmt->fetch() ?: [];

    $latestMessageStmt = $pdo->prepare(
        'SELECT
            t.id AS thread_id,
            u.full_name AS consumer_name,
            u.profile_image AS consumer_image,
            m.message_text,
            m.created_at,
            m.is_read
         FROM message_threads t
         INNER JOIN users u ON u.id = t.consumer_id
         LEFT JOIN messages m ON m.id = (
            SELECT m2.id
            FROM messages m2
            WHERE m2.thread_id = t.id
            ORDER BY m2.created_at DESC, m2.id DESC
            LIMIT 1
         )
         WHERE t.farmer_id = :farmer_id
         ORDER BY COALESCE(m.created_at, t.last_message_at, t.updated_at) DESC
         LIMIT 1'
    );
    $latestMessageStmt->execute([':farmer_id' => $userId]);
    $latestMessage = $latestMessageStmt->fetch() ?: null;

    $topProductsStmt = $pdo->prepare(
        'SELECT
            oi.product_name_snapshot AS product_name,
            COALESCE(SUM(oi.qty), 0) AS total_qty,
            COALESCE(MAX(p.image_path), "/figma/images (2).jpg") AS image_path
         FROM orders o
         INNER JOIN order_items oi ON oi.order_id = o.id
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE o.farmer_id = :farmer_id
           AND o.status IN ("completed", "to_receive")
         GROUP BY oi.product_name_snapshot
         ORDER BY total_qty DESC
         LIMIT 6'
    );
    $topProductsStmt->execute([':farmer_id' => $userId]);

    $topProducts = [];
    foreach (($topProductsStmt->fetchAll() ?: []) as $row) {
        $topProducts[] = [
            'name' => (string)($row['product_name'] ?? 'Product'),
            'qty' => (float)($row['total_qty'] ?? 0),
            'image_path' => toPublicAssetPath((string)($row['image_path'] ?? '')),
        ];
    }

    $latestOrdersStmt = $pdo->prepare(
        'SELECT
            o.id AS order_id,
            o.order_code,
            o.status,
            o.payment_status,
            o.total_amount,
            o.placed_at,
            COALESCE(oi_first.product_name_snapshot, "Product") AS product_name
         FROM orders o
         LEFT JOIN order_items oi_first ON oi_first.id = (
            SELECT oi2.id
            FROM order_items oi2
            WHERE oi2.order_id = o.id
            ORDER BY oi2.id ASC
            LIMIT 1
         )
         WHERE o.farmer_id = :farmer_id
         ORDER BY o.placed_at DESC, o.id DESC
         LIMIT 8'
    );
    $latestOrdersStmt->execute([':farmer_id' => $userId]);

    $latestOrders = [];
    foreach (($latestOrdersStmt->fetchAll() ?: []) as $row) {
        $statusRaw = strtolower((string)($row['status'] ?? 'pending'));
        $statusClass = 'processing';
        $statusLabel = 'Processing';

        if ($statusRaw === 'completed') {
            $statusClass = 'completed';
            $statusLabel = 'Completed';
        } elseif ($statusRaw === 'refund_return') {
            $statusClass = 'refund';
            $statusLabel = 'Refund/Return';
        } elseif ($statusRaw === 'cancelled') {
            $statusClass = 'cancelled';
            $statusLabel = 'Cancelled';
        } elseif ($statusRaw === 'to_receive') {
            $statusClass = 'receive';
            $statusLabel = 'To Receive';
        }

        $latestOrders[] = [
            'order_id' => (int)($row['order_id'] ?? 0),
            'order_code' => (string)($row['order_code'] ?? ''),
            'product_name' => (string)($row['product_name'] ?? 'Product'),
            'placed_at' => (string)($row['placed_at'] ?? ''),
            'amount' => (float)($row['total_amount'] ?? 0),
            'payment_status' => ucwords(str_replace('_', ' ', strtolower((string)($row['payment_status'] ?? 'pending')))),
            'status_label' => $statusLabel,
            'status_class' => $statusClass,
        ];
    }

    echo json_encode([
        'ok' => true,
        'stats' => [
            'total_customers' => (int)($stats['total_customers'] ?? 0),
            'sold_items' => (float)($stats['sold_items'] ?? 0),
            'unread_messages' => (int)($unread['unread_messages'] ?? 0),
            'total_orders' => (int)($stats['total_orders'] ?? 0),
            'total_revenue' => (float)($stats['total_revenue'] ?? 0),
        ],
        'latest_message' => $latestMessage ? [
            'thread_id' => (int)($latestMessage['thread_id'] ?? 0),
            'consumer_name' => (string)($latestMessage['consumer_name'] ?? 'Customer'),
            'consumer_image' => toPublicAssetPath((string)($latestMessage['consumer_image'] ?? '/figma/images (2).jpg')),
            'text' => (string)($latestMessage['message_text'] ?? ''),
            'created_at' => (string)($latestMessage['created_at'] ?? ''),
            'is_unread' => isset($latestMessage['is_read']) ? ((int)$latestMessage['is_read'] === 0) : false,
        ] : null,
        'top_products' => $topProducts,
        'latest_orders' => $latestOrders,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to load dashboard data',
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

        $basePath = trim(str_replace('\\\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($basePath !== '') {
            $prefix = '/' . $basePath;
            if (strpos($path, $prefix . '/') !== 0 && $path !== $prefix) {
                $path = $prefix . $path;
            }
        }
    }

    return $path;
}
