<?php

declare(strict_types=1);
require_once __DIR__ . '/../../config/database.php';

// Sample categories to add
$categories = [
    'Vegetables',
    'Fruits',
    'Grains',
    'Dairy',
    'Meat & Fish',
    'Spices',
    'Oils & Ghee',
    'Honey & Jams',
    'Seeds',
    'Flowers',
    'Fertilizers',
    'Tools & Equipment'
];

try {
    echo '<h2>Adding Categories to Database</h2>';

    $added = 0;
    $skipped = 0;

    foreach ($categories as $category) {
        // Check if category already exists
        $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $category]);

        if ($stmt->fetch()) {
            echo '<p style="color: orange;">⚠ "' . htmlspecialchars($category) . '" already exists</p>';
            $skipped++;
        } else {
            // Insert new category
            $stmt = $pdo->prepare('INSERT INTO categories (name) VALUES (:name)');
            $stmt->execute([':name' => $category]);
            echo '<p style="color: green;">✓ Added "' . htmlspecialchars($category) . '"</p>';
            $added++;
        }
    }

    echo '<hr>';
    echo '<p><strong>Summary:</strong></p>';
    echo '<p>✓ Added: ' . $added . ' categories</p>';
    echo '<p>⚠ Skipped: ' . $skipped . ' categories (already exist)</p>';

    // Show all categories
    $stmt = $pdo->prepare('SELECT id, name FROM categories ORDER BY name ASC');
    $stmt->execute();
    $allCategories = $stmt->fetchAll();

    echo '<h3>All Categories in Database:</h3>';
    echo '<ul>';
    foreach ($allCategories as $cat) {
        echo '<li>' . htmlspecialchars($cat['name']) . ' (ID: ' . $cat['id'] . ')</li>';
    }
    echo '</ul>';

    echo '<p><a href="farmer-products.php">✓ Go to Products Page</a></p>';
} catch (Throwable $e) {
    echo '<p style="color: red;"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
}
