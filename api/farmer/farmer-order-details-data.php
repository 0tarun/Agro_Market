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

$orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid order id',
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

    $orderStmt = $pdo->prepare(
        'SELECT o.id, o.order_code, o.consumer_id, o.status, o.payment_status, o.payment_method, o.placed_at,
                u.full_name, u.email, u.phone, u.profile_image, u.address_line, u.district, u.division
         FROM orders o
         JOIN users u ON u.id = o.consumer_id
         WHERE o.id = :order_id AND o.farmer_id = :farmer_id
         LIMIT 1'
    );
    $orderStmt->execute([
        ':order_id' => $orderId,
        ':farmer_id' => $userId,
    ]);
    $order = $orderStmt->fetch();

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'message' => 'Order not found',
        ]);
        exit;
    }

    $consumerId = (int)($order['consumer_id'] ?? 0);
    $itemsStmt = $pdo->prepare(
        'SELECT o.id AS order_id, o.order_code, o.placed_at, o.status, o.payment_status, o.payment_method,
                oi.product_name_snapshot, oi.qty, oi.line_total
         FROM orders o
         JOIN order_items oi ON oi.order_id = o.id
         WHERE o.farmer_id = :farmer_id AND o.consumer_id = :consumer_id
         ORDER BY o.placed_at DESC, oi.id DESC
         LIMIT 50'
    );
    $itemsStmt->execute([
        ':farmer_id' => $userId,
        ':consumer_id' => $consumerId,
    ]);

    $rows = $itemsStmt->fetchAll() ?: [];
    $items = [];

    foreach ($rows as $row) {
        $statusRaw = strtolower((string)($row['status'] ?? 'pending'));
        $statusClass = 'processing';
        $statusLabel = 'Processing';

        if ($statusRaw === 'completed') {
            $statusClass = 'completed';
            $statusLabel = 'Completed';
        } elseif ($statusRaw === 'cancelled') {
            $statusClass = 'cancelled';
            $statusLabel = 'Cancelled';
        } elseif ($statusRaw === 'refund_return') {
            $statusClass = 'return';
            $statusLabel = 'Refund/Return';
        }

        $paymentStatus = ucwords(str_replace('_', ' ', strtolower((string)($row['payment_status'] ?? 'pending'))));

        $items[] = [
            'order_id' => (int)($row['order_id'] ?? 0),
            'order_code' => (string)($row['order_code'] ?? ''),
            'product' => (string)($row['product_name_snapshot'] ?? 'Product'),
            'qty' => (float)($row['qty'] ?? 0),
            'order_date' => (string)($row['placed_at'] ?? ''),
            'price' => (float)($row['line_total'] ?? 0),
            'payment' => $paymentStatus,
            'status_raw' => $statusRaw,
            'status_class' => $statusClass,
            'status_label' => $statusLabel,
            'method' => ucfirst((string)($row['payment_method'] ?? 'cash')),
        ];
    }

    $address = composeAddress(
        (string)($order['address_line'] ?? ''),
        (string)($order['district'] ?? ''),
        (string)($order['division'] ?? '')
    );

    $consumer = [
        'name' => (string)($order['full_name'] ?? 'Consumer'),
        'email' => (string)($order['email'] ?? ''),
        'phone' => (string)($order['phone'] ?? ''),
        'address' => $address,
        'profile_image' => toPublicAssetPath((string)($order['profile_image'] ?? '/figma/images (2).jpg')),
        'meta' => 'Order ' . (string)($order['order_code'] ?? '') . ' at ' . (string)($order['placed_at'] ?? ''),
    ];

    echo json_encode([
        'ok' => true,
        'consumer' => $consumer,
        'latest_orders' => $items,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to load order details',
    ]);
}

function composeAddress(string $addressLine, string $district, string $division): string
{
    $parts = array_values(array_filter([$addressLine, $district, $division], static function ($value) {
        return trim((string)$value) !== '';
    }));

    return $parts ? implode(', ', $parts) : 'Location not set';
}

function toPublicAssetPath(string $rawPath): string
{
    $path = trim(str_replace('\\', '/', $rawPath));
    if ($path === '') {
        $path = '/figma/images (2).jpg';
    }

    if (!preg_match('#^(https?:)?//#i', $path) && strpos($path, 'data:') !== 0) {
        if ($path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        $basePath = trim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''), 3)), '/');
        if ($basePath !== '') {
            $prefix = '/' . $basePath;
            if (strpos($path, $prefix . '/') !== 0 && $path !== $prefix) {
                $path = $prefix . $path;
            }
        }
    }

    return $path;
}
