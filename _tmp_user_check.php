<?php
declare(strict_types=1);
require __DIR__ . '/config/database.php';

$rows = $pdo->query('SELECT id, role, full_name, email FROM users ORDER BY id ASC')->fetchAll();
foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
