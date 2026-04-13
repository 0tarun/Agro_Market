<?php
declare(strict_types=1);
require __DIR__ . '/config/database.php';

$userId = 6;
$sql = 'SELECT
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
$stmt = $pdo->prepare($sql);
$stmt->execute([':user_id' => $userId, ':user_id_read_1' => $userId]);
$rows = $stmt->fetchAll();
foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
