<?php
namespace App\Services;

use App\Models\Database;

class PromotionArchiveService {
    private $db;
    private string $storeHash;
    private array $archiveIdsByPromotion = [];

    public function __construct($db = null, ?string $storeHash = null) {
        $this->db = $db ?? Database::getInstance();
        $this->storeHash = (string)($storeHash ?? $this->db->getStoreContext());

        if ($this->storeHash === '') {
            throw new \Exception("Store context required for promotion archive.");
        }

        self::ensureSchema($this->db);
    }

    public static function ensureSchema($db): void {
        $db->query(
            "CREATE TABLE IF NOT EXISTS `promotion_archives` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `store_hash` VARCHAR(255) NOT NULL,
                `promotion_id` INT NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `custom_field_value` VARCHAR(255) NULL,
                `discount_percent` DECIMAL(5, 2) NOT NULL,
                `start_date` DATETIME NOT NULL,
                `end_date` DATETIME NOT NULL,
                `priority` INT NOT NULL DEFAULT 0,
                `filters` JSON NOT NULL,
                `filters_text` TEXT NULL,
                `status_at_archive` VARCHAR(50) NOT NULL DEFAULT 'expired',
                `color` VARCHAR(20) NULL,
                `description` TEXT NULL,
                `archive_reason` VARCHAR(50) NOT NULL DEFAULT 'expired_cleanup',
                `archived_at` DATETIME NOT NULL,
                `cleanup_completed_at` DATETIME NULL,
                `product_count` INT NOT NULL DEFAULT 0,
                `backfill_status` VARCHAR(50) NOT NULL DEFAULT 'complete',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_store_promotion_archive` (`store_hash`, `promotion_id`),
                INDEX `idx_store_archived_at` (`store_hash`, `archived_at`),
                INDEX `idx_store_period` (`store_hash`, `start_date`, `end_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->query(
            "CREATE TABLE IF NOT EXISTS `promotion_product_history` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `store_hash` VARCHAR(255) NOT NULL,
                `promotion_id` INT NOT NULL,
                `archive_id` BIGINT UNSIGNED NULL,
                `product_id` INT UNSIGNED NOT NULL,
                `variant_id` INT UNSIGNED NULL,
                `cache_id` VARCHAR(255) NULL,
                `product_name` VARCHAR(255) NULL,
                `sku` VARCHAR(255) NULL,
                `type` VARCHAR(20) NULL,
                `original_price` DECIMAL(20, 4) NULL,
                `promo_price` DECIMAL(20, 4) NULL,
                `discount_percent` DECIMAL(5, 2) NULL,
                `custom_field_id` INT UNSIGNED NULL,
                `first_applied_at` DATETIME NULL,
                `omnibus_reference_at` DATETIME NULL,
                `applied_at` DATETIME NOT NULL,
                `last_seen_at` DATETIME NOT NULL,
                `removed_at` DATETIME NULL,
                `removal_reason` VARCHAR(50) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_store_promotion_open` (`store_hash`, `promotion_id`, `removed_at`),
                INDEX `idx_archive_id` (`archive_id`),
                INDEX `idx_store_product_variant` (`store_hash`, `product_id`, `variant_id`),
                INDEX `idx_store_sku` (`store_hash`, `sku`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function recordAppliedItems(array $promotion, array $items, ?int $archiveId = null): void {
        $promotionId = (int)($promotion['id'] ?? $promotion['promotion_id'] ?? 0);
        if ($promotionId <= 0 || empty($items)) {
            return;
        }

        $now = $this->getDatabaseTimestamp();
        $discount = $this->normalizeDecimal($promotion['discount_percent'] ?? null);
        $seen = [];

        foreach ($items as $item) {
            $normalized = $this->normalizeHistoryItem($item, $promotionId, $discount, $now);
            if ($normalized === null) {
                continue;
            }

            $key = $this->itemKey($normalized['product_id'], $normalized['variant_id']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $existing = $this->findOpenHistoryRow(
                $promotionId,
                $normalized['product_id'],
                $normalized['variant_id']
            );

            if ($existing) {
                $this->updateOpenHistoryRow((int)$existing['id'], $normalized, $archiveId);
                continue;
            }

            $this->insertHistoryRow($normalized, $archiveId, null, null);
        }
    }

    public function recordRemovedItems(?int $promotionId, array $items, string $reason, ?string $removedAt = null): void {
        if (empty($items)) {
            return;
        }

        $removedAt = $this->normalizeDate($removedAt, $this->getDatabaseTimestamp());
        $seen = [];

        foreach ($items as $item) {
            $itemPromotionId = (int)($promotionId ?: ($item['promotion_id'] ?? 0));
            if ($itemPromotionId <= 0) {
                continue;
            }

            $normalized = $this->normalizeHistoryItem($item, $itemPromotionId, null, $removedAt);
            if ($normalized === null) {
                continue;
            }

            $key = $itemPromotionId . ':' . $this->itemKey($normalized['product_id'], $normalized['variant_id']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $existing = $this->findOpenHistoryRow(
                $itemPromotionId,
                $normalized['product_id'],
                $normalized['variant_id']
            );

            if ($existing) {
                $this->db->query(
                    "UPDATE promotion_product_history
                     SET removed_at = ?,
                         removal_reason = ?,
                         last_seen_at = GREATEST(last_seen_at, ?),
                         updated_at = NOW()
                     WHERE store_hash = ? AND id = ?",
                    [$removedAt, $reason, $removedAt, $this->storeHash, (int)$existing['id']]
                );
                continue;
            }

            $archiveId = $this->getArchiveIdForPromotion($itemPromotionId);
            $this->insertHistoryRow($normalized, $archiveId, $removedAt, $reason);
        }
    }

    public function finalizeArchive(int $promotionId, string $reason = 'expired_cleanup', ?string $archivedAt = null): ?int {
        if ($promotionId <= 0) {
            return null;
        }

        $promotion = $this->db->fetchOne(
            "SELECT *
             FROM promotions
             WHERE store_hash = ? AND id = ?
             LIMIT 1",
            [$this->storeHash, $promotionId]
        );

        if (!$promotion) {
            return null;
        }

        $archivedAt = $this->normalizeDate($archivedAt, $this->getDatabaseTimestamp());
        $filtersJson = $this->normalizeFiltersJson($promotion['filters'] ?? '{}');
        $filters = json_decode($filtersJson, true);
        $filtersText = $this->buildFiltersText(is_array($filters) ? $filters : []);

        $this->db->query(
            "INSERT INTO promotion_archives
                (store_hash, promotion_id, name, custom_field_value, discount_percent, start_date, end_date,
                 priority, filters, filters_text, status_at_archive, color, description, archive_reason, archived_at,
                 product_count, backfill_status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'complete', NOW())
             ON DUPLICATE KEY UPDATE
                 name = VALUES(name),
                 custom_field_value = VALUES(custom_field_value),
                 discount_percent = VALUES(discount_percent),
                 start_date = VALUES(start_date),
                 end_date = VALUES(end_date),
                 priority = VALUES(priority),
                 filters = VALUES(filters),
                 filters_text = VALUES(filters_text),
                 status_at_archive = VALUES(status_at_archive),
                 color = VALUES(color),
                 description = VALUES(description),
                 updated_at = NOW()",
            [
                $this->storeHash,
                $promotionId,
                (string)($promotion['name'] ?? ''),
                $promotion['custom_field_value'] ?? ($promotion['name'] ?? null),
                $this->normalizeDecimal($promotion['discount_percent'] ?? 0) ?? 0,
                $this->normalizeDate($promotion['start_date'] ?? null, $archivedAt),
                $this->normalizeDate($promotion['end_date'] ?? null, $archivedAt),
                (int)($promotion['priority'] ?? 0),
                $filtersJson,
                $filtersText,
                (string)($promotion['status'] ?? 'expired'),
                $promotion['color'] ?? null,
                $promotion['description'] ?? null,
                $reason,
                $archivedAt,
            ]
        );

        $archiveId = $this->getArchiveIdForPromotion($promotionId, true);
        if ($archiveId === null) {
            return null;
        }

        $currentItems = $this->fetchCurrentPromotionItems($promotionId);
        if (!empty($currentItems)) {
            $this->recordAppliedItems($promotion, $currentItems, $archiveId);
        }

        $this->db->query(
            "UPDATE promotion_product_history
             SET archive_id = ?
             WHERE store_hash = ?
               AND promotion_id = ?
               AND archive_id IS NULL",
            [$archiveId, $this->storeHash, $promotionId]
        );

        $productCount = $this->countDistinctHistoryItems($promotionId);
        $backfillStatus = ($reason === 'backfill' && $productCount === 0) ? 'partial' : 'complete';

        $this->db->query(
            "UPDATE promotion_archives
             SET product_count = ?,
                 backfill_status = ?,
                 updated_at = NOW()
             WHERE store_hash = ? AND id = ?",
            [$productCount, $backfillStatus, $this->storeHash, $archiveId]
        );

        return $archiveId;
    }

    public function markCleanupCompleted(int $promotionId, ?string $completedAt = null): void {
        $archiveId = $this->getArchiveIdForPromotion($promotionId);
        if ($archiveId === null) {
            return;
        }

        $this->db->query(
            "UPDATE promotion_archives
             SET cleanup_completed_at = COALESCE(cleanup_completed_at, ?),
                 updated_at = NOW()
             WHERE store_hash = ? AND id = ?",
            [$this->normalizeDate($completedAt, $this->getDatabaseTimestamp()), $this->storeHash, $archiveId]
        );
    }

    public function backfillExistingExpiredPromotions(int $limit = 500): int {
        $limit = max(1, min(1000, $limit));
        $rows = $this->db->fetchAll(
            "SELECT p.id
             FROM promotions p
             LEFT JOIN promotion_archives pa
                ON pa.store_hash = ?
               AND pa.promotion_id = p.id
             WHERE p.store_hash = ?
               AND pa.id IS NULL
               AND (p.status = 'expired' OR p.end_date < NOW())
             ORDER BY p.end_date DESC, p.id DESC
             LIMIT " . (int)$limit,
            [$this->storeHash, $this->storeHash]
        );

        $processed = 0;
        foreach ($rows as $row) {
            if ($this->finalizeArchive((int)$row['id'], 'backfill') !== null) {
                $processed++;
            }
        }

        return $processed;
    }

    public function hasHistoricalProducts(int $promotionId): bool {
        $row = $this->db->fetchOne(
            "SELECT 1
             FROM promotion_product_history
             WHERE store_hash = ? AND promotion_id = ?
             LIMIT 1",
            [$this->storeHash, $promotionId]
        );

        return !empty($row);
    }

    public function buildFiltersText(array $filters): string {
        $parts = $this->describeFilters($filters);
        return implode('; ', array_values(array_filter($parts, static fn($part) => $part !== '')));
    }

    private function describeFilters(array $filters, string $prefix = ''): array {
        $parts = [];

        foreach ($filters as $key => $value) {
            if ($key === '_manual_unblocked_items') {
                $count = is_array($value) ? count($value) : 0;
                if ($count > 0) {
                    $parts[] = 'Manual cost override items: ' . $count;
                }
                continue;
            }

            if ($key === '_block_below_cost_price') {
                if (!empty($value)) {
                    $parts[] = 'Block below cost price';
                }
                continue;
            }

            if ($key === 'exclude' && is_array($value)) {
                foreach ($this->describeFilters($value, 'Exclude ') as $part) {
                    $parts[] = $part;
                }
                continue;
            }

            $label = $this->filterLabel((string)$key);
            $valueText = $this->filterValueText($value);
            if ($valueText === '') {
                continue;
            }

            $parts[] = $prefix . $label . ': ' . $valueText;
        }

        return $parts;
    }

    private function filterLabel(string $key): string {
        if (strpos($key, 'custom_field:') === 0) {
            return 'Custom field ' . substr($key, 13);
        }

        $labels = [
            'categories:in' => 'Categories',
            'brand_id' => 'Brands',
            'product_id' => 'Product ID',
            'sku' => 'SKU',
            'sku:in' => 'SKUs',
            'name:like' => 'Name contains',
            'price:min' => 'Minimum price',
            'price:max' => 'Maximum price',
            'inventory_level:min' => 'Minimum inventory',
            'is_visible' => 'Visible',
            'is_featured' => 'Featured',
        ];

        return $labels[$key] ?? $key;
    }

    private function filterValueText($value): string {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, static function ($item) use (&$flat): void {
                if ($item !== null && $item !== '') {
                    $flat[] = (string)$item;
                }
            });
            return implode(', ', $flat);
        }

        return trim((string)$value);
    }

    private function fetchCurrentPromotionItems(int $promotionId): array {
        return $this->db->fetchAll(
            "SELECT
                pp.id,
                pp.promotion_id,
                pp.product_id,
                pp.variant_id,
                pp.custom_field_id,
                pp.first_applied_at,
                pp.omnibus_reference_at,
                pp.synced_at,
                pc.id AS cache_id,
                pc.type,
                pc.name AS product_name,
                pc.sku,
                pc.price AS original_price,
                pc.sale_price AS promo_price
             FROM promotion_products pp
             LEFT JOIN products_cache pc
                ON pc.store_hash = pp.store_hash
               AND pc.product_id = pp.product_id
               AND pc.variant_id <=> pp.variant_id
             WHERE pp.store_hash = ?
               AND pp.promotion_id = ?",
            [$this->storeHash, $promotionId]
        );
    }

    private function normalizeHistoryItem(array $item, int $promotionId, ?float $promotionDiscount, string $fallbackAt): ?array {
        $productId = (int)($item['product_id'] ?? 0);
        if ($productId <= 0) {
            return null;
        }

        $variantId = $this->normalizeVariantId($item['variant_id'] ?? null);
        $discount = $this->normalizeDecimal($item['discount_percent'] ?? null) ?? $promotionDiscount;
        $originalPrice = $this->normalizeDecimal($item['original_price'] ?? ($item['price'] ?? null));
        $promoPrice = $this->normalizeDecimal($item['promo_price'] ?? ($item['sale_price'] ?? null));

        if ($promoPrice === null && $originalPrice !== null && $discount !== null) {
            $promoPrice = round($originalPrice * (1 - $discount / 100), 2);
        }

        $appliedAt = $this->normalizeDate(
            $item['applied_at'] ?? ($item['first_applied_at'] ?? ($item['synced_at'] ?? null)),
            $fallbackAt
        );
        $lastSeenAt = $this->normalizeDate($item['last_seen_at'] ?? ($item['synced_at'] ?? null), $appliedAt);

        return [
            'promotion_id' => $promotionId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'cache_id' => isset($item['cache_id']) ? (string)$item['cache_id'] : null,
            'product_name' => $item['product_name'] ?? ($item['name'] ?? null),
            'sku' => $item['sku'] ?? null,
            'type' => $item['type'] ?? ($variantId === null ? 'product' : 'variant'),
            'original_price' => $originalPrice,
            'promo_price' => $promoPrice,
            'discount_percent' => $discount,
            'custom_field_id' => isset($item['custom_field_id']) && $item['custom_field_id'] !== null
                ? (int)$item['custom_field_id']
                : null,
            'first_applied_at' => $this->normalizeDate($item['first_applied_at'] ?? null, null),
            'omnibus_reference_at' => $this->normalizeDate($item['omnibus_reference_at'] ?? null, null),
            'applied_at' => $appliedAt,
            'last_seen_at' => $lastSeenAt,
        ];
    }

    private function updateOpenHistoryRow(int $id, array $item, ?int $archiveId): void {
        $this->db->query(
            "UPDATE promotion_product_history
             SET archive_id = COALESCE(archive_id, ?),
                 cache_id = COALESCE(?, cache_id),
                 product_name = COALESCE(?, product_name),
                 sku = COALESCE(?, sku),
                 type = COALESCE(?, type),
                 original_price = COALESCE(?, original_price),
                 promo_price = COALESCE(?, promo_price),
                 discount_percent = COALESCE(?, discount_percent),
                 custom_field_id = COALESCE(?, custom_field_id),
                 first_applied_at = COALESCE(first_applied_at, ?),
                 omnibus_reference_at = COALESCE(omnibus_reference_at, ?),
                 last_seen_at = GREATEST(last_seen_at, ?),
                 updated_at = NOW()
             WHERE store_hash = ? AND id = ?",
            [
                $archiveId,
                $item['cache_id'],
                $item['product_name'],
                $item['sku'],
                $item['type'],
                $item['original_price'],
                $item['promo_price'],
                $item['discount_percent'],
                $item['custom_field_id'],
                $item['first_applied_at'],
                $item['omnibus_reference_at'],
                $item['last_seen_at'],
                $this->storeHash,
                $id,
            ]
        );
    }

    private function insertHistoryRow(array $item, ?int $archiveId, ?string $removedAt, ?string $removalReason): void {
        $this->db->query(
            "INSERT INTO promotion_product_history
                (store_hash, promotion_id, archive_id, product_id, variant_id, cache_id, product_name, sku, type,
                 original_price, promo_price, discount_percent, custom_field_id, first_applied_at, omnibus_reference_at,
                 applied_at, last_seen_at, removed_at, removal_reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $this->storeHash,
                $item['promotion_id'],
                $archiveId,
                $item['product_id'],
                $item['variant_id'],
                $item['cache_id'],
                $item['product_name'],
                $item['sku'],
                $item['type'],
                $item['original_price'],
                $item['promo_price'],
                $item['discount_percent'],
                $item['custom_field_id'],
                $item['first_applied_at'],
                $item['omnibus_reference_at'],
                $item['applied_at'],
                $item['last_seen_at'],
                $removedAt,
                $removalReason,
            ]
        );
    }

    private function findOpenHistoryRow(int $promotionId, int $productId, ?int $variantId) {
        return $this->db->fetchOne(
            "SELECT id
             FROM promotion_product_history
             WHERE store_hash = ?
               AND promotion_id = ?
               AND product_id = ?
               AND variant_id <=> ?
               AND removed_at IS NULL
             ORDER BY applied_at DESC, id DESC
             LIMIT 1",
            [$this->storeHash, $promotionId, $productId, $variantId]
        );
    }

    private function getArchiveIdForPromotion(int $promotionId, bool $refresh = false): ?int {
        if (!$refresh && array_key_exists($promotionId, $this->archiveIdsByPromotion)) {
            return $this->archiveIdsByPromotion[$promotionId];
        }

        $row = $this->db->fetchOne(
            "SELECT id
             FROM promotion_archives
             WHERE store_hash = ? AND promotion_id = ?
             LIMIT 1",
            [$this->storeHash, $promotionId]
        );

        $this->archiveIdsByPromotion[$promotionId] = $row ? (int)$row['id'] : null;
        return $this->archiveIdsByPromotion[$promotionId];
    }

    private function countDistinctHistoryItems(int $promotionId): int {
        $row = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT CONCAT(product_id, ':', COALESCE(CAST(variant_id AS CHAR), 'parent'))) AS cnt
             FROM promotion_product_history
             WHERE store_hash = ? AND promotion_id = ?",
            [$this->storeHash, $promotionId]
        );

        return (int)($row['cnt'] ?? 0);
    }

    private function normalizeFiltersJson($filters): string {
        if (is_array($filters)) {
            return json_encode($filters, JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        $decoded = json_decode((string)$filters, true);
        if (is_array($decoded)) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        return '{}';
    }

    private function normalizeDate($value, ?string $fallback): ?string {
        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            return (new \DateTimeImmutable((string)$value))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function normalizeDecimal($value): ?float {
        if ($value === null || $value === '') {
            return null;
        }

        $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        return is_numeric($value) ? (float)$value : null;
    }

    private function normalizeVariantId($variantId): ?int {
        if ($variantId === null || $variantId === '') {
            return null;
        }

        return (int)$variantId;
    }

    private function itemKey(int $productId, ?int $variantId): string {
        return $productId . ':' . ($variantId === null ? 'parent' : (string)$variantId);
    }

    private function getDatabaseTimestamp(): string {
        try {
            $row = $this->db->fetchOne("SELECT NOW() AS current_time");
            if (!empty($row['current_time'])) {
                return (new \DateTimeImmutable((string)$row['current_time']))->format('Y-m-d H:i:s');
            }
        } catch (\Throwable $e) {
            // Tests and degraded environments can fall back to PHP time.
        }

        return date('Y-m-d H:i:s');
    }
}
