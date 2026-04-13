<?php
declare(strict_types=1);
require __DIR__ . '/config/database.php';

$threads = $pdo->query('SELECT id, farmer_id, consumer_id, last_message_at, created_at, updated_at FROM message_threads ORDER BY id DESC LIMIT 10')->fetchAll();
$messages = $pdo->query('SELECT id, thread_id, sender_id, message_text, is_read, created_at FROM messages ORDER BY id DESC LIMIT 20')->fetchAll();

echo "THREADS\n";
foreach ($threads as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

echo "MESSAGES\n";
foreach ($messages as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
