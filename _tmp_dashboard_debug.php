<?php
require __DIR__ . '/config/database.php';

function runQuery(PDO $pdo, string $name, string $sql, array $params = []): void {
    echo "=== {$name} ===\n";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "ok rows=" . count($rows) . "\n";
        if (!empty($rows)) {
            echo json_encode($rows[0], JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (Throwable $e) {
        echo "error: " . $e->getMessage() . "\n";
    }
}

$farmerId = 0;
try {
    $stmt = $pdo->query("SELECT id FROM users WHERE role='farmer' AND is_active=1 ORDER BY id ASC LIMIT 1");
    $farmerId = (int)($stmt->fetchColumn() ?: 0);
} catch (Throwable $e) {
    echo "farmer lookup error: " . $e->getMessage() . "\n";
}

echo "farmerId={$farmerId}\n";
if ($farmerId <= 0) {
    exit(0);
}

runQuery($pdo, 'stats', 'SELECT COUNT(DISTINCT o.consumer_id) AS total_customers, COALESCE(SUM(CASE WHEN o.status = "completed" THEN oi.qty ELSE 0 END), 0) AS sold_items, COUNT(DISTINCT o.id) AS total_orders, COALESCE(SUM(CASE WHEN o.status = "completed" THEN o.total_amount ELSE 0 END), 0) AS total_revenue FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id WHERE o.farmer_id = :farmer_id', [':farmer_id' => $farmerId]);

runQuery($pdo, 'unread', 'SELECT COUNT(*) AS unread_messages FROM messages m INNER JOIN message_threads t ON t.id = m.thread_id WHERE t.farmer_id = :farmer_id AND m.sender_id <> :farmer_id AND m.is_read = 0', [':farmer_id' => $farmerId]);

runQuery($pdo, 'latest message', 'SELECT t.id AS thread_id, u.full_name AS consumer_name, u.profile_image AS consumer_image, m.message_text, m.created_at, m.is_read FROM message_threads t INNER JOIN users u ON u.id = t.consumer_id LEFT JOIN messages m ON m.id = ( SELECT m2.id FROM messages m2 WHERE m2.thread_id = t.id ORDER BY m2.created_at DESC, m2.id DESC LIMIT 1 ) WHERE t.farmer_id = :farmer_id ORDER BY COALESCE(m.created_at, t.last_message_at, t.updated_at) DESC LIMIT 1', [':farmer_id' => $farmerId]);

runQuery($pdo, 'top products', 'SELECT oi.product_name_snapshot AS product_name, COALESCE(SUM(oi.qty), 0) AS total_qty, COALESCE(MAX(p.image_path), "/figma/images (2).jpg") AS image_path FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id LEFT JOIN products p ON p.id = oi.product_id WHERE o.farmer_id = :farmer_id AND o.status IN ("completed", "to_receive") GROUP BY oi.product_name_snapshot ORDER BY total_qty DESC LIMIT 6', [':farmer_id' => $farmerId]);

runQuery($pdo, 'latest orders', 'SELECT o.id AS order_id, o.order_code, o.status, o.payment_status, o.total_amount, o.placed_at, COALESCE(oi_first.product_name_snapshot, "Product") AS product_name FROM orders o LEFT JOIN order_items oi_first ON oi_first.id = ( SELECT oi2.id FROM order_items oi2 WHERE oi2.order_id = o.id ORDER BY oi2.id ASC LIMIT 1 ) WHERE o.farmer_id = :farmer_id ORDER BY o.placed_at DESC, o.id DESC LIMIT 8', [':farmer_id' => $farmerId]);
