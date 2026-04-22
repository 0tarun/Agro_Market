<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Please login first']);
    exit;
}

try {
    $userStmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'User not found or inactive']);
        exit;
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));
    if ($role === 'customer') {
        $role = 'consumer';
    }

    if ($role !== 'consumer') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Only customers can access this']);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $action = strtolower(trim((string)($input['action'] ?? 'get_shipment')));

    if ($action === 'get_shipment') {
        $orderId = (int)($input['order_id'] ?? 0);

        if ($orderId <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Invalid order id']);
            exit;
        }

        // Verify customer owns this order
        $orderStmt = $pdo->prepare(
            'SELECT o.id, o.order_code, o.status, o.farmer_id, o.total_amount, o.payment_method,
                    o.shipping_address, o.placed_at,
                    f.full_name AS farmer_name
             FROM orders o
             LEFT JOIN users f ON f.id = o.farmer_id
             WHERE o.id = :order_id AND o.consumer_id = :consumer_id
             LIMIT 1'
        );
        $orderStmt->execute([':order_id' => $orderId, ':consumer_id' => $userId]);
        $order = $orderStmt->fetch();

        if (!$order) {
            echo json_encode(['ok' => false, 'message' => 'Order not found']);
            exit;
        }

        // Get order items
        $itemsStmt = $pdo->prepare(
            'SELECT product_name_snapshot, qty, unit_price, line_total
             FROM order_items WHERE order_id = :order_id'
        );
        $itemsStmt->execute([':order_id' => $orderId]);
        $orderItems = $itemsStmt->fetchAll() ?: [];

        // Get shipment
        $shipStmt = $pdo->prepare('SELECT * FROM shipments WHERE order_id = :order_id LIMIT 1');
        $shipStmt->execute([':order_id' => $orderId]);
        $shipment = $shipStmt->fetch();

        $events = [];
        if ($shipment) {
            $eventsStmt = $pdo->prepare(
                'SELECT status, location, description, event_at
                 FROM shipment_events WHERE shipment_id = :sid ORDER BY event_at DESC'
            );
            $eventsStmt->execute([':sid' => (int)$shipment['id']]);
            $events = $eventsStmt->fetchAll() ?: [];
        }

        echo json_encode([
            'ok' => true,
            'order' => [
                'id' => (int)$order['id'],
                'order_code' => (string)$order['order_code'],
                'status' => (string)$order['status'],
                'farmer_name' => (string)($order['farmer_name'] ?? 'Farmer'),
                'total_amount' => (float)$order['total_amount'],
                'payment_method' => ucwords(str_replace('_', ' ', (string)$order['payment_method'])),
                'shipping_address' => (string)($order['shipping_address'] ?? ''),
                'placed_at' => (string)$order['placed_at'],
                'items' => $orderItems,
            ],
            'shipment' => $shipment ? [
                'tracking_code' => (string)$shipment['tracking_code'],
                'carrier_name' => (string)$shipment['carrier_name'],
                'status' => (string)$shipment['status'],
                'estimated_delivery' => (string)($shipment['estimated_delivery'] ?? ''),
                'shipped_at' => (string)($shipment['shipped_at'] ?? ''),
                'delivered_at' => (string)($shipment['delivered_at'] ?? ''),
                'current_location' => (string)($shipment['current_location'] ?? ''),
                'created_at' => (string)$shipment['created_at'],
            ] : null,
            'events' => $events,
        ]);
        exit;
    }

    if ($action === 'list_shipments') {
        $stmt = $pdo->prepare(
            'SELECT
                o.id AS order_id, o.order_code, o.status AS order_status, o.placed_at,
                o.total_amount,
                s.tracking_code, s.status AS shipment_status,
                s.estimated_delivery, s.current_location,
                f.full_name AS farmer_name,
                GROUP_CONCAT(oi.product_name_snapshot SEPARATOR ", ") AS products
             FROM orders o
             INNER JOIN shipments s ON s.order_id = o.id
             LEFT JOIN users f ON f.id = o.farmer_id
             LEFT JOIN order_items oi ON oi.order_id = o.id
             WHERE o.consumer_id = :consumer_id
             GROUP BY o.id, s.id
             ORDER BY s.updated_at DESC
             LIMIT 200'
        );
        $stmt->execute([':consumer_id' => $userId]);
        $rows = $stmt->fetchAll() ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'order_id' => (int)$row['order_id'],
                'order_code' => (string)$row['order_code'],
                'order_status' => (string)$row['order_status'],
                'tracking_code' => (string)$row['tracking_code'],
                'shipment_status' => (string)$row['shipment_status'],
                'estimated_delivery' => (string)($row['estimated_delivery'] ?? ''),
                'current_location' => (string)($row['current_location'] ?? ''),
                'farmer_name' => (string)($row['farmer_name'] ?? 'Farmer'),
                'products' => (string)($row['products'] ?? ''),
                'total_amount' => (float)$row['total_amount'],
                'placed_at' => (string)$row['placed_at'],
            ];
        }

        echo json_encode(['ok' => true, 'items' => $items]);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to load shipment data']);
}
