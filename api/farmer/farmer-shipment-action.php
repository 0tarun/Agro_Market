<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

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
    if ($role !== 'farmer') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Only farmers can access this']);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $action = strtolower(trim((string)($input['action'] ?? '')));

    if ($action === 'get_shipment') {
        // ── GET SHIPMENT DETAILS ──
        $orderId = (int)($input['order_id'] ?? 0);
        if ($orderId <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Invalid order id']);
            exit;
        }

        // Verify farmer owns this order
        $orderStmt = $pdo->prepare(
            'SELECT o.id, o.order_code, o.status, o.consumer_id, o.total_amount, o.payment_method, o.shipping_address, o.placed_at,
                    u.full_name AS consumer_name
             FROM orders o
             LEFT JOIN users u ON u.id = o.consumer_id
             WHERE o.id = :order_id AND o.farmer_id = :farmer_id
             LIMIT 1'
        );
        $orderStmt->execute([':order_id' => $orderId, ':farmer_id' => $userId]);
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
        $shipStmt = $pdo->prepare(
            'SELECT * FROM shipments WHERE order_id = :order_id LIMIT 1'
        );
        $shipStmt->execute([':order_id' => $orderId]);
        $shipment = $shipStmt->fetch();

        $events = [];
        if ($shipment) {
            $eventsStmt = $pdo->prepare(
                'SELECT * FROM shipment_events WHERE shipment_id = :sid ORDER BY event_at DESC'
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
                'consumer_name' => (string)($order['consumer_name'] ?? 'Customer'),
                'total_amount' => (float)$order['total_amount'],
                'payment_method' => ucwords(str_replace('_', ' ', (string)$order['payment_method'])),
                'shipping_address' => (string)($order['shipping_address'] ?? ''),
                'placed_at' => (string)$order['placed_at'],
                'items' => $orderItems,
            ],
            'shipment' => $shipment ? [
                'id' => (int)$shipment['id'],
                'tracking_code' => (string)$shipment['tracking_code'],
                'carrier_name' => (string)$shipment['carrier_name'],
                'status' => (string)$shipment['status'],
                'estimated_delivery' => (string)($shipment['estimated_delivery'] ?? ''),
                'shipped_at' => (string)($shipment['shipped_at'] ?? ''),
                'delivered_at' => (string)($shipment['delivered_at'] ?? ''),
                'current_location' => (string)($shipment['current_location'] ?? ''),
                'notes' => (string)($shipment['notes'] ?? ''),
                'created_at' => (string)$shipment['created_at'],
            ] : null,
            'events' => $events,
        ]);
        exit;
    }

    if ($action === 'update_shipment') {
        // ── UPDATE SHIPMENT STATUS ──
        $orderId = (int)($input['order_id'] ?? 0);
        $newStatus = strtolower(trim((string)($input['status'] ?? '')));
        $location = trim((string)($input['location'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));
        $estimatedDelivery = trim((string)($input['estimated_delivery'] ?? ''));

        if ($orderId <= 0) {
            echo json_encode(['ok' => false, 'message' => 'Invalid order id']);
            exit;
        }

        $validStatuses = ['preparing', 'shipped', 'in_transit', 'out_for_delivery', 'delivered'];
        if (!in_array($newStatus, $validStatuses, true)) {
            echo json_encode(['ok' => false, 'message' => 'Invalid shipment status']);
            exit;
        }

        // Verify farmer owns this order
        $orderStmt = $pdo->prepare(
            'SELECT id, status FROM orders WHERE id = :order_id AND farmer_id = :farmer_id LIMIT 1'
        );
        $orderStmt->execute([':order_id' => $orderId, ':farmer_id' => $userId]);
        $order = $orderStmt->fetch();

        if (!$order) {
            echo json_encode(['ok' => false, 'message' => 'Order not found']);
            exit;
        }

        // Get or create shipment
        $shipStmt = $pdo->prepare('SELECT * FROM shipments WHERE order_id = :order_id LIMIT 1');
        $shipStmt->execute([':order_id' => $orderId]);
        $shipment = $shipStmt->fetch();

        if (!$shipment) {
            // Auto-create shipment if none exists
            $trackingCode = generateTrackingCode($pdo);
            $insertShip = $pdo->prepare(
                'INSERT INTO shipments (order_id, tracking_code, status, current_location, notes)
                 VALUES (:order_id, :tracking_code, :status, :location, :notes)'
            );
            $insertShip->execute([
                ':order_id' => $orderId,
                ':tracking_code' => $trackingCode,
                ':status' => 'preparing',
                ':location' => $location ?: null,
                ':notes' => $notes ?: null,
            ]);
            $shipmentId = (int)$pdo->lastInsertId();

            // Log initial event
            $insertEvent = $pdo->prepare(
                'INSERT INTO shipment_events (shipment_id, status, location, description)
                 VALUES (:sid, :status, :location, :description)'
            );
            $insertEvent->execute([
                ':sid' => $shipmentId,
                ':status' => 'preparing',
                ':location' => $location ?: null,
                ':description' => 'Order confirmed, preparing package',
            ]);

            $shipStmt->execute([':order_id' => $orderId]);
            $shipment = $shipStmt->fetch();
        }

        $shipmentId = (int)$shipment['id'];

        // Define status label mapping
        $statusLabels = [
            'preparing' => 'Preparing package',
            'shipped' => 'Package shipped',
            'in_transit' => 'Package in transit',
            'out_for_delivery' => 'Out for delivery',
            'delivered' => 'Package delivered',
        ];

        $pdo->beginTransaction();

        // Update shipment
        $updateFields = ['status = :status', 'current_location = :location', 'updated_at = NOW()'];
        $updateParams = [
            ':status' => $newStatus,
            ':location' => $location ?: null,
            ':sid' => $shipmentId,
        ];

        if ($notes !== '') {
            $updateFields[] = 'notes = :notes';
            $updateParams[':notes'] = $notes;
        }

        if ($estimatedDelivery !== '') {
            $updateFields[] = 'estimated_delivery = :est_delivery';
            $updateParams[':est_delivery'] = $estimatedDelivery;
        }

        if ($newStatus === 'shipped' && (string)($shipment['shipped_at'] ?? '') === '') {
            $updateFields[] = 'shipped_at = NOW()';
        }

        if ($newStatus === 'delivered') {
            $updateFields[] = 'delivered_at = NOW()';
        }

        $updateQuery = 'UPDATE shipments SET ' . implode(', ', $updateFields) . ' WHERE id = :sid';
        $updateShipStmt = $pdo->prepare($updateQuery);
        $updateShipStmt->execute($updateParams);

        // Log event
        $eventDesc = $statusLabels[$newStatus] ?? 'Status updated';
        if ($notes !== '') {
            $eventDesc .= ' — ' . mb_substr($notes, 0, 300);
        }

        $insertEvent = $pdo->prepare(
            'INSERT INTO shipment_events (shipment_id, status, location, description)
             VALUES (:sid, :status, :location, :description)'
        );
        $insertEvent->execute([
            ':sid' => $shipmentId,
            ':status' => $newStatus,
            ':location' => $location ?: null,
            ':description' => $eventDesc,
        ]);

        // Update order status based on shipment status
        $orderStatusMap = [
            'shipped' => 'to_receive',
            'in_transit' => 'to_receive',
            'out_for_delivery' => 'to_receive',
            'delivered' => 'completed',
        ];

        if (isset($orderStatusMap[$newStatus])) {
            $newOrderStatus = $orderStatusMap[$newStatus];
            $updateOrder = $pdo->prepare(
                'UPDATE orders SET status = :status WHERE id = :order_id'
            );
            $updateOrder->execute([
                ':status' => $newOrderStatus,
                ':order_id' => $orderId,
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'ok' => true,
            'message' => $statusLabels[$newStatus] ?? 'Shipment updated',
            'status' => $newStatus,
        ]);
        exit;
    }

    // ── LIST ALL SHIPMENTS FOR THIS FARMER ──
    if ($action === 'list_shipments') {
        $filter = strtolower(trim((string)($input['filter'] ?? 'all')));

        $where = 'o.farmer_id = :farmer_id';
        $params = [':farmer_id' => $userId];

        if (in_array($filter, ['preparing', 'shipped', 'in_transit', 'out_for_delivery', 'delivered'], true)) {
            $where .= ' AND s.status = :ship_status';
            $params[':ship_status'] = $filter;
        }

        $stmt = $pdo->prepare(
            'SELECT
                o.id AS order_id, o.order_code, o.status AS order_status, o.placed_at,
                o.total_amount, o.payment_method,
                s.id AS shipment_id, s.tracking_code, s.status AS shipment_status,
                s.estimated_delivery, s.current_location,
                u.full_name AS consumer_name,
                GROUP_CONCAT(oi.product_name_snapshot SEPARATOR ", ") AS products
             FROM orders o
             INNER JOIN shipments s ON s.order_id = o.id
             LEFT JOIN users u ON u.id = o.consumer_id
             LEFT JOIN order_items oi ON oi.order_id = o.id
             WHERE ' . $where . '
             GROUP BY o.id, s.id
             ORDER BY s.updated_at DESC
             LIMIT 200'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'order_id' => (int)$row['order_id'],
                'order_code' => (string)$row['order_code'],
                'order_status' => (string)$row['order_status'],
                'shipment_id' => (int)$row['shipment_id'],
                'tracking_code' => (string)$row['tracking_code'],
                'shipment_status' => (string)$row['shipment_status'],
                'estimated_delivery' => (string)($row['estimated_delivery'] ?? ''),
                'current_location' => (string)($row['current_location'] ?? ''),
                'consumer_name' => (string)($row['consumer_name'] ?? 'Customer'),
                'products' => (string)($row['products'] ?? ''),
                'total_amount' => (float)$row['total_amount'],
                'payment_method' => ucwords(str_replace('_', ' ', (string)$row['payment_method'])),
                'placed_at' => (string)$row['placed_at'],
            ];
        }

        echo json_encode(['ok' => true, 'items' => $items]);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown action']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
