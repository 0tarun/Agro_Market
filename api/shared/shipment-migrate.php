<?php

declare(strict_types=1);

/**
 * Shipment schema auto-migration.
 * Called once per request from config/database.php (same pattern as ensurePerishableProductSchema).
 */
function ensureShipmentSchema(PDO $pdo): void
{
    static $hasRun = false;
    if ($hasRun) {
        return;
    }

    $hasRun = true;

    // Check if shipments table exists
    $tableCheck = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = "shipments"'
    );
    $tableCheck->execute();
    $shipmentsExists = (int)($tableCheck->fetch()['total'] ?? 0) > 0;

    if (!$shipmentsExists) {
        $pdo->exec('
            CREATE TABLE shipments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                tracking_code VARCHAR(50) NOT NULL UNIQUE,
                carrier_name VARCHAR(100) DEFAULT "AgroMarket Delivery",
                status ENUM("preparing","shipped","in_transit","out_for_delivery","delivered") DEFAULT "preparing",
                estimated_delivery DATE NULL,
                shipped_at DATETIME NULL,
                delivered_at DATETIME NULL,
                current_location VARCHAR(255) NULL,
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    // Check if shipment_events table exists
    $eventsCheck = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = "shipment_events"'
    );
    $eventsCheck->execute();
    $eventsExists = (int)($eventsCheck->fetch()['total'] ?? 0) > 0;

    if (!$eventsExists) {
        $pdo->exec('
            CREATE TABLE shipment_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                shipment_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(50) NOT NULL,
                location VARCHAR(255) NULL,
                description VARCHAR(500) NULL,
                event_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shipment_id) REFERENCES shipments(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }
}

/**
 * Generate a unique tracking code for a shipment.
 */
function generateTrackingCode(PDO $pdo): string
{
    for ($i = 0; $i < 5; $i++) {
        $candidate = 'TRK' . date('ymdHis') . strtoupper(bin2hex(random_bytes(2)));
        $check = $pdo->prepare('SELECT id FROM shipments WHERE tracking_code = :code LIMIT 1');
        $check->execute([':code' => $candidate]);
        if (!$check->fetch()) {
            return $candidate;
        }
    }

    return 'TRK' . date('ymdHis') . strtoupper(bin2hex(random_bytes(4)));
}
