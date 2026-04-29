<?php
namespace App\Support;

use App\Models\Database;

class StoreSettings {
    private static $settingsColumnReady = false;

    public static function load(Database $db, ?string $storeHash): array {
        if (empty($storeHash)) {
            return [];
        }

        self::ensureSettingsColumn($db);

        $store = $db->fetchOne(
            "SELECT settings FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );

        $settings = json_decode($store['settings'] ?? '{}', true);
        return is_array($settings) ? $settings : [];
    }

    public static function ensureSettingsColumn(Database $db): void {
        if (self::$settingsColumnReady) {
            return;
        }

        $column = $db->fetchOne(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'bigcommerce_stores'
               AND COLUMN_NAME = 'settings'"
        );

        if (!$column) {
            $db->query("ALTER TABLE bigcommerce_stores ADD COLUMN settings JSON NULL");
        }

        self::$settingsColumnReady = true;
    }
}
