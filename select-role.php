<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: pages/auth/Login.html?error=Please+login+to+select+your+role');
    exit;
}

$roleInput = strtolower(trim((string) ($_GET['role'] ?? $_POST['role'] ?? '')));
$roleAliasMap = [
    'farmer' => 'farmer',
    'consumer' => 'consumer',
    'customer' => 'consumer',
];

$role = $roleAliasMap[$roleInput] ?? '';
if (!in_array($role, ['farmer', 'consumer'], true)) {
    header('Location: pages/auth/role-selection.html?error=Invalid+role+selection');
    exit;
}

try {
    $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id LIMIT 1');
    $stmt->execute([
        'role' => $role,
        'id' => $userId,
    ]);

    $_SESSION['user_role'] = $role;

    if ($role === 'farmer') {
        header('Location: pages/farmer/farmer-dashboard.html');
        exit;
    }

    header('Location: pages/customer/customer-marketplace.html');
    exit;
} catch (Throwable $e) {
    header('Location: pages/auth/role-selection.html?error=Could+not+save+role.+Please+try+again');
    exit;
}
