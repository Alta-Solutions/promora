<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

class OmnibusSyncSchedulerService {
    private $db;
    private $queueFactory;
    private int $fullSyncHour;
    private array $columnCache = [];

    private const DEFAULT_FULL_SYNC_HOUR = 2;

    public function __construct($db, ?callable $queueFactory = null, ?int $fullSyncHour = null) {
        $this->db = $db;
        $this->queueFactory = $queueFactory;
        $this->fullSyncHour = $this->normalizeFullSyncHour($fullSyncHour ?? $this->readFullSyncHourFromEnvironment());
    }

    public function scheduleAllStores(?DateTimeImmutable $now = null): array {
        $now = $now ?? new DateTimeImmutable('now');
        $stores = $this->db->fetchAll(
            "SELECT store_hash FROM bigcommerce_stores WHERE enable_omnibus = 1"
        );

        $results = [];
        foreach ($stores as $store) {
            $storeHash = trim((string)($store['store_hash'] ?? ''));
            if ($storeHash === '') {
                continue;
            }

            $this->db->setStoreContext($storeHash);
            $results[] = $this->scheduleStore($storeHash, $now);
        }

        return $results;
    }

    public function scheduleStore(string $storeHash, ?DateTimeImmutable $now = null): array {
        $now = $now ?? new DateTimeImmutable('now');
        $dayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');

        if ($this->shouldScheduleFullSync($storeHash, $now, $dayStart)) {
            $totalItems = $this->countParentProducts($storeHash);
            $result = $this->queue($storeHash)->createOmnibusSyncJob($totalItems > 0 ? $totalItems : 1);

            return $result + [
                'mode' => 'full',
                'store_hash' => $storeHash,
                'total_items' => $totalItems,
                'day_start' => $dayStart,
            ];
        }

        $dirty = $this->collectDirtyProductIds($storeHash, $dayStart);
        if (empty($dirty['product_ids'])) {
            return [
                'created' => false,
                'job_id' => null,
                'reason' => 'no_dirty_products',
                'message' => 'No dirty products found for incremental Omnibus sync.',
                'mode' => 'incremental',
                'store_hash' => $storeHash,
                'day_start' => $dayStart,
                'dirty' => $dirty,
            ];
        }

        $result = $this->queue($storeHash)->createTargetedOmnibusSyncJob(
            $dirty['product_ids'],
            [
                'source' => 'scheduler_incremental',
                'day_start' => $dayStart,
                'cache_dirty_count' => $dirty['cache_dirty_count'],
                'history_dirty_count' => $dirty['history_dirty_count'],
                'ignored_history_dirty_count' => $dirty['ignored_history_dirty_count'],
                'total_dirty_count' => count($dirty['product_ids']),
            ]
        );

        return $result + [
            'mode' => 'incremental',
            'store_hash' => $storeHash,
            'day_start' => $dayStart,
            'dirty' => $dirty,
        ];
    }

    public function collectDirtyProductIds(string $storeHash, string $dayStart): array {
        $cacheProductIds = $this->fetchProductIds(
            "SELECT DISTINCT product_id
             FROM products_cache
             WHERE store_hash = ?
               AND cached_at >= ?
               AND product_id IS NOT NULL",
            [$storeHash, $dayStart]
        );

        $historyProductIds = $this->fetchProductIds(
            "SELECT DISTINCT product_id
             FROM product_price_history
             WHERE store_hash = ?
               AND recorded_at >= ?
               AND product_id IS NOT NULL",
            [$storeHash, $dayStart]
        );

        $ignoredHistoryProductIds = [];
        if ($this->tableHasColumn('product_price_history', 'ignored_at')) {
            $ignoredHistoryProductIds = $this->fetchProductIds(
                "SELECT DISTINCT product_id
                 FROM product_price_history
                 WHERE store_hash = ?
                   AND ignored_at >= ?
                   AND product_id IS NOT NULL",
                [$storeHash, $dayStart]
            );
        }

        $productIds = $this->normalizeProductIds(array_merge(
            $cacheProductIds,
            $historyProductIds,
            $ignoredHistoryProductIds
        ));

        return [
            'product_ids' => $productIds,
            'cache_dirty_count' => count($cacheProductIds),
            'history_dirty_count' => count($historyProductIds),
            'ignored_history_dirty_count' => count($ignoredHistoryProductIds),
        ];
    }

    private function shouldScheduleFullSync(string $storeHash, DateTimeImmutable $now, string $dayStart): bool {
        if ((int)$now->format('G') < $this->fullSyncHour) {
            return false;
        }

        $existingToday = $this->db->fetchOne(
            "SELECT id, status
             FROM sync_jobs
             WHERE store_hash = ?
               AND job_type = 'omnibus_sync'
               AND status IN ('pending', 'processing', 'completed')
               AND created_at >= ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            [$storeHash, $dayStart]
        );

        return empty($existingToday);
    }

    private function countParentProducts(string $storeHash): int {
        $baseProductClause = $this->tableHasColumn('products_cache', 'type') ? " AND type = 'product'" : '';
        $row = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT product_id) AS total
             FROM products_cache
             WHERE store_hash = ?" . $baseProductClause,
            [$storeHash]
        );

        return (int)($row['total'] ?? 0);
    }

    private function fetchProductIds(string $sql, array $params): array {
        $rows = $this->db->fetchAll($sql, $params);
        $productIds = [];

        foreach ($rows as $row) {
            $productIds[] = $row['product_id'] ?? null;
        }

        return $this->normalizeProductIds($productIds);
    }

    private function normalizeProductIds(array $productIds): array {
        $normalized = [];
        foreach ($productIds as $productId) {
            if (!is_numeric($productId)) {
                continue;
            }

            $productId = (int)$productId;
            if ($productId > 0) {
                $normalized[$productId] = true;
            }
        }

        $productIds = array_keys($normalized);
        sort($productIds, SORT_NUMERIC);
        return $productIds;
    }

    private function tableHasColumn(string $tableName, string $columnName): bool {
        $cacheKey = $tableName . '.' . $columnName;
        if (array_key_exists($cacheKey, $this->columnCache)) {
            return $this->columnCache[$cacheKey];
        }

        try {
            $column = $this->db->fetchOne("SHOW COLUMNS FROM {$tableName} LIKE '{$columnName}'");
            $this->columnCache[$cacheKey] = $column !== false && $column !== null;
        } catch (\Throwable $e) {
            $this->columnCache[$cacheKey] = false;
        }

        return $this->columnCache[$cacheKey];
    }

    private function queue(string $storeHash) {
        if ($this->queueFactory !== null) {
            return ($this->queueFactory)($storeHash);
        }

        return new QueueService($storeHash);
    }

    private function readFullSyncHourFromEnvironment(): int {
        $value = $_ENV['OMNIBUS_FULL_SYNC_HOUR'] ?? getenv('OMNIBUS_FULL_SYNC_HOUR');
        return is_numeric($value) ? (int)$value : self::DEFAULT_FULL_SYNC_HOUR;
    }

    private function normalizeFullSyncHour(int $hour): int {
        return $hour >= 0 && $hour <= 23 ? $hour : self::DEFAULT_FULL_SYNC_HOUR;
    }
}
