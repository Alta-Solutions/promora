<?php
declare(strict_types=1);

namespace App\Support;

use App\Models\Database;

class MigrationRunner {
    private Database $db;

    public function __construct(Database $db = null) {
        $this->db = $db ?? Database::getInstance();
    }

    public function status(): array {
        $this->ensureLedger();
        $appliedRows = $this->db->fetchAll("SELECT migration FROM schema_migrations ORDER BY migration ASC");
        $applied = array_fill_keys(array_column($appliedRows, 'migration'), true);

        $status = [];
        foreach ($this->migrations() as $migration => $_) {
            $status[] = [
                'migration' => $migration,
                'applied' => isset($applied[$migration]),
            ];
        }

        return $status;
    }

    public function up(): array {
        $this->ensureLedger();
        $appliedRows = $this->db->fetchAll("SELECT migration FROM schema_migrations");
        $applied = array_fill_keys(array_column($appliedRows, 'migration'), true);
        $ran = [];

        foreach ($this->migrations() as $migration => $callback) {
            if (isset($applied[$migration])) {
                continue;
            }

            $callback();
            $this->db->query(
                "INSERT INTO schema_migrations (migration, applied_at) VALUES (?, NOW())",
                [$migration]
            );
            $ran[] = $migration;
        }

        return $ran;
    }

    private function ensureLedger(): void {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function migrations(): array {
        return [
            '202609150001_performance_indexes_and_filter_tables' => function (): void {
                $this->db->query(
                    "CREATE TABLE IF NOT EXISTS product_category_index (
                        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        store_hash VARCHAR(255) NOT NULL,
                        cache_row_id VARCHAR(255) NOT NULL,
                        product_id INT UNSIGNED NOT NULL,
                        category_id INT UNSIGNED NOT NULL,
                        UNIQUE KEY uniq_store_cache_category (store_hash, cache_row_id, category_id),
                        INDEX idx_store_category_cache (store_hash, category_id, cache_row_id),
                        INDEX idx_store_product (store_hash, product_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );

                $this->db->query(
                    "CREATE TABLE IF NOT EXISTS product_search_trigram (
                        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        store_hash VARCHAR(255) NOT NULL,
                        cache_row_id VARCHAR(255) NOT NULL,
                        trigram VARCHAR(3) NOT NULL,
                        UNIQUE KEY uniq_store_cache_trigram (store_hash, cache_row_id, trigram),
                        INDEX idx_store_trigram_cache (store_hash, trigram, cache_row_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );

                $this->addIndex('products_cache', 'idx_store_type_product', ['store_hash', 'type', 'product_id']);
                $this->addIndex('products_cache', 'idx_store_product_variant_id', ['store_hash', 'product_id', 'variant_id', 'id']);
                $this->addIndex('sync_log', 'idx_store_promotion_synced', ['store_hash', 'promotion_id', 'synced_at']);
                $this->addIndex('sync_log', 'idx_store_errors_synced', ['store_hash', 'errors', 'synced_at']);
                $this->addIndex('sync_log', 'idx_store_type_synced', ['store_hash', 'sync_type', 'synced_at']);
                $this->addIndex('webhook_events', 'idx_store_processed_created', ['store_hash', 'processed', 'created_at']);
                $this->addIndex('promotion_product_history', 'idx_store_active_history', ['store_hash', 'promotion_id', 'removed_at', 'product_id', 'variant_id']);
                $this->addIndex('sync_jobs', 'idx_store_status_updated', ['store_hash', 'status', 'updated_at', 'created_at']);

                $this->addGeneratedColumn(
                    'products_cache',
                    'sku_cleaned',
                    "VARCHAR(255) GENERATED ALWAYS AS (NULLIF(TRIM(`sku`), '')) STORED"
                );
                $this->addGeneratedColumn(
                    'products_cache',
                    'variant_sort_id',
                    "INT UNSIGNED GENERATED ALWAYS AS (IFNULL(`variant_id`, 0)) STORED"
                );
                $this->addIndex('products_cache', 'idx_store_sku_cleaned', ['store_hash', 'sku_cleaned']);
                $this->addIndex('products_cache', 'idx_store_product_variant_sort', ['store_hash', 'product_id', 'variant_sort_id', 'id']);
            },
            '202609150002_filter_stats_covering_index' => function (): void {
                $this->addIndex('products_cache', 'idx_store_filter_stats', ['store_hash', 'is_visible', 'is_featured', 'inventory_level', 'price']);
            },
        ];
    }

    private function addIndex(string $table, string $index, array $columns): void {
        if ($this->indexExists($table, $index)) {
            return;
        }

        foreach ($columns as $column) {
            if (!$this->columnExists($table, $column)) {
                return;
            }
        }

        $columnSql = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
        $this->db->query("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$columnSql})");
    }

    private function addGeneratedColumn(string $table, string $column, string $definition): void {
        if ($this->columnExists($table, $column)) {
            return;
        }

        try {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (\Throwable $e) {
            error_log("Generated column {$table}.{$column} could not be added: " . $e->getMessage());
        }
    }

    private function indexExists(string $table, string $index): bool {
        $row = $this->db->fetchOne(
            "SELECT 1
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1",
            [$table, $index]
        );

        return !empty($row);
    }

    private function columnExists(string $table, string $column): bool {
        $row = $this->db->fetchOne(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1",
            [$table, $column]
        );

        return !empty($row);
    }
}
