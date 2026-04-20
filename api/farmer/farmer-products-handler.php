<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// Check if user is logged in
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Please login first'
    ]);
    exit;
}

try {
    // Verify user is a farmer and is active
    $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
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

    if ($role !== 'farmer') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only farmers can access this']);
        exit;
    }

    // Get action
    $action = '';
    if (!empty($_POST['action'])) {
        $action = trim((string)$_POST['action']);
    } else {
        $jsonInput = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = trim($jsonInput['action'] ?? '');
    }

    if ($action === 'add_product') {
        handleAddProduct($pdo, $userId);
    } elseif ($action === 'list_products') {
        handleListProducts($pdo, $userId);
    } elseif ($action === 'delete_product') {
        handleDeleteProduct($pdo, $userId);
    } elseif ($action === 'update_product') {
        handleUpdateProduct($pdo, $userId);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

/**
 * Handle adding a new product
 */
function handleAddProduct(PDO $pdo, int $userId): void
{
    try {
        // Get form data
        $productName = trim((string)($_POST['product_name'] ?? ''));
        $productPrice = (float)($_POST['product_price'] ?? 0);
        $productStock = (int)($_POST['product_stock'] ?? 0);
        $productCategory = (int)($_POST['product_category'] ?? 0);
        $productCategoryName = trim((string)($_POST['product_category_name'] ?? ''));
        $harvestDate = trim((string)($_POST['harvest_date'] ?? ''));

        // Validation
        if (empty($productName)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product name is required']);
            return;
        }

        if (strlen($productName) > 140) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product name must be less than 140 characters']);
            return;
        }

        if ($productPrice <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Price must be greater than 0']);
            return;
        }

        if ($productStock < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Stock cannot be negative']);
            return;
        }

        if (!isValidHarvestDate($harvestDate)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please provide a valid harvest date']);
            return;
        }

        if ($productCategory <= 0 && $productCategoryName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please select a valid category']);
            return;
        }

        // Resolve category either by ID or by name (creates default categories on first use).
        if ($productCategory > 0) {
            $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $productCategory]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Selected category does not exist']);
                return;
            }
        } else {
            $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
            $stmt->execute([':name' => $productCategoryName]);
            $existing = $stmt->fetch();

            if ($existing && isset($existing['id'])) {
                $productCategory = (int)$existing['id'];
            } else {
                $insertCategory = $pdo->prepare('INSERT INTO categories (name) VALUES (:name)');
                $insertCategory->execute([':name' => $productCategoryName]);
                $productCategory = (int)$pdo->lastInsertId();
            }
        }

        // Handle image upload
        $imagePath = null;
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            try {
                $imagePath = uploadProductImage($_FILES['product_image'], $userId);
            } catch (Throwable $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Image upload failed: ' . $e->getMessage()]);
                return;
            }
        }

        // Insert into database
        $stmt = $pdo->prepare(
            'INSERT INTO products (farmer_id, category_id, name, description, harvest_date, price, stock_qty, image_path, is_active)
             VALUES (:farmer_id, :category_id, :name, :description, :harvest_date, :price, :stock_qty, :image_path, 1)'
        );

        $stmt->execute([
            ':farmer_id' => $userId,
            ':category_id' => $productCategory,
            ':name' => $productName,
            ':description' => null,
            ':harvest_date' => $harvestDate,
            ':price' => $productPrice,
            ':stock_qty' => $productStock,
            ':image_path' => $imagePath
        ]);

        $productId = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Product added successfully',
            'product_id' => $productId
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error adding product: ' . $e->getMessage()
        ]);
    }
}

/**
 * Handle listing current farmer's products
 */
function handleListProducts(PDO $pdo, int $userId): void
{
    try {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.name, p.price, p.stock_qty, p.image_path, p.harvest_date, p.created_at, c.id AS category_id, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.farmer_id = :farmer_id AND p.is_active = 1
             ORDER BY p.id DESC'
        );
        $stmt->execute([':farmer_id' => $userId]);
        $rows = $stmt->fetchAll() ?: [];

        $products = [];
        foreach ($rows as $row) {
            $pricing = calculatePerishableProductPricing($row);
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
                'category_id' => (int)($row['category_id'] ?? 0),
                'category' => (string)($row['category_name'] ?? 'Uncategorized'),
                'image_path' => toPublicAssetPath((string)($row['image_path'] ?? '')),
            ];
        }

        echo json_encode([
            'success' => true,
            'products' => $products,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error loading products: ' . $e->getMessage(),
        ]);
    }
}

/**
 * Handle deleting a product
 */
function handleDeleteProduct(PDO $pdo, int $userId): void
{
    try {
        // Get JSON input
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true) ?? [];
        $productId = (int)($input['product_id'] ?? 0);

        if ($productId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
            return;
        }

        // Verify product belongs to this farmer
        $stmt = $pdo->prepare(
            'SELECT id, image_path FROM products WHERE id = :product_id AND farmer_id = :farmer_id LIMIT 1'
        );
        $stmt->execute([
            ':product_id' => $productId,
            ':farmer_id' => $userId
        ]);
        $product = $stmt->fetch();

        if (!$product) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Product not found or you do not own it']);
            return;
        }

        // Hard delete from database.
        $stmt = $pdo->prepare(
            'DELETE FROM products WHERE id = :product_id AND farmer_id = :farmer_id'
        );
        $stmt->execute([
            ':product_id' => $productId,
            ':farmer_id' => $userId
        ]);

        // Best-effort cleanup for uploaded product image after DB deletion.
        $imagePath = trim((string)($product['image_path'] ?? ''));
        if ($imagePath !== '' && strpos($imagePath, '/image/uploads/products/') === 0) {
            $absoluteImagePath = __DIR__ . '/../../' . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $imagePath), DIRECTORY_SEPARATOR);
            if (is_file($absoluteImagePath)) {
                @unlink($absoluteImagePath);
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    } catch (PDOException $e) {
        // If product is referenced in order_items, deletion is blocked by FK constraint.
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'This product has order history, so it cannot be deleted permanently.'
            ]);
            return;
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting product: ' . $e->getMessage()
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting product: ' . $e->getMessage()
        ]);
    }
}

/**
 * Handle updating a product
 */
function handleUpdateProduct(PDO $pdo, int $userId): void
{
    try {
        // Get JSON input
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true) ?? [];

        $productId = (int)($input['product_id'] ?? 0);
        $productName = trim((string)($input['product_name'] ?? ''));
        $productPrice = (float)($input['product_price'] ?? 0);
        $productStock = (int)($input['product_stock'] ?? 0);
        $productCategory = (int)($input['product_category'] ?? 0);
        $productCategoryName = trim((string)($input['product_category_name'] ?? ''));
        $harvestDate = trim((string)($input['harvest_date'] ?? ''));

        // Validation
        if ($productId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
            return;
        }

        if (empty($productName)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product name is required']);
            return;
        }

        if (strlen($productName) > 140) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Product name must be less than 140 characters']);
            return;
        }

        if ($productPrice <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Price must be greater than 0']);
            return;
        }

        if ($productStock < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Stock cannot be negative']);
            return;
        }

        // harvest_date is optional on update — if not provided, keep existing value.
        $updateHarvest = false;
        if ($harvestDate !== '') {
            if (!isValidHarvestDate($harvestDate)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please provide a valid harvest date']);
                return;
            }
            $updateHarvest = true;
        }

        if ($productCategory <= 0 && $productCategoryName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please select a valid category']);
            return;
        }

        // Verify product belongs to this farmer
        $stmt = $pdo->prepare(
            'SELECT id FROM products WHERE id = :product_id AND farmer_id = :farmer_id LIMIT 1'
        );
        $stmt->execute([
            ':product_id' => $productId,
            ':farmer_id' => $userId
        ]);
        $product = $stmt->fetch();

        if (!$product) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Product not found or you do not own it']);
            return;
        }

        // Resolve category either by ID or by name.
        if ($productCategory > 0) {
            $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $productCategory]);
            if (!$stmt->fetch()) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Selected category does not exist']);
                return;
            }
        } else {
            $stmt = $pdo->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
            $stmt->execute([':name' => $productCategoryName]);
            $existing = $stmt->fetch();

            if ($existing && isset($existing['id'])) {
                $productCategory = (int)$existing['id'];
            } else {
                $insertCategory = $pdo->prepare('INSERT INTO categories (name) VALUES (:name)');
                $insertCategory->execute([':name' => $productCategoryName]);
                $productCategory = (int)$pdo->lastInsertId();
            }
        }

        // Update product — conditionally include harvest_date
        if ($updateHarvest) {
            $stmt = $pdo->prepare(
                'UPDATE products 
                 SET name = :name, price = :price, stock_qty = :stock_qty, category_id = :category_id, harvest_date = :harvest_date
                 WHERE id = :product_id AND farmer_id = :farmer_id'
            );
            $stmt->execute([
                ':name' => $productName,
                ':price' => $productPrice,
                ':stock_qty' => $productStock,
                ':category_id' => $productCategory,
                ':harvest_date' => $harvestDate,
                ':product_id' => $productId,
                ':farmer_id' => $userId
            ]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE products 
                 SET name = :name, price = :price, stock_qty = :stock_qty, category_id = :category_id
                 WHERE id = :product_id AND farmer_id = :farmer_id'
            );
            $stmt->execute([
                ':name' => $productName,
                ':price' => $productPrice,
                ':stock_qty' => $productStock,
                ':category_id' => $productCategory,
                ':product_id' => $productId,
                ':farmer_id' => $userId
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Product updated successfully'
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error updating product: ' . $e->getMessage()
        ]);
    }
}

function isValidHarvestDate(string $harvestDate): bool
{
    if ($harvestDate === '') {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $harvestDate);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $harvestDate;
}

/**
 * Handle product image upload
 */
function uploadProductImage(array $file, int $userId): ?string
{
    // Create upload directory if it doesn't exist
    $uploadDir = __DIR__ . '/../../image/uploads/products/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    // Validate file type
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new Exception('Invalid image file type. Allowed: JPG, PNG, GIF, WebP');
    }

    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('Image file too large. Maximum size: 5MB');
    }

    // Generate unique filename
    $ext = match ($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'jpg'
    };

    $filename = 'product_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save image file');
    }

    return '/image/uploads/products/' . $filename;
}

/**
 * Convert stored paths into web-accessible paths across root/subfolder installs.
 */
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
