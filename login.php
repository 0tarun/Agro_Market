<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pages/auth/Login.html');
    exit;
}

$email = trim((string) ($_POST['email'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    header('Location: pages/auth/Login.html?error=Email+and+password+are+required');
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT id, role, full_name, email, password_hash, is_active FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        header('Location: pages/auth/Login.html?error=Invalid+email+or+password');
        exit;
    }

    if ((int) $user['is_active'] !== 1) {
        header('Location: pages/auth/Login.html?error=Your+account+is+disabled');
        exit;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['full_name'];
    $_SESSION['user_email'] = (string) $user['email'];
    $role = strtolower(trim((string) ($user['role'] ?? '')));

    if ($role === 'customer') {
        $role = 'consumer';
    }

    $_SESSION['user_role'] = $role;

    if ($role === 'farmer') {
        header('Location: pages/farmer/farmer-dashboard.html');
        exit;
    }

    if ($role === 'consumer') {
        header('Location: pages/customer/customer-marketplace.html');
        exit;
    }

    // Unknown role fallback: send users to role picker instead of landing page.
    header('Location: pages/auth/role-selection.html?error=Please+select+your+role');
    exit;
} catch (Throwable $e) {
    header('Location: pages/auth/Login.html?error=Login+failed.+Please+try+again');
    exit;
}
