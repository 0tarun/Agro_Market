<?php

declare(strict_types=1);

$host = '127.0.0.1';
$dbName = 'agromarket';
$dbUser = 'root';
$dbPass = '';

$dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Please check credentials in config/database.php');
}

ensurePerishableProductSchema($pdo);
ensureProductRatingSchema($pdo);
ensureOrderShippingSchema($pdo);

require_once __DIR__ . '/../api/shared/shipment-migrate.php';
ensureShipmentSchema($pdo);

function ensurePerishableProductSchema(PDO $pdo): void
{
    static $hasRun = false;
    if ($hasRun) {
        return;
    }

    $hasRun = true;

    $schemaStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = "products"
           AND column_name = "harvest_date"'
    );
    $schemaStmt->execute();
    $columnExists = (int)($schemaStmt->fetch()['total'] ?? 0) > 0;

    if (!$columnExists) {
        $pdo->exec('ALTER TABLE products ADD COLUMN harvest_date DATE NULL AFTER description');
        $pdo->exec('UPDATE products SET harvest_date = DATE(created_at) WHERE harvest_date IS NULL');
    }
}

function calculatePerishableProductPricing(array $product, ?DateTimeInterface $now = null): array
{
    $basePrice = (float)($product['price'] ?? 0);
    $harvestSource = trim((string)($product['harvest_date'] ?? ''));
    if ($harvestSource === '') {
        $harvestSource = trim((string)($product['created_at'] ?? ''));
    }

    $harvestDate = null;
    if ($harvestSource !== '') {
        try {
            $harvestDate = new DateTimeImmutable(substr($harvestSource, 0, 10));
        } catch (Throwable $e) {
            $harvestDate = null;
        }
    }

    $currentMoment = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now');
    $ageDays = 0;
    if ($harvestDate instanceof DateTimeImmutable) {
        $ageInterval = $harvestDate->diff($currentMoment);
        $ageDays = max(0, (int)$ageInterval->format('%r%a'));
    }

    if ($ageDays >= 7) {
        return [
            'age_days' => $ageDays,
            'base_price' => round($basePrice, 2),
            'effective_price' => round($basePrice, 2),
            'discount_percent' => 0,
            'pricing_status' => 'expired',
            'pricing_label' => 'Expired',
            'is_expired' => true,
            'is_purchasable' => false,
        ];
    }

    if ($ageDays >= 3 && $ageDays <= 6) {
        $effectivePrice = round($basePrice * 0.8, 2);
        return [
            'age_days' => $ageDays,
            'base_price' => round($basePrice, 2),
            'effective_price' => $effectivePrice,
            'discount_percent' => 20,
            'pricing_status' => 'discounted',
            'pricing_label' => 'Discounted',
            'is_expired' => false,
            'is_purchasable' => true,
        ];
    }

    return [
        'age_days' => $ageDays,
        'base_price' => round($basePrice, 2),
        'effective_price' => round($basePrice, 2),
        'discount_percent' => 0,
        'pricing_status' => 'fresh',
        'pricing_label' => 'Fresh',
        'is_expired' => false,
        'is_purchasable' => true,
    ];
}

function ensureProductRatingSchema(PDO $pdo): void
{
    static $hasRun = false;
    if ($hasRun) {
        return;
    }

    $hasRun = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_ratings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            consumer_id BIGINT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_product_ratings_product
                FOREIGN KEY (product_id) REFERENCES products(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_product_ratings_consumer
                FOREIGN KEY (consumer_id) REFERENCES users(id)
                ON DELETE CASCADE,
            CONSTRAINT uq_product_ratings_pair UNIQUE KEY (product_id, consumer_id),
            INDEX idx_product_ratings_product (product_id),
            INDEX idx_product_ratings_consumer (consumer_id)
        ) ENGINE=InnoDB'
    );
}

function ensureOrderShippingSchema(PDO $pdo): void
{
    static $hasRun = false;
    if ($hasRun) {
        return;
    }

    $hasRun = true;

    $subtotalColStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = "orders"
           AND column_name = "items_subtotal"'
    );
    $subtotalColStmt->execute();
    $hasSubtotalCol = (int)($subtotalColStmt->fetch()['total'] ?? 0) > 0;

    if (!$hasSubtotalCol) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN items_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER payment_method');
        $pdo->exec('UPDATE orders SET items_subtotal = total_amount WHERE items_subtotal = 0');
    }

    $shippingFeeColStmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = "orders"
           AND column_name = "shipping_fee"'
    );
    $shippingFeeColStmt->execute();
    $hasShippingFeeCol = (int)($shippingFeeColStmt->fetch()['total'] ?? 0) > 0;

    if (!$hasShippingFeeCol) {
        $pdo->exec('ALTER TABLE orders ADD COLUMN shipping_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER items_subtotal');
    }
}

function getDefaultShippingFee(): float
{
    return 60.00;
}
