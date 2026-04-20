<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

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
            'message' => 'Only customers can access this',
        ]);
        exit;
    }

    if (!isset($_FILES['profile_image'])) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'No image selected',
        ]);
        exit;
    }

    $file = $_FILES['profile_image'];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Image upload failed',
        ]);
        exit;
    }

    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Image must be less than 2MB',
        ]);
        exit;
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    $mimeType = '';

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mimeType = (string)finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
        }
    }

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedMimeTypes[$mimeType])) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Only JPG, PNG, and WEBP images are allowed',
        ]);
        exit;
    }

    $uploadDir = __DIR__ . '/../../image/uploads/profiles';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Could not prepare upload directory');
    }

    $fileName = 'user_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowedMimeTypes[$mimeType];
    $destination = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $destination)) {
        throw new RuntimeException('Could not save uploaded image');
    }

    $imagePathForDb = 'image/uploads/profiles/' . $fileName;

    $updateStmt = $pdo->prepare('UPDATE users SET profile_image = :profile_image WHERE id = :id LIMIT 1');
    $updateStmt->execute([
        ':profile_image' => $imagePathForDb,
        ':id' => $userId,
    ]);

    echo json_encode([
        'ok' => true,
        'message' => 'Profile image updated successfully',
        'profile_image' => toPublicAssetPath($imagePathForDb),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to update image now. Please try again',
    ]);
}

function toPublicAssetPath(string $rawPath): string
{
    $path = trim(str_replace('\\', '/', $rawPath));
    if ($path === '') {
        $path = '/figma/images (2).jpg';
    }

    if (!preg_match('#^(https?:)?//#i', $path) && strpos($path, 'data:') !== 0) {
        if ($path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        $basePath = trim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''), 3)), '/');
        if ($basePath !== '') {
            $prefix = '/' . $basePath;
            if (strpos($path, $prefix . '/') !== 0 && $path !== $prefix) {
                $path = $prefix . $path;
            }
        }
    }

    return $path;
}
