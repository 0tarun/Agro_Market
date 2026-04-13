<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Please login first',
    ]);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true) ?? [];
$items = is_array($input['items'] ?? null) ? $input['items'] : [];
$paymentMethod = strtolower(trim((string)($input['payment_method'] ?? 'cash')));
$shippingAddress = trim((string)($input['shipping_address'] ?? ''));

$allowedMethods = ['cash', 'nagad', 'bkash', 'bank_transfer'];
if (!in_array($paymentMethod, $allowedMethods, true)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid payment method',
    ]);
    exit;
}

if (!$items) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Cart is empty',
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
            'message' => 'Only customers can place orders',
        ]);
        exit;
    }

    $normalizedItems = [];
    $productIds = [];
    foreach ($items as $item) {
        $pid = (int)($item['id'] ?? 0);
        $qty = (float)($item['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            continue;
        }

        if (!isset($normalizedItems[$pid])) {
            $normalizedItems[$pid] = 0.0;
        }
        $normalizedItems[$pid] += $qty;
        $productIds[] = $pid;
    }

    $productIds = array_values(array_unique($productIds));
    if (!$productIds) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'No valid products in cart',
        ]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $productsStmt = $pdo->prepare(
        "SELECT id, farmer_id, name, price, stock_qty, harvest_date, created_at, is_active
         FROM products
         WHERE id IN ($placeholders)
         FOR UPDATE"
    );
    $productsStmt->execute($productIds);
    $productRows = $productsStmt->fetchAll() ?: [];

    $productsById = [];
    foreach ($productRows as $row) {
        $productsById[(int)$row['id']] = $row;
    }

    foreach ($normalizedItems as $pid => $qty) {
        if (!isset($productsById[$pid])) {
            throw new RuntimeException('Product not found');
        }

        $product = $productsById[$pid];
        $pricing = calculatePerishableProductPricing($product);
        if ((int)($product['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('A product in cart is no longer active');
        }

        if ($pricing['is_expired']) {
            throw new RuntimeException('One or more products in your cart have expired');
        }

        if ((float)($product['stock_qty'] ?? 0) < $qty) {
            throw new RuntimeException('Insufficient stock for ' . (string)($product['name'] ?? 'product'));
        }
    }

    // Group products by farmer because orders table uses one farmer per order.
    $byFarmer = [];
    foreach ($normalizedItems as $pid => $qty) {
        $product = $productsById[$pid];
        $pricing = calculatePerishableProductPricing($product);
        $farmerId = (int)($product['farmer_id'] ?? 0);
        if ($farmerId <= 0) {
            throw new RuntimeException('Invalid farmer mapping for product');
        }

        if (!isset($byFarmer[$farmerId])) {
            $byFarmer[$farmerId] = [];
        }

        $byFarmer[$farmerId][] = [
            'id' => $pid,
            'name' => (string)($product['name'] ?? 'Product'),
            'price' => (float)$pricing['effective_price'],
            'qty' => $qty,
            'line_total' => (float)$pricing['effective_price'] * $qty,
        ];
    }

    $pdo->beginTransaction();

    $insertOrder = $pdo->prepare(
        'INSERT INTO orders (order_code, consumer_id, farmer_id, status, payment_status, payment_method, total_amount, shipping_address)
         VALUES (:order_code, :consumer_id, :farmer_id, :status, :payment_status, :payment_method, :total_amount, :shipping_address)'
    );

    $insertItem = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name_snapshot, qty, unit_price, line_total)
         VALUES (:order_id, :product_id, :product_name_snapshot, :qty, :unit_price, :line_total)'
    );

    $updateStock = $pdo->prepare(
        'UPDATE products
         SET stock_qty = stock_qty - :qty
         WHERE id = :id'
    );

    $createdOrders = [];

    foreach ($byFarmer as $farmerId => $farmerItems) {
        $orderTotal = 0.0;
        foreach ($farmerItems as $line) {
            $orderTotal += (float)$line['line_total'];
        }

        $orderCode = generateOrderCode($pdo);
        $insertOrder->execute([
            ':order_code' => $orderCode,
            ':consumer_id' => $userId,
            ':farmer_id' => (int)$farmerId,
            ':status' => 'pending',
            ':payment_status' => 'pending',
            ':payment_method' => $paymentMethod,
            ':total_amount' => $orderTotal,
            ':shipping_address' => ($shippingAddress === '' ? null : mb_substr($shippingAddress, 0, 255)),
        ]);

        $orderId = (int)$pdo->lastInsertId();

        foreach ($farmerItems as $line) {
            $insertItem->execute([
                ':order_id' => $orderId,
                ':product_id' => (int)$line['id'],
                ':product_name_snapshot' => mb_substr((string)$line['name'], 0, 140),
                ':qty' => (float)$line['qty'],
                ':unit_price' => (float)$line['price'],
                ':line_total' => (float)$line['line_total'],
            ]);

            $updateStock->execute([
                ':qty' => (float)$line['qty'],
                ':id' => (int)$line['id'],
            ]);
        }

        $createdOrders[] = [
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'farmer_id' => (int)$farmerId,
            'total_amount' => $orderTotal,
        ];
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Order placed successfully',
        'orders' => $createdOrders,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
}

function generateOrderCode(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i += 1) {
        $candidate = 'ORD' . date('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
        $check = $pdo->prepare('SELECT id FROM orders WHERE order_code = :order_code LIMIT 1');
        $check->execute([':order_code' => $candidate]);
        if (!$check->fetch()) {
            return $candidate;
        }
    }

    return 'ORD' . date('ymdHis') . strtoupper(bin2hex(random_bytes(4)));
}
