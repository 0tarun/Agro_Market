<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

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
    $userStmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch();

    if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
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
            'message' => 'Only farmers can access support',
        ]);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $action = strtolower(trim((string)($input['action'] ?? 'list_tickets')));

    if ($action === 'create_ticket') {
        $messageText = trim((string)($input['message_text'] ?? ''));
        if (mb_strlen($messageText) < 8 || mb_strlen($messageText) > 1200) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Message must be between 8 and 1200 characters',
            ]);
            exit;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO support_tickets (farmer_id, message_text, status)
             VALUES (:farmer_id, :message_text, "open")'
        );
        $insertStmt->execute([
            ':farmer_id' => $userId,
            ':message_text' => $messageText,
        ]);

        echo json_encode([
            'ok' => true,
            'message' => 'Support ticket submitted successfully',
            'tickets' => listSupportTickets($pdo, $userId),
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'tickets' => listSupportTickets($pdo, $userId),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to load support data',
    ]);
}

function listSupportTickets(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, message_text, status, created_at, updated_at
         FROM support_tickets
         WHERE farmer_id = :farmer_id
         ORDER BY created_at DESC, id DESC
         LIMIT 20'
    );
    $stmt->execute([':farmer_id' => $userId]);

    $tickets = [];
    foreach (($stmt->fetchAll() ?: []) as $row) {
        $statusRaw = strtolower((string)($row['status'] ?? 'open'));
        $tickets[] = [
            'id' => (int)($row['id'] ?? 0),
            'message_text' => (string)($row['message_text'] ?? ''),
            'status' => $statusRaw,
            'status_label' => ucwords(str_replace('_', ' ', $statusRaw)),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    return $tickets;
}
