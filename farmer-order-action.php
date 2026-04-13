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
$orderId = (int)($input['order_id'] ?? 0);
$action = strtolower(trim((string)($input['action'] ?? '')));

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid order id',
    ]);
    exit;
}

if (!in_array($action, ['confirm', 'decline', 'complete', 'refund'], true)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid action',
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
        'SELECT id, status
         FROM orders
         WHERE id = :order_id AND farmer_id = :farmer_id
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

    $statusRaw = strtolower((string)($order['status'] ?? 'pending'));

    $transitionMap = [
        'confirm' => [
            'from' => ['pending'],
            'to' => 'to_receive',
            'message' => 'Order confirmed successfully',
        ],
        'decline' => [
            'from' => ['pending'],
            'to' => 'cancelled',
            'message' => 'Order declined successfully',
        ],
        'complete' => [
            'from' => ['to_receive'],
            'to' => 'completed',
            'message' => 'Order completed successfully',
        ],
        'refund' => [
            'from' => ['to_receive', 'completed'],
            'to' => 'refund_return',
            'message' => 'Order marked as refund/return',
        ],
    ];

    $transition = $transitionMap[$action] ?? null;
    if (!$transition) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Invalid action',
        ]);
        exit;
    }

    if (!in_array($statusRaw, $transition['from'], true)) {
        echo json_encode([
            'ok' => false,
            'message' => 'Action not allowed for current status',
            'status' => $statusRaw,
        ]);
        exit;
    }

    $nextStatus = (string)$transition['to'];
    $successMessage = (string)$transition['message'];

    $updateStmt = $pdo->prepare(
        'UPDATE orders
         SET status = :status
         WHERE id = :order_id AND farmer_id = :farmer_id AND status = :current_status
         LIMIT 1'
    );
    $updateStmt->execute([
        ':status' => $nextStatus,
        ':order_id' => $orderId,
        ':farmer_id' => $userId,
        ':current_status' => $statusRaw,
    ]);

    if ($updateStmt->rowCount() < 1) {
        echo json_encode([
            'ok' => false,
            'message' => 'Order status changed, please refresh and try again',
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => $successMessage,
        'status' => $nextStatus,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to update order status',
    ]);
}
