<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../pages/auth/Registration.html');
    exit;
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');
$roleInput = strtolower(trim((string) ($_POST['role'] ?? 'consumer')));

// Keep one canonical role value in DB while accepting common aliases from forms.
$roleAliasMap = [
    'farmer' => 'farmer',
    'consumer' => 'consumer',
    'customer' => 'consumer',
    'admin' => 'admin',
];

$role = $roleAliasMap[$roleInput] ?? 'consumer';

if (!in_array($role, ['farmer', 'consumer', 'admin'], true)) {
    $role = 'consumer';
}

if ($fullName === '' || $email === '' || $password === '' || $confirmPassword === '') {
    header('Location: ../../pages/auth/Registration.html?error=Please+fill+all+required+fields');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: ../../pages/auth/Registration.html?error=Invalid+email+address');
    exit;
}

if ($password !== $confirmPassword) {
    header('Location: ../../pages/auth/Registration.html?error=Passwords+do+not+match');
    exit;
}

if (strlen($password) < 6) {
    header('Location: ../../pages/auth/Registration.html?error=Password+must+be+at+least+6+characters');
    exit;
}

try {
    $checkStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $checkStmt->execute(['email' => $email]);

    if ($checkStmt->fetch()) {
        header('Location: ../../pages/auth/Registration.html?error=Email+already+registered');
        exit;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insertStmt = $pdo->prepare(
        'INSERT INTO users (role, full_name, email, password_hash, phone) VALUES (:role, :full_name, :email, :password_hash, :phone)'
    );

    $insertStmt->execute([
        'role' => $role,
        'full_name' => $fullName,
        'email' => $email,
        'password_hash' => $passwordHash,
        'phone' => ($phone === '' ? null : $phone),
    ]);

    $_SESSION['user_id'] = (int) $pdo->lastInsertId();
    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = $role;

    if ($role === 'admin') {
        header('Location: ../../pages/customer/customer-marketplace.html');
        exit;
    }

    // New users must explicitly choose Farmer or Consumer after registration.
    header('Location: ../../pages/auth/role-selection.html');
    exit;
} catch (Throwable $e) {
    header('Location: ../../pages/auth/Registration.html?error=Registration+failed.+Please+try+again');
    exit;
}
