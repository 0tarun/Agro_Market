<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

$userId = (int) ($_SESSION['user_id'] ?? 0);

echo '<h2>Debug Information</h2>';
echo '<p><strong>User ID:</strong> ' . $userId . '</p>';

if ($userId <= 0) {
    echo '<p style="color:red;"><strong>Not logged in!</strong> Please login first.</p>';
    exit;
}

try {
    // Check user
    $stmt = $pdo->prepare('SELECT role, is_active, full_name FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    
    echo '<p><strong>User:</strong> ' . htmlspecialchars($user['full_name'] ?? 'Unknown') . '</p>';
    echo '<p><strong>Role:</strong> ' . htmlspecialchars($user['role'] ?? 'Unknown') . '</p>';
    echo '<p><strong>Active:</strong> ' . ($user['is_active'] ? 'Yes' : 'No') . '</p>';
    
    // Check categories
    $stmt = $pdo->prepare('SELECT id, name FROM categories');
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
    echo '<p><strong>Categories:</strong> ' . count($categories) . '</p>';
    echo '<ul>';
    foreach ($categories as $cat) {
        echo '<li>' . htmlspecialchars($cat['id'] . ': ' . $cat['name']) . '</li>';
    }
    echo '</ul>';
    
    // Check products
    $stmt = $pdo->prepare('SELECT id, name, farmer_id FROM products WHERE farmer_id = :farmer_id LIMIT 10');
    $stmt->execute(['farmer_id' => $userId]);
    $products = $stmt->fetchAll();
    
    echo '<p><strong>Your Products:</strong> ' . count($products) . '</p>';
    echo '<ul>';
    foreach ($products as $prod) {
        echo '<li>ID ' . $prod['id'] . ': ' . htmlspecialchars($prod['name']) . '</li>';
    }
    echo '</ul>';
    
    echo '<p style="color:green;"><strong>✓ Database connection OK</strong></p>';
    echo '<p><a href="farmer-products.php">Back to Products</a></p>';
    
} catch (Throwable $e) {
    echo '<p style="color:red;"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
}
