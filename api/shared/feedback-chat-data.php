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

    if (!in_array($role, ['farmer', 'consumer'], true)) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'Only farmer or customer can access chat',
        ]);
        exit;
    }

    ensureMessagesEditedAtColumn($pdo);

    $rawBody = file_get_contents('php://input');
    $jsonInput = json_decode((string)$rawBody, true);
    $input = is_array($jsonInput) ? $jsonInput : [];

    $action = strtolower(trim((string)($input['action'] ?? $_GET['action'] ?? 'list_threads')));

    if ($action === 'list_threads') {
        echo json_encode([
            'ok' => true,
            'role' => $role,
            'threads' => listThreads($pdo, $userId, $role),
        ]);
        exit;
    }

    if ($action === 'get_or_create_thread') {
        $participantId = (int)($input['participant_id'] ?? $input['farmer_id'] ?? $input['consumer_id'] ?? 0);
        $threadId = getOrCreateThread($pdo, $userId, $role, $participantId);
        echo json_encode([
            'ok' => true,
            'thread_id' => $threadId,
        ]);
        exit;
    }

    if ($action === 'get_messages') {
        $threadId = (int)($input['thread_id'] ?? $_GET['thread_id'] ?? 0);
        if ($threadId <= 0) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid thread id',
            ]);
            exit;
        }

        $thread = getThreadIfAllowed($pdo, $threadId, $userId, $role);
        if (!$thread) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'Thread not found',
            ]);
            exit;
        }

        $otherUserId = ($role === 'farmer') ? (int)$thread['consumer_id'] : (int)$thread['farmer_id'];
        $participant = getParticipant($pdo, $otherUserId);

        $markReadStmt = $pdo->prepare(
            'UPDATE messages
             SET is_read = 1
             WHERE thread_id = :thread_id AND sender_id <> :viewer_id AND is_read = 0'
        );
        $markReadStmt->execute([
            ':thread_id' => $threadId,
            ':viewer_id' => $userId,
        ]);

        $msgStmt = $pdo->prepare(
            'SELECT id, sender_id, message_text, is_read, created_at, edited_at
             FROM messages
             WHERE thread_id = :thread_id
             ORDER BY created_at ASC, id ASC
             LIMIT 400'
        );
        $msgStmt->execute([':thread_id' => $threadId]);

        $messages = [];
        foreach (($msgStmt->fetchAll() ?: []) as $row) {
            $messages[] = [
                'id' => (int)($row['id'] ?? 0),
                'sender_id' => (int)($row['sender_id'] ?? 0),
                'text' => (string)($row['message_text'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'is_mine' => (int)($row['sender_id'] ?? 0) === $userId,
                'is_read' => (int)($row['is_read'] ?? 0) === 1,
                'is_edited' => trim((string)($row['edited_at'] ?? '')) !== '',
            ];
        }

        echo json_encode([
            'ok' => true,
            'thread_id' => $threadId,
            'participant' => $participant,
            'messages' => $messages,
        ]);
        exit;
    }

    if ($action === 'send_message') {
        $messageText = trim((string)($input['message'] ?? ''));
        $threadId = (int)($input['thread_id'] ?? 0);

        if ($messageText === '' || mb_strlen($messageText) > 1000) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Message must be between 1 and 1000 characters',
            ]);
            exit;
        }

        if ($threadId <= 0) {
            $participantId = (int)($input['participant_id'] ?? $input['farmer_id'] ?? $input['consumer_id'] ?? 0);
            if ($participantId <= 0) {
                http_response_code(400);
                echo json_encode([
                    'ok' => false,
                    'message' => 'Thread id or participant id is required',
                ]);
                exit;
            }

            $threadId = getOrCreateThread($pdo, $userId, $role, $participantId);
        }

        $thread = getThreadIfAllowed($pdo, $threadId, $userId, $role);
        if (!$thread) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'Thread not found',
            ]);
            exit;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO messages (thread_id, sender_id, message_text, is_read)
             VALUES (:thread_id, :sender_id, :message_text, 0)'
        );
        $insertStmt->execute([
            ':thread_id' => $threadId,
            ':sender_id' => $userId,
            ':message_text' => $messageText,
        ]);

        $touchStmt = $pdo->prepare(
            'UPDATE message_threads
             SET last_message_at = NOW()
             WHERE id = :thread_id
             LIMIT 1'
        );
        $touchStmt->execute([':thread_id' => $threadId]);

        echo json_encode([
            'ok' => true,
            'thread_id' => $threadId,
            'message' => [
                'id' => (int)$pdo->lastInsertId(),
                'sender_id' => $userId,
                'text' => $messageText,
                'created_at' => date('Y-m-d H:i:s'),
                'is_mine' => true,
                'is_edited' => false,
            ],
        ]);
        exit;
    }

    if ($action === 'edit_message') {
        $messageId = (int)($input['message_id'] ?? 0);
        $messageText = trim((string)($input['message'] ?? ''));

        if ($messageId <= 0) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid message id',
            ]);
            exit;
        }

        if ($messageText === '' || mb_strlen($messageText) > 1000) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Message must be between 1 and 1000 characters',
            ]);
            exit;
        }

        $message = getMessageIfAllowed($pdo, $messageId, $userId, $role);
        if (!$message) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'Message not found',
            ]);
            exit;
        }

        $updateStmt = $pdo->prepare(
            'UPDATE messages
             SET message_text = :message_text,
                 edited_at = NOW()
             WHERE id = :message_id
             LIMIT 1'
        );
        $updateStmt->execute([
            ':message_text' => $messageText,
            ':message_id' => $messageId,
        ]);

        echo json_encode([
            'ok' => true,
            'message' => 'Message updated successfully',
        ]);
        exit;
    }

    if ($action === 'delete_message') {
        $messageId = (int)($input['message_id'] ?? 0);

        if ($messageId <= 0) {
            http_response_code(400);
            echo json_encode([
                'ok' => false,
                'message' => 'Invalid message id',
            ]);
            exit;
        }

        $message = getMessageIfAllowed($pdo, $messageId, $userId, $role);
        if (!$message) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'Message not found',
            ]);
            exit;
        }

        $threadId = (int)($message['thread_id'] ?? 0);
        $deleteStmt = $pdo->prepare(
            'DELETE FROM messages
             WHERE id = :message_id
             LIMIT 1'
        );
        $deleteStmt->execute([':message_id' => $messageId]);

        refreshThreadLastMessageAt($pdo, $threadId);

        echo json_encode([
            'ok' => true,
            'message' => 'Message deleted successfully',
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid action',
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to process chat request',
    ]);
}

function listThreads(PDO $pdo, int $userId, string $role): array
{
    if ($role === 'farmer') {
        $sql =
            'SELECT
                t.id AS thread_id,
                u.id AS participant_id,
                u.full_name AS participant_name,
                u.profile_image AS participant_image,
                u.address_line,
                u.district,
                u.division,
                lm.message_text AS last_message,
                lm.created_at AS last_message_at,
                lm.sender_id AS last_sender_id,
                (
                    SELECT COUNT(*)
                    FROM messages um
                    WHERE um.thread_id = t.id AND um.sender_id <> :user_id_read_1 AND um.is_read = 0
                ) AS unread_count
             FROM message_threads t
             JOIN users u ON u.id = t.consumer_id
             LEFT JOIN messages lm ON lm.id = (
                SELECT m2.id
                FROM messages m2
                WHERE m2.thread_id = t.id
                ORDER BY m2.created_at DESC, m2.id DESC
                LIMIT 1
             )
             WHERE t.farmer_id = :user_id
             ORDER BY COALESCE(lm.created_at, t.last_message_at, t.updated_at) DESC
             LIMIT 250';
    } else {
        $sql =
            'SELECT
                t.id AS thread_id,
                u.id AS participant_id,
                u.full_name AS participant_name,
                u.profile_image AS participant_image,
                u.address_line,
                u.district,
                u.division,
                lm.message_text AS last_message,
                lm.created_at AS last_message_at,
                lm.sender_id AS last_sender_id,
                (
                    SELECT COUNT(*)
                    FROM messages um
                    WHERE um.thread_id = t.id AND um.sender_id <> :user_id_read_1 AND um.is_read = 0
                ) AS unread_count
             FROM message_threads t
             JOIN users u ON u.id = t.farmer_id
             LEFT JOIN messages lm ON lm.id = (
                SELECT m2.id
                FROM messages m2
                WHERE m2.thread_id = t.id
                ORDER BY m2.created_at DESC, m2.id DESC
                LIMIT 1
             )
             WHERE t.consumer_id = :user_id
             ORDER BY COALESCE(lm.created_at, t.last_message_at, t.updated_at) DESC
             LIMIT 250';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id' => $userId,
        ':user_id_read_1' => $userId,
    ]);

    $items = [];
    foreach (($stmt->fetchAll() ?: []) as $row) {
        $addressParts = array_values(array_filter([
            trim((string)($row['address_line'] ?? '')),
            trim((string)($row['district'] ?? '')),
            trim((string)($row['division'] ?? '')),
        ], static function ($value) {
            return $value !== '';
        }));

        $items[] = [
            'thread_id' => (int)($row['thread_id'] ?? 0),
            'participant_id' => (int)($row['participant_id'] ?? 0),
            'participant_name' => (string)($row['participant_name'] ?? 'User'),
            'participant_image' => toPublicAssetPath((string)($row['participant_image'] ?? '/figma/images (2).jpg')),
            'participant_location' => $addressParts ? implode(', ', $addressParts) : 'Location not set',
            'last_message' => (string)($row['last_message'] ?? ''),
            'last_message_at' => (string)($row['last_message_at'] ?? ''),
            'last_sender_id' => (int)($row['last_sender_id'] ?? 0),
            'unread_count' => (int)($row['unread_count'] ?? 0),
        ];
    }

    return $items;
}

function getOrCreateThread(PDO $pdo, int $userId, string $role, int $participantId): int
{
    if ($participantId <= 0 || $participantId === $userId) {
        throw new RuntimeException('Invalid participant');
    }

    $participantRole = ($role === 'farmer') ? 'consumer' : 'farmer';
    $participantStmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE id = :id AND is_active = 1 AND LOWER(role) IN (:r1, :r2)
         LIMIT 1'
    );
    // MySQL does not support binding array in IN with named params, so split r1/r2.
    $roleAlt = ($participantRole === 'consumer') ? 'customer' : $participantRole;
    $participantStmt->execute([
        ':id' => $participantId,
        ':r1' => $participantRole,
        ':r2' => $roleAlt,
    ]);
    if (!$participantStmt->fetch()) {
        throw new RuntimeException('Participant not found');
    }

    $farmerId = ($role === 'farmer') ? $userId : $participantId;
    $consumerId = ($role === 'consumer') ? $userId : $participantId;

    $findStmt = $pdo->prepare(
        'SELECT id
         FROM message_threads
         WHERE farmer_id = :farmer_id AND consumer_id = :consumer_id
         LIMIT 1'
    );
    $findStmt->execute([
        ':farmer_id' => $farmerId,
        ':consumer_id' => $consumerId,
    ]);

    $found = $findStmt->fetch();
    if ($found && (int)($found['id'] ?? 0) > 0) {
        return (int)$found['id'];
    }

    try {
        $createStmt = $pdo->prepare(
            'INSERT INTO message_threads (farmer_id, consumer_id, last_message_at)
             VALUES (:farmer_id, :consumer_id, NOW())'
        );
        $createStmt->execute([
            ':farmer_id' => $farmerId,
            ':consumer_id' => $consumerId,
        ]);

        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $retryStmt = $pdo->prepare(
            'SELECT id
             FROM message_threads
             WHERE farmer_id = :farmer_id AND consumer_id = :consumer_id
             LIMIT 1'
        );
        $retryStmt->execute([
            ':farmer_id' => $farmerId,
            ':consumer_id' => $consumerId,
        ]);

        $row = $retryStmt->fetch();
        if ($row && (int)($row['id'] ?? 0) > 0) {
            return (int)$row['id'];
        }

        throw $e;
    }
}

function getThreadIfAllowed(PDO $pdo, int $threadId, int $userId, string $role): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, farmer_id, consumer_id
         FROM message_threads
         WHERE id = :thread_id
         LIMIT 1'
    );
    $stmt->execute([':thread_id' => $threadId]);
    $thread = $stmt->fetch();

    if (!$thread) {
        return null;
    }

    if ($role === 'farmer' && (int)$thread['farmer_id'] !== $userId) {
        return null;
    }

    if ($role === 'consumer' && (int)$thread['consumer_id'] !== $userId) {
        return null;
    }

    return $thread;
}

function getParticipant(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT full_name, profile_image, address_line, district, division
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch() ?: [];

    $parts = array_values(array_filter([
        trim((string)($row['address_line'] ?? '')),
        trim((string)($row['district'] ?? '')),
        trim((string)($row['division'] ?? '')),
    ], static function ($value) {
        return $value !== '';
    }));

    return [
        'id' => $userId,
        'name' => (string)($row['full_name'] ?? 'User'),
        'image' => toPublicAssetPath((string)($row['profile_image'] ?? '/figma/images (2).jpg')),
        'location' => $parts ? implode(', ', $parts) : 'Location not set',
    ];
}

function getMessageIfAllowed(PDO $pdo, int $messageId, int $userId, string $role): ?array
{
    $stmt = $pdo->prepare(
        'SELECT m.id, m.thread_id, m.sender_id, m.message_text, t.farmer_id, t.consumer_id
         FROM messages m
         JOIN message_threads t ON t.id = m.thread_id
         WHERE m.id = :message_id
         LIMIT 1'
    );
    $stmt->execute([':message_id' => $messageId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if ((int)($row['sender_id'] ?? 0) !== $userId) {
        return null;
    }

    if ($role === 'farmer' && (int)($row['farmer_id'] ?? 0) !== $userId) {
        return null;
    }

    if ($role === 'consumer' && (int)($row['consumer_id'] ?? 0) !== $userId) {
        return null;
    }

    return $row;
}

function refreshThreadLastMessageAt(PDO $pdo, int $threadId): void
{
    if ($threadId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT created_at
         FROM messages
         WHERE thread_id = :thread_id
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([':thread_id' => $threadId]);
    $row = $stmt->fetch();

    $updateStmt = $pdo->prepare(
        'UPDATE message_threads
         SET last_message_at = :last_message_at
         WHERE id = :thread_id
         LIMIT 1'
    );
    $updateStmt->execute([
        ':last_message_at' => $row ? (string)($row['created_at'] ?? null) : null,
        ':thread_id' => $threadId,
    ]);
}

function ensureMessagesEditedAtColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $checkStmt = $pdo->query(
        'SELECT COUNT(*) AS c
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = "messages"
           AND column_name = "edited_at"'
    );
    $exists = (int)(($checkStmt->fetch()['c'] ?? 0));
    if ($exists > 0) {
        return;
    }

    $pdo->exec('ALTER TABLE messages ADD COLUMN edited_at DATETIME NULL AFTER created_at');
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
