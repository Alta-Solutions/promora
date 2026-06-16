<?php
declare(strict_types=1);

namespace App\Services;

class PriceHistorySchemaService {
    private static $ensured = [];

    public static function ensureIgnoredColumns($db): void {
        $key = spl_object_hash($db);
        if (isset(self::$ensured[$key])) {
            return;
        }

        $ensured = false;
        try {
            self::ensureColumn($db, 'ignored_at', 'DATETIME NULL AFTER recorded_at');
            self::ensureColumn($db, 'ignored_reason', 'TEXT NULL AFTER ignored_at');
            self::ensureColumn($db, 'ignored_by_correction_id', 'BIGINT UNSIGNED NULL AFTER ignored_reason');
            $ensured = true;
        } catch (\Throwable $e) {
            // Schema guards must not break read paths in partially upgraded installs.
        }

        if ($ensured) {
            self::$ensured[$key] = true;
        }
    }

    private static function ensureColumn($db, string $columnName, string $definition): void {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $columnName)) {
            throw new \InvalidArgumentException('Invalid column name.');
        }

        $column = $db->fetchOne("SHOW COLUMNS FROM product_price_history LIKE '{$columnName}'");

        if (!$column) {
            $db->query("ALTER TABLE product_price_history ADD COLUMN {$columnName} {$definition}");
        }
    }
}
