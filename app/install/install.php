<?php
// install.php

// 1. Učitavanje konfiguracije i autoloadera
// Pretpostavka je da se ovaj fajl poziva iz konteksta gde je ROOT_PATH definisan
if (!defined('ROOT_PATH')) {
    // Ako nije, definišemo ga na osnovu lokacije ovog fajla
    define('ROOT_PATH', dirname(__DIR__, 2) . '/');
}
require_once ROOT_PATH . 'vendor/autoload.php';
require_once ROOT_PATH . 'config.php';

use App\Models\Database;

// Funkcija za prikaz poruka
function message($text, $type = 'info') {
    // Jednostavan ispis za sada, može se proširiti za CLI boje ili HTML
    echo "[$type] $text\n";
}

message("--- Starting Database Installation/Update ---", 'info');

try {
    // 2. Konekcija na bazu
    $db = Database::getInstance();
    message("✓ Successfully connected to the database.", 'success');

    // 3. SQL za kreiranje tabela (preuzeto iz glavnog install.php fajla)
    $sqlStatements = [
        // Tabela za prodavnice
        "CREATE TABLE IF NOT EXISTS `bigcommerce_stores` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `store_hash` VARCHAR(255) NOT NULL UNIQUE,
            `access_token` TEXT NOT NULL,
            `context` VARCHAR(255) NOT NULL,
            `scope` TEXT,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `installed_at` DATETIME NOT NULL,
            `last_accessed` DATETIME NULL,
            `enable_omnibus` TINYINT(1) NOT NULL DEFAULT 0,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
            `settings` JSON NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // Tabela za keširanje proizvoda
        "CREATE TABLE IF NOT EXISTS `products_cache` (
            `id` VARCHAR(255) PRIMARY KEY,
            `store_hash` VARCHAR(255) NOT NULL,
            `type` ENUM('product','variant') NOT NULL DEFAULT 'product',
            `product_id` INT UNSIGNED NOT NULL,
            `variant_id` INT UNSIGNED NULL,
            `name` VARCHAR(255) NULL,
            `sku` VARCHAR(255) NULL,
            `price` DECIMAL(20, 4) NOT NULL DEFAULT 0.0000,
            `sale_price` DECIMAL(20, 4) NULL,
            `cost_price` DECIMAL(20, 4) NULL,
            `retail_price` DECIMAL(20, 4) NULL,
            `weight` DECIMAL(20, 4) NULL,
            `inventory_level` INT NOT NULL DEFAULT 0,
            `inventory_warning_level` INT NOT NULL DEFAULT 0,
            `brand_id` INT UNSIGNED NULL,
            `brand_name` VARCHAR(255) NULL,
            `categories` JSON NULL,
            `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
            `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
            `availability` VARCHAR(50) NULL,
            `condition` VARCHAR(50) NULL,
            `option_values` JSON NULL,
            `date_created` DATETIME NULL,
            `date_modified` DATETIME NULL,
            `custom_fields` JSON NULL,
            `images` JSON NULL,
            `cached_at` DATETIME NOT NULL,
            UNIQUE KEY `store_product_variant` (`store_hash`, `product_id`, `variant_id`),
            INDEX `idx_store_hash` (`store_hash`),
            INDEX `idx_type` (`type`),
            INDEX `idx_brand_id` (`brand_id`),
            INDEX `idx_sku` (`sku`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `product_custom_field_index` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `store_hash` VARCHAR(255) NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `field_name` VARCHAR(255) NOT NULL,
            `field_value` VARCHAR(255) NOT NULL,
            UNIQUE KEY `uniq_store_product_field_value` (`store_hash`, `product_id`, `field_name`, `field_value`),
            INDEX `idx_store_field_name_value` (`store_hash`, `field_name`, `field_value`),
            INDEX `idx_store_product` (`store_hash`, `product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // Tabela za promocije
        "CREATE TABLE IF NOT EXISTS `promotions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `store_hash` VARCHAR(255) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `custom_field_value` VARCHAR(255) NULL,
            `discount_percent` DECIMAL(5, 2) NOT NULL,
            `start_date` DATE NOT NULL,
            `end_date` DATE NOT NULL,
            `priority` INT NOT NULL DEFAULT 0,
            `filters` JSON NOT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'inactive',
            `color` VARCHAR(20) NULL,
            `description` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_store_hash_status` (`store_hash`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // Tabela za vezu proizvoda i promocija
        "CREATE TABLE IF NOT EXISTS `promotion_products` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `store_hash` VARCHAR(255) NOT NULL,
            `promotion_id` INT NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `variant_id` INT UNSIGNED NULL,
            `product_name` VARCHAR(255) NOT NULL,
            `original_price` DECIMAL(20, 4) NOT NULL,
            `promo_price` DECIMAL(20, 4) NOT NULL,
            `custom_field_id` INT UNSIGNED NULL,
            `synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `store_product_variant` (`store_hash`, `product_id`, `variant_id`),
            INDEX `idx_promotion_id` (`promotion_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // Tabela za logovanje sinhronizacije
        "CREATE TABLE IF NOT EXISTS `sync_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `store_hash` VARCHAR(255) NOT NULL,
            `promotion_id` INT NULL,
            `sync_type` VARCHAR(50) NOT NULL,
            `products_synced` INT NOT NULL DEFAULT 0,
            `errors` INT NOT NULL DEFAULT 0,
            `duration_seconds` DECIMAL(10, 4) NOT NULL,
            `log_message` TEXT,
            `synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_store_hash_type` (`store_hash`, `sync_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // Tabela za red poslova (Queue)
        "CREATE TABLE IF NOT EXISTS `sync_jobs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `store_hash` VARCHAR(255) NOT NULL,
            `job_type` VARCHAR(50) NOT NULL,
            `promotion_id` INT NULL,
            `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
            `total_items` INT NOT NULL DEFAULT 0,
            `processed_items` INT NOT NULL DEFAULT 0,
            `attempts` INT NOT NULL DEFAULT 0,
            `error_message` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            `next_run_at` DATETIME NULL,
            INDEX `idx_status_next_run` (`status`, `next_run_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // Tabela za webhook-ove
        "CREATE TABLE IF NOT EXISTS `webhooks` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `store_hash` VARCHAR(255) NOT NULL,
            `bc_webhook_id` INT UNSIGNED NOT NULL UNIQUE,
            `scope` VARCHAR(255) NOT NULL,
            `destination` VARCHAR(255) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // Tabela za događaje sa webhook-ova
        "CREATE TABLE IF NOT EXISTS `webhook_events` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `store_hash` VARCHAR(255) NOT NULL,
            `scope` VARCHAR(255) NOT NULL,
            `resource_id` VARCHAR(255) NOT NULL,
            `resource_type` VARCHAR(50) NOT NULL,
            `payload` JSON NOT NULL,
            `processed` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `processed_at` DATETIME NULL,
            INDEX `idx_store_hash_processed` (`store_hash`, `processed`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        // Tabela za istoriju cena (Omnibus)
        "CREATE TABLE IF NOT EXISTS `product_price_history` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `store_hash` VARCHAR(255) NOT NULL,
            `product_id` INT UNSIGNED NOT NULL,
            `variant_id` INT UNSIGNED NULL,
            `price` DECIMAL(20, 4) NOT NULL,
            `currency` VARCHAR(10) NOT NULL,
            `recorded_at` DATETIME NOT NULL,
            INDEX `idx_omnibus_lookup` (`store_hash`, `product_id`, `variant_id`, `currency`, `recorded_at`),
            UNIQUE KEY `store_product_variant_currency_time` (`store_hash`, `product_id`, `variant_id`, `currency`, `recorded_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    ];

    // 4. Izvršavanje SQL komandi
    foreach ($sqlStatements as $sql) {
        preg_match('/`([^`]+)`/', $sql, $matches);
        $tableName = $matches[1] ?? 'unknown';
        
        try {
            $db->query($sql);
            message("  - Table `{$tableName}` created/verified successfully.", 'success');
        } catch (\PDOException $e) {
            message("  - Error creating table `{$tableName}`: " . $e->getMessage(), 'error');
        }
    }

    message("\n--- Database setup complete! ---", 'info');

} catch (\PDOException $e) {
    message("DATABASE CONNECTION FAILED: " . $e->getMessage(), 'error');
    exit(1); // Izlaz sa greškom
} catch (\Exception $e) {
    message("An unexpected error occurred: " . $e->getMessage(), 'error');
    exit(1);
}
