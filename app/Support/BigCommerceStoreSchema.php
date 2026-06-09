<?php
namespace App\Support;

use App\Models\Database;

class BigCommerceStoreSchema {
    private static $ready = false;

    public static function ensure(Database $db): void {
        if (self::$ready) {
            return;
        }

        $db->query(
            "CREATE TABLE IF NOT EXISTS `bigcommerce_stores` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `store_hash` VARCHAR(255) NOT NULL UNIQUE,
                `access_token` TEXT NOT NULL,
                `context` VARCHAR(255) NOT NULL,
                `scope` TEXT,
                `user_id` VARCHAR(255) NULL,
                `user_email` VARCHAR(255) NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `installed_at` DATETIME NOT NULL,
                `last_accessed` DATETIME NULL,
                `enable_omnibus` TINYINT(1) NOT NULL DEFAULT 0,
                `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
                `settings` JSON NULL,
                `updated_at` DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::ensureColumn($db, 'user_id', "ALTER TABLE bigcommerce_stores ADD COLUMN user_id VARCHAR(255) NULL AFTER scope");
        self::ensureColumn($db, 'user_email', "ALTER TABLE bigcommerce_stores ADD COLUMN user_email VARCHAR(255) NULL AFTER user_id");
        self::ensureColumn($db, 'updated_at', "ALTER TABLE bigcommerce_stores ADD COLUMN updated_at DATETIME NULL AFTER settings");

        self::$ready = true;
    }

    private static function ensureColumn(Database $db, string $columnName, string $alterSql): void {
        $column = $db->fetchOne(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'bigcommerce_stores'
               AND COLUMN_NAME = ?",
            [$columnName]
        );

        if (!$column) {
            $db->query($alterSql);
        }
    }
}
