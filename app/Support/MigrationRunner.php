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
            '202611230001_operations' => function (): void {
                foreach ([
                    'payload' => 'LONGTEXT NULL', 'lease_token' => 'CHAR(32) NULL', 'lease_until' => 'DATETIME NULL',
                    'checkpoint' => 'LONGTEXT NULL', 'outcome' => 'VARCHAR(24) NULL',
                ] as $column => $type) {
                    $this->addColumn('sync_jobs', $column, $type);
                }
                $this->addColumn('webhook_events', 'terminal_at', 'DATETIME NULL');
                $this->addIndex('webhook_events','idx_terminal_retention',['store_hash','processed','terminal_at','id']);
                $time = \App\Services\OperationalRetention::webhookTimeColumn($this->db);
                $this->addIndex('webhook_events', 'idx_retention_events', ['store_hash','processed',$time,'id']);
                $this->addIndex('sync_jobs', 'idx_lease_expiry', ['status','lease_until']);
                $this->addColumn('bigcommerce_stores', 'catalog_synced_until', 'DATETIME NULL');
            },
            '202611230002_price_ownership' => function (): void {
                if (!$this->columnExists('promotion_application_corrections','id')) {
                    new \App\Services\PromotionApplicationCorrectionService($this->db);
                }
                foreach (['start_date','end_date'] as $column) {
                    $row = $this->db->fetchOne("SHOW COLUMNS FROM promotions LIKE '$column'");
                    if (strtolower($row['Type']) === 'date') {
                        $this->db->query("ALTER TABLE promotions MODIFY $column DATETIME NOT NULL");
                    }
                }
                foreach (['product_name' => 'VARCHAR(255)', 'original_price' => 'DECIMAL(20,4)',
                    'promo_price' => 'DECIMAL(20,4)', 'previous_sale_price' => 'DECIMAL(20,4)',
                    'prior_price_known' => 'TINYINT(1) NOT NULL DEFAULT 0'] as $column => $type) {
                    $this->addColumn('promotion_products', $column, $type);
                }
                $this->db->query("CREATE TABLE IF NOT EXISTS price_write_intents (
                    store_hash VARCHAR(255) NOT NULL, product_id INT UNSIGNED NOT NULL, variant_key INT UNSIGNED NOT NULL,
                    promotion_id INT NOT NULL, restoring TINYINT(1) NOT NULL, target_price DECIMAL(20,4),
                    prior_price DECIMAL(20,4), PRIMARY KEY(store_hash,product_id,variant_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $this->db->query("CREATE TABLE IF NOT EXISTS price_conflicts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, store_hash VARCHAR(255) NOT NULL,
                    product_id INT UNSIGNED NOT NULL, variant_key INT UNSIGNED NOT NULL,
                    promotion_id INT NOT NULL, lifecycle VARCHAR(64) NOT NULL,
                    expected_price DECIMAL(20,4), observed_price DECIMAL(20,4),
                    decision VARCHAR(20) NOT NULL DEFAULT 'open', actor VARCHAR(255), decided_at DATETIME,
                    updated_at DATETIME NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1,
                    UNIQUE KEY uniq_conflict(store_hash,product_id,variant_key,promotion_id,lifecycle)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            },
            '202611230003_omnibus_changes' => function (): void {
                $this->addColumn('product_price_history','ignored_at','DATETIME NULL');
                $this->addColumn('promotion_products','omnibus_reference_at','DATETIME NULL');
                $this->db->query("CREATE TABLE IF NOT EXISTS omnibus_changes (
                    store_hash VARCHAR(255) NOT NULL, product_id INT UNSIGNED NOT NULL,
                    version BIGINT UNSIGNED NOT NULL DEFAULT 1, acknowledged BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    last_failure_at DATETIME NULL,
                    PRIMARY KEY(store_hash,product_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                foreach ([
                    'products_cache' => ['INSERT' => '', 'DELETE' => '', 'UPDATE' => 'NOT (OLD.price <=> NEW.price AND OLD.sale_price <=> NEW.sale_price)'],
                    'product_price_history' => ['INSERT' => '', 'DELETE' => '', 'UPDATE' => 'NOT (OLD.ignored_at <=> NEW.ignored_at AND OLD.price <=> NEW.price AND OLD.currency <=> NEW.currency AND OLD.recorded_at <=> NEW.recorded_at)'],
                    'promotion_products' => ['INSERT' => '', 'DELETE' => '', 'UPDATE' => 'NOT (OLD.promotion_id <=> NEW.promotion_id AND OLD.omnibus_reference_at <=> NEW.omnibus_reference_at)'],
                ] as $table => $events) {
                    foreach ($events as $event => $condition) {
                        $row = $event === 'DELETE' ? 'OLD' : 'NEW';
                        $name = 'promora_dirty_' . $table . '_' . strtolower($event);
                        if ($this->db->fetchOne("SELECT 1 FROM information_schema.triggers WHERE trigger_schema=DATABASE()
                            AND trigger_name=?", [$name])) {
                            continue;
                        }
                        $insert = "INSERT INTO omnibus_changes(store_hash,product_id) VALUES ($row.store_hash,$row.product_id)
                            ON DUPLICATE KEY UPDATE version=version+1";
                        $body = $condition ? "BEGIN IF $condition THEN $insert; END IF; END" : $insert;
                        $this->db->query("CREATE TRIGGER $name AFTER $event ON $table FOR EACH ROW $body");
                    }
                }
            },
            '202611230004_unique_price_owner' => function (): void {
                $this->addColumn('price_conflicts','allow_apply','TINYINT(1) NOT NULL DEFAULT 1');
                $this->addColumn('promotion_products','verified_at','DATETIME NULL');
                if ($this->indexExists('promotion_products', 'uniq_store_item_owner')) {
                    return;
                }
                $duplicates = $this->db->fetchOne("SELECT COUNT(*) AS groups_found FROM (
                    SELECT 1 FROM promotion_products GROUP BY store_hash, product_id, IFNULL(variant_id, 0)
                    HAVING COUNT(*) > 1) AS duplicates");
                if ($duplicates['groups_found'] > 0) {
                    throw new \RuntimeException('Resolve duplicate ownership groups before continuing migration: ' . $duplicates['groups_found']);
                }
                $this->addColumn('promotion_products', 'variant_key',
                    'INT UNSIGNED GENERATED ALWAYS AS (IFNULL(variant_id, 0)) STORED');
                $this->db->query('ALTER TABLE promotion_products ADD UNIQUE INDEX uniq_store_item_owner(store_hash,product_id,variant_key)');
            },
            '202611230005_conflict_decisions' => function (): void {
                $this->db->query("CREATE TABLE IF NOT EXISTS price_conflict_decisions (
                    store_hash VARCHAR(255) NOT NULL, conflict_id BIGINT UNSIGNED NOT NULL, revision INT UNSIGNED NOT NULL,
                    expected_price DECIMAL(20,4), observed_price DECIMAL(20,4), decision VARCHAR(20) NOT NULL,
                    actor VARCHAR(255) NOT NULL, decided_at DATETIME NOT NULL, PRIMARY KEY(store_hash,conflict_id,revision)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            },
        ];
    }

    private function addColumn(string $table, string $column, string $type): void {
        if (!$this->columnExists($table, $column)) {
            $this->db->query("ALTER TABLE $table ADD COLUMN $column $type");
        }
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
