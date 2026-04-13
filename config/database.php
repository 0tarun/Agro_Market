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
            'discount_percent' => 100,
            'pricing_status' => 'expired',
            'pricing_label' => 'Expired',
            'is_expired' => true,
        ];
    }

    if ($ageDays >= 6) {
        $effectivePrice = round($basePrice * 0.6, 2);
        return [
            'age_days' => $ageDays,
            'base_price' => round($basePrice, 2),
            'effective_price' => $effectivePrice,
            'discount_percent' => 40,
            'pricing_status' => 'clearance',
            'pricing_label' => '40% Discount',
            'is_expired' => false,
        ];
    }

    if ($ageDays >= 3) {
        $effectivePrice = round($basePrice * 0.8, 2);
        return [
            'age_days' => $ageDays,
            'base_price' => round($basePrice, 2),
            'effective_price' => $effectivePrice,
            'discount_percent' => 20,
            'pricing_status' => 'discounted',
            'pricing_label' => '20% Discount',
            'is_expired' => false,
        ];
    }

    return [
        'age_days' => $ageDays,
        'base_price' => round($basePrice, 2),
        'effective_price' => round($basePrice, 2),
        'discount_percent' => 0,
        'pricing_status' => 'full_price',
        'pricing_label' => 'Full Price',
        'is_expired' => false,
    ];
}
