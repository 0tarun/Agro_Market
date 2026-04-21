<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode([
        'ok' => true,
        'is_authenticated' => false,
        'role' => null,
        'is_customer' => false,
        'is_farmer' => false,
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
        echo json_encode([
            'ok' => true,
            'is_authenticated' => false,
            'role' => null,
            'is_customer' => false,
            'is_farmer' => false,
        ]);
        exit;
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));
    if ($role === 'customer') {
        $role = 'consumer';
    }

    echo json_encode([
        'ok' => true,
        'is_authenticated' => true,
        'role' => $role,
        'is_customer' => $role === 'consumer',
        'is_farmer' => $role === 'farmer',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to check session',
    ]);
}
