<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

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
    $stmt = $pdo->prepare(
        'SELECT id, role, full_name, email, phone, profile_image, division, district, address_line, is_active
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

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

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
        $action = strtolower(trim((string)($input['action'] ?? '')));

        if ($action === 'update_profile') {
            $fullName = trim((string)($input['full_name'] ?? ''));
            $phone = trim((string)($input['phone'] ?? ''));
            $addressLineInput = trim((string)($input['address_line'] ?? ''));
            $districtInput = trim((string)($input['district'] ?? ''));
            $divisionInput = trim((string)($input['division'] ?? ''));

            if ($fullName === '') {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Full name is required',
                ]);
                exit;
            }

            if (mb_strlen($fullName) > 120) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Full name is too long',
                ]);
                exit;
            }

            if ($phone !== '' && mb_strlen($phone) > 25) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Phone is too long',
                ]);
                exit;
            }

            $update = $pdo->prepare(
                'UPDATE users
                 SET full_name = :full_name,
                     phone = :phone,
                     address_line = :address_line,
                     district = :district,
                     division = :division
                 WHERE id = :id
                 LIMIT 1'
            );

            $update->execute([
                ':full_name' => $fullName,
                ':phone' => ($phone === '' ? null : $phone),
                ':address_line' => ($addressLineInput === '' ? null : $addressLineInput),
                ':district' => ($districtInput === '' ? null : $districtInput),
                ':division' => ($divisionInput === '' ? null : $divisionInput),
                ':id' => $userId,
            ]);

            echo json_encode([
                'ok' => true,
                'message' => 'Profile updated successfully',
            ]);
            exit;
        }
    }

    $addressLine = trim((string)($user['address_line'] ?? ''));
    $district = trim((string)($user['district'] ?? ''));
    $division = trim((string)($user['division'] ?? ''));

    $parts = array_values(array_filter([$addressLine, $district, $division], static function ($value) {
        return trim((string)$value) !== '';
    }));

    echo json_encode([
        'ok' => true,
        'full_name' => (string)($user['full_name'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'phone' => (string)($user['phone'] ?? ''),
        'profile_image' => toPublicAssetPath((string)($user['profile_image'] ?? '/figma/images (2).jpg')),
        'address_line' => $addressLine,
        'district' => $district,
        'division' => $division,
        'address' => $parts ? implode(', ', $parts) : 'Location not set',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load checkout data',
    ]);
}

function toPublicAssetPath(string $rawPath): string
{
    $path = trim(str_replace('\\\\', '/', $rawPath));
    if ($path === '') {
        $path = '/figma/images (2).jpg';
    }

    if (!preg_match('#^(https?:)?//#i', $path) && strpos($path, 'data:') !== 0) {
        if ($path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        $basePath = trim(str_replace('\\\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($basePath !== '') {
            $prefix = '/' . $basePath;
            if (strpos($path, $prefix . '/') !== 0 && $path !== $prefix) {
                $path = $prefix . $path;
            }
        }
    }

    return $path;
}
