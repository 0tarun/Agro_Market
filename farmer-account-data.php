<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode([
        'ok' => false,
        'redirect' => 'pages/auth/Login.html?error=' . urlencode('Please login first'),
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT id, role, full_name, email, phone, profile_image, division, district, address_line, is_active
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        echo json_encode([
            'ok' => false,
            'redirect' => 'logout.php',
        ]);
        exit;
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if ($role === 'customer') {
        $role = 'consumer';
    }

    if ($role !== 'farmer') {
        echo json_encode([
            'ok' => false,
            'redirect' => 'pages/farmer/customer-details.html',
        ]);
        exit;
    }

    $fullName = trim((string) ($user['full_name'] ?? ''));
    $nameParts = preg_split('/\s+/', $fullName, 2) ?: [];
    $firstName = (string) ($nameParts[0] ?? '');
    $lastName = (string) ($nameParts[1] ?? '');

    $basePath = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $basePath = ($basePath === '' ? '' : '/' . $basePath);

    $profileImage = trim((string) ($user['profile_image'] ?? ''));
    $profileImage = str_replace('\\', '/', $profileImage);

    if ($profileImage === '') {
        $profileImage = '/figma/images (5).jpg';
    }

    if (!preg_match('#^(https?:)?//#i', $profileImage) && strpos($profileImage, 'data:') !== 0) {
        if (strpos($profileImage, '/') !== 0) {
            $profileImage = '/' . ltrim($profileImage, '/');
        }

        // If app runs under a subfolder (e.g. /Salman_Web_Project), keep paths under it.
        if ($basePath !== '' && strpos($profileImage, $basePath . '/') !== 0 && $profileImage !== $basePath) {
            $profileImage = $basePath . $profileImage;
        }
    }

    echo json_encode([
        'ok' => true,
        'full_name' => $fullName,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => (string) ($user['email'] ?? ''),
        'phone' => (string) ($user['phone'] ?? ''),
        'division' => (string) ($user['division'] ?? ''),
        'district' => (string) ($user['district'] ?? ''),
        'address_line' => (string) ($user['address_line'] ?? ''),
        'profile_image' => $profileImage,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load account data',
    ]);
}
