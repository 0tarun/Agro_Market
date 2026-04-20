<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../config/database.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ../../pages/auth/Login.html?error=' . urlencode('Please login first'));
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        header('Location: ../auth/logout.php');
        exit;
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if ($role === 'customer') {
        $role = 'consumer';
    }

    if ($role !== 'farmer') {
        header('Location: ../../pages/farmer/customer-details.html');
        exit;
    }

    require __DIR__ . '/farmer-account.html';
} catch (Throwable $e) {
    header('Location: ../../pages/farmer/farmer-dashboard.html?error=' . urlencode('Unable to open account page'));
    exit;
}
