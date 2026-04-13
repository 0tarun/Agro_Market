<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Please login first'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'User not found or inactive']);
        exit;
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));
    if ($role === 'customer') {
        $role = 'consumer';
    }

    if ($role !== 'consumer') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only customers can access this']);
        exit;
    }

    $rawInput = json_decode((string)file_get_contents('php://input'), true) ?? [];
    $action = strtolower(trim((string)($rawInput['action'] ?? 'list_products')));

    if ($action === 'product_detail') {
        $productId = (int)($rawInput['product_id'] ?? 0);
        handleProductDetail($pdo, $productId);
        exit;
    }

    $category = trim((string)($rawInput['category'] ?? 'All'));

        $baseProductsSql =
                'SELECT p.id, p.name, p.price, p.stock_qty, p.harvest_date, p.created_at, p.image_path, p.farmer_id, c.id AS category_id, c.name AS category_name, u.full_name AS farmer_name
         FROM products p
         JOIN users u ON u.id = p.farmer_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.is_active = 1
           AND p.stock_qty > 0
           AND u.is_active = 1
           AND LOWER(u.role) = :farmer_role';

        $baseParams = [':farmer_role' => 'farmer'];
        $productsSql = $baseProductsSql;
        $params = $baseParams;

    if ($category !== '' && strcasecmp($category, 'All') !== 0) {
        $productsSql .= ' AND c.name = :category_name';
        $params[':category_name'] = $category;
    }

    $productsSql .= ' ORDER BY p.created_at DESC LIMIT 120';

    $stmt = $pdo->prepare($productsSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $products = [];
    foreach ($rows as $row) {
        $pricing = calculatePerishableProductPricing($row);
        if ($pricing['is_expired']) {
            continue;
        }

        $products[] = [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'price' => $pricing['effective_price'],
            'base_price' => $pricing['base_price'],
            'pricing_status' => (string)$pricing['pricing_status'],
            'pricing_label' => (string)$pricing['pricing_label'],
            'discount_percent' => (int)$pricing['discount_percent'],
            'age_days' => (int)$pricing['age_days'],
            'harvest_date' => (string)($row['harvest_date'] ?? ''),
            'stock' => (int)($row['stock_qty'] ?? 0),
            'farmer_id' => (int)($row['farmer_id'] ?? 0),
            'category_id' => (int)($row['category_id'] ?? 0),
            'category' => (string)($row['category_name'] ?? 'Uncategorized'),
            'farmer_name' => (string)($row['farmer_name'] ?? 'Local Farmer'),
            'image_path' => toPublicAssetPath((string)($row['image_path'] ?? '')),
        ];
    }

    $categoriesStmt = $pdo->prepare($baseProductsSql . ' ORDER BY p.created_at DESC');
    $categoriesStmt->execute($baseParams);
    $categoryRows = $categoriesStmt->fetchAll() ?: [];

    $categories = ['All'];
    foreach ($categoryRows as $item) {
        $pricing = calculatePerishableProductPricing($item);
        if ($pricing['is_expired']) {
            continue;
        }

        $name = trim((string)($item['category_name'] ?? ''));
        if ($name !== '') {
            $categories[] = $name;
        }
    }

    $farmersStmt = $pdo->query(
        'SELECT id, full_name, profile_image, division, district, address_line
         FROM users
         WHERE is_active = 1 AND LOWER(role) = "farmer"
         ORDER BY updated_at DESC, id DESC
         LIMIT 12'
    );

    $trustedFarmers = [];
    foreach (($farmersStmt->fetchAll() ?: []) as $farmer) {
        $division = trim((string)($farmer['division'] ?? ''));
        $district = trim((string)($farmer['district'] ?? ''));
        $addressLine = trim((string)($farmer['address_line'] ?? ''));

        $parts = array_values(array_filter([$addressLine, $district, $division], static function ($value) {
            return trim((string)$value) !== '';
        }));

        $trustedFarmers[] = [
            'id' => (int)($farmer['id'] ?? 0),
            'name' => (string)($farmer['full_name'] ?? 'Local Farmer'),
            'image_path' => toPublicAssetPath((string)($farmer['profile_image'] ?? '/figma/images (5).jpg')),
            'location' => $parts ? implode(', ', $parts) : 'Location not set',
        ];
    }

    echo json_encode([
        'success' => true,
        'products' => $products,
        'categories' => array_values(array_unique($categories)),
        'trusted_farmers' => $trustedFarmers,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load marketplace: ' . $e->getMessage(),
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

function handleProductDetail(PDO $pdo, int $productId): void
{
    if ($productId <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid product id',
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.price, p.stock_qty, p.harvest_date, p.created_at, p.image_path, p.farmer_id,
                c.name AS category_name,
                u.full_name AS farmer_name,
                u.profile_image AS farmer_profile_image,
                u.division, u.district, u.address_line
         FROM products p
         JOIN users u ON u.id = p.farmer_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = :product_id
           AND p.is_active = 1
           AND p.stock_qty > 0
           AND u.is_active = 1
           AND LOWER(u.role) = "farmer"
         LIMIT 1'
    );
    $stmt->execute([':product_id' => $productId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Product not found',
        ]);
        return;
    }

    $division = trim((string)($row['division'] ?? ''));
    $district = trim((string)($row['district'] ?? ''));
    $addressLine = trim((string)($row['address_line'] ?? ''));
    $parts = array_values(array_filter([$addressLine, $district, $division], static function ($value) {
        return trim((string)$value) !== '';
    }));

    $pricing = calculatePerishableProductPricing($row);
    if ($pricing['is_expired']) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Product expired and no longer available',
        ]);
        return;
    }

    echo json_encode([
        'success' => true,
        'product' => [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'price' => $pricing['effective_price'],
            'base_price' => $pricing['base_price'],
            'pricing_status' => (string)$pricing['pricing_status'],
            'pricing_label' => (string)$pricing['pricing_label'],
            'discount_percent' => (int)$pricing['discount_percent'],
            'age_days' => (int)$pricing['age_days'],
            'harvest_date' => (string)($row['harvest_date'] ?? ''),
            'stock' => (int)($row['stock_qty'] ?? 0),
            'farmer_id' => (int)($row['farmer_id'] ?? 0),
            'category' => (string)($row['category_name'] ?? 'Uncategorized'),
            'image_path' => toPublicAssetPath((string)($row['image_path'] ?? '')),
            'farmer_name' => (string)($row['farmer_name'] ?? 'Local Farmer'),
            'farmer_profile_image' => toPublicAssetPath((string)($row['farmer_profile_image'] ?? '/figma/images (5).jpg')),
            'farmer_location' => $parts ? implode(', ', $parts) : 'Location not set',
        ],
    ]);
}
