<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

function redirectWithMessage(string $type, string $message): void
{
    header('Location: farmer-account.php?' . $type . '=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage('error', 'Invalid request method');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: pages/auth/Login.html?error=' . urlencode('Please login first'));
    exit;
}

$firstName = trim((string) ($_POST['first_name'] ?? ''));
$lastName = trim((string) ($_POST['last_name'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$division = trim((string) ($_POST['division'] ?? ''));
$district = trim((string) ($_POST['district'] ?? ''));
$addressLine = trim((string) ($_POST['address_line'] ?? ''));

if ($firstName === '' || $lastName === '') {
    redirectWithMessage('error', 'First name and last name are required');
}

$firstName = substr($firstName, 0, 60);
$lastName = substr($lastName, 0, 60);
$phone = substr($phone, 0, 25);
$division = substr($division, 0, 80);
$district = substr($district, 0, 80);
$addressLine = substr($addressLine, 0, 255);
$fullName = trim($firstName . ' ' . $lastName);

try {
    $schemaStmt = $pdo->query('SHOW COLUMNS FROM users');
    $tableColumns = [];
    foreach ($schemaStmt->fetchAll() as $columnInfo) {
        $field = (string) ($columnInfo['Field'] ?? '');
        if ($field !== '') {
            $tableColumns[$field] = true;
        }
    }

    $checkStmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
    $checkStmt->execute(['id' => $userId]);
    $user = $checkStmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        header('Location: logout.php');
        exit;
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if ($role === 'customer') {
        $role = 'consumer';
    }

    if ($role !== 'farmer') {
        header('Location: pages/farmer/customer-details.html');
        exit;
    }

    $imagePathForDb = null;
    if (isset($_FILES['profile_image']) && (int) $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['profile_image'];

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            redirectWithMessage('error', 'Image upload failed');
        }

        if ((int) $file['size'] > 2 * 1024 * 1024) {
            redirectWithMessage('error', 'Image must be less than 2MB');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $mimeType = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mimeType = (string) finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
            }
        }

        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedMimeTypes[$mimeType])) {
            redirectWithMessage('error', 'Only JPG, PNG, and WEBP images are allowed');
        }

        $uploadDir = __DIR__ . '/image/uploads/profiles';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            redirectWithMessage('error', 'Could not prepare upload directory');
        }

        $fileName = 'user_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowedMimeTypes[$mimeType];
        $destination = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($tmpPath, $destination)) {
            redirectWithMessage('error', 'Could not save uploaded image');
        }

        $imagePathForDb = '/image/uploads/profiles/' . $fileName;
    }

    $setClauses = [];
    $params = ['id' => $userId];

    if (isset($tableColumns['full_name'])) {
        $setClauses[] = 'full_name = :full_name';
        $params['full_name'] = $fullName;
    }
    if (isset($tableColumns['phone'])) {
        $setClauses[] = 'phone = :phone';
        $params['phone'] = ($phone === '' ? null : $phone);
    }
    if (isset($tableColumns['division'])) {
        $setClauses[] = 'division = :division';
        $params['division'] = ($division === '' ? null : $division);
    }
    if (isset($tableColumns['district'])) {
        $setClauses[] = 'district = :district';
        $params['district'] = ($district === '' ? null : $district);
    }
    if (isset($tableColumns['address_line'])) {
        $setClauses[] = 'address_line = :address_line';
        $params['address_line'] = ($addressLine === '' ? null : $addressLine);
    }
    if ($imagePathForDb !== null && isset($tableColumns['profile_image'])) {
        $setClauses[] = 'profile_image = :profile_image';
        $params['profile_image'] = $imagePathForDb;
    }

    if (!empty($setClauses)) {
        $updateSql = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = :id LIMIT 1';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($params);
    }

    $_SESSION['user_name'] = $fullName;

    redirectWithMessage('success', 'Account updated successfully');
} catch (Throwable $e) {
    redirectWithMessage('error', 'Unable to update account now. Please try again');
}
