<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Database;

class OmnibusSyncService {
    private $db;
    private $storeHash;
    private $api;
    private $cacheService;
    private $omnibusFieldService;
    private $omnibusPricingService;
    private $priceLogger;
    private $productsCacheHasType;
    private $promotionsHasOmnibusTermsUpdatedAt;

    private const BATCH_SIZE = 50;

    public function __construct(string $storeHash) {
        $this->storeHash = $storeHash;
        $this->db = Database::getInstance();
        $this->db->setStoreContext($storeHash);

        $this->api = new BigCommerceAPI($storeHash);
        $this->cacheService = new ProductCacheService($this->db);
        $this->omnibusFieldService = new OmnibusFieldService($this->api, $this->db);
        $this->omnibusPricingService = new OmnibusPricingService($this->db);
        $this->priceLogger = new PriceLogger($this->db);
    }

    public function processStore(): array {
        $storeConfig = $this->db->fetchOne(
            "SELECT enable_omnibus FROM bigcommerce_stores WHERE store_hash = ?",
            [$this->storeHash]
        );

        if (!$storeConfig || !$storeConfig['enable_omnibus']) {
            return ['status' => 'skipped', 'message' => 'Omnibus Lowest Price Tracker is disabled for this store.'];
        }

        $totalProductsQuery = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT product_id) as total FROM products_cache WHERE store_hash = ?" . $this->baseProductClause(),
            [$this->storeHash]
        );
        $totalParentProducts = (int)($totalProductsQuery['total'] ?? 0);
        $processedCount = 0;
        $errorCount = 0;

        for ($offset = 0; $offset < $totalParentProducts; $offset += self::BATCH_SIZE) {
            $parentProducts = $this->db->fetchAll(
                "SELECT DISTINCT product_id FROM products_cache WHERE store_hash = ?" . $this->baseProductClause() . " LIMIT ? OFFSET ?",
                [$this->storeHash, self::BATCH_SIZE, $offset]
            );

            if (empty($parentProducts)) {
                break;
            }

            $result = $this->processBatch($parentProducts);
            $processedCount += $result['success'];
            $errorCount += $result['errors'];
        }

        return [
            'status' => 'completed',
            'total_products' => $totalParentProducts,
            'processed' => $processedCount,
            'errors' => $errorCount,
        ];
    }

    public function processBatch(array $parentProducts): array {
        $productIds = [];
        foreach ($parentProducts as $item) {
            if (is_array($item) && isset($item['product_id'])) {
                $productIds[] = (int)$item['product_id'];
            } elseif (is_numeric($item)) {
                $productIds[] = (int)$item;
            }
        }
        $productIds = array_values(array_unique(array_filter($productIds)));

        if (empty($productIds)) {
            return ['success' => 0, 'errors' => 0, 'message' => 'No valid product ids in batch.'];
        }

        $storeConfig = $this->db->fetchOne(
            "SELECT currency FROM bigcommerce_stores WHERE store_hash = ?",
            [$this->storeHash]
        );
        $currency = $storeConfig['currency'] ?? 'USD';

        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        $cachedProducts = $this->db->fetchAll(
            "SELECT product_id, variant_id, type, price, sale_price, custom_fields, cached_at
             FROM products_cache
             WHERE store_hash = ? AND product_id IN ($placeholders)",
            array_merge([$this->storeHash], $productIds)
        );

        if (empty($cachedProducts)) {
            return ['success' => 0, 'errors' => 0, 'message' => 'No cached products found for this batch.'];
        }

        $productsCacheMap = $this->buildParentProductsCacheMap($cachedProducts);
        $productsById = [];
        foreach ($cachedProducts as $product) {
            $productsById[(int)$product['product_id']][] = $product;
        }

        $this->seedInitialPriceHistory($productsById, $currency);
        $promotionReferenceMap = $this->fetchActivePromotionReferenceMap($productIds);
        $priceActivationMap = $this->fetchCurrentPriceActivationMap($productsById, $promotionReferenceMap, $currency);

        $updates = [];

        foreach ($productsById as $productId => $productRows) {
            $updates[] = $this->buildAggregatedUpdateForProduct(
                $productId,
                $productRows,
                $currency,
                $promotionReferenceMap,
                $priceActivationMap
            );
        }

        try {
            $apiResults = $this->omnibusFieldService->batchSyncLowestPriceFields($updates, $productsCacheMap);

            $successCount = 0;
            foreach ($apiResults as $result) {
                if (isset($result['status']) && $result['status'] >= 200 && $result['status'] < 300) {
                    $successCount++;
                }
            }

            return [
                'processed' => count($updates),
                'success' => $successCount,
                'errors' => count($updates) - $successCount,
            ];
        } catch (\Exception $e) {
            error_log("Omnibus Sync API Error for store {$this->storeHash}: " . $e->getMessage());
            return ['processed' => count($updates), 'success' => 0, 'errors' => count($updates)];
        }
    }

    private function buildAggregatedUpdateForProduct(
        int $productId,
        array $productRows,
        string $currency,
        array $promotionReferenceMap = [],
        array $priceActivationMap = []
    ): array {
        $rowsForPricing = $this->selectRowsForPricing($productRows);
        $hasVariantRows = $this->hasVariantRows($rowsForPricing);
        $lowestReference = null;
        $variantReferences = [];
        $lastDto = null;

        foreach ($rowsForPricing as $row) {
            $currentPrice = $this->resolveCurrentPrice($row);
            if ($currentPrice === null || $currentPrice <= 0) {
                continue;
            }

            $variantId = isset($row['variant_id']) && $row['variant_id'] !== null
                ? (int)$row['variant_id']
                : null;
            $referenceAt = $this->getPricingReferenceForRow(
                $productId,
                $variantId,
                $promotionReferenceMap,
                $priceActivationMap,
                $row['cached_at'] ?? null
            );

            $dto = $this->omnibusPricingService->getDisplayData(
                $this->storeHash,
                $productId,
                $variantId,
                $currency,
                $currentPrice,
                $referenceAt,
                [
                    'current_price_observed_at' => $row['cached_at'] ?? null,
                    'require_full_30_days_history' => true,
                ]
            );
            $lastDto = $dto;

            if (empty($dto['is_valid_omnibus_reduction']) || $dto['omnibus_reference_price'] === null) {
                continue;
            }

            $referencePrice = (float)$dto['omnibus_reference_price'];
            if ($hasVariantRows && $variantId !== null) {
                $variantReferences[(string)$variantId] = $referencePrice;
            } elseif ($lowestReference === null || $referencePrice < $lowestReference) {
                $lowestReference = $referencePrice;
            }
        }

        $referenceValue = $hasVariantRows
            ? $this->buildVariantReferencePayload($variantReferences, $currency)
            : $lowestReference;

        return [
            'product_id' => $productId,
            'current_price' => $lastDto['current_price'] ?? null,
            'rolling_lowest_price_last_30_days' => $lastDto['rolling_lowest_price_last_30_days'] ?? null,
            'lowest_price_last_30_days' => $lastDto['lowest_price_last_30_days'] ?? null,
            'is_discounted_now' => $referenceValue !== null,
            'omnibus_reference_price' => $referenceValue,
            'effective_currency' => $lastDto['effective_currency'] ?? $currency,
        ];
    }

    private function hasVariantRows(array $rows): bool {
        foreach ($rows as $row) {
            if (isset($row['variant_id']) && $row['variant_id'] !== null) {
                return true;
            }
        }

        return false;
    }

    private function buildVariantReferencePayload(array $variantReferences, string $currency): ?array {
        if (empty($variantReferences)) {
            return null;
        }

        ksort($variantReferences, SORT_NATURAL);

        return [
            'type' => 'variant_prior_prices',
            'currency' => $currency,
            'values' => $variantReferences,
        ];
    }

    private function fetchCurrentPriceActivationMap(
        array $productsById,
        array $promotionReferenceMap,
        string $currency
    ): array {
        if (empty($productsById) || empty($promotionReferenceMap)) {
            return [];
        }

        $conditions = [];
        $params = [$this->storeHash, $currency];
        $seen = [];

        foreach ($productsById as $productId => $productRows) {
            $productId = (int)$productId;
            foreach ($this->selectRowsForPricing($productRows) as $row) {
                $currentPrice = $this->resolveCurrentPrice($row);
                if ($currentPrice === null || $currentPrice <= 0) {
                    continue;
                }

                $variantId = isset($row['variant_id']) && $row['variant_id'] !== null
                    ? (int)$row['variant_id']
                    : null;
                $promotionReferenceAt = $this->getPromotionReferenceForRow(
                    $productId,
                    $variantId,
                    $promotionReferenceMap
                );
                if ($promotionReferenceAt === null) {
                    continue;
                }

                $price = number_format($currentPrice, 4, '.', '');
                $referenceSql = $promotionReferenceAt->format('Y-m-d H:i:s');
                $dedupeKey = $this->buildPromotionReferenceKey($productId, $variantId)
                    . ':' . $price
                    . ':' . $referenceSql;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $conditions[] = '(product_id = ? AND variant_id <=> ? AND price = ? AND recorded_at >= ?)';
                $params[] = $productId;
                $params[] = $variantId;
                $params[] = $price;
                $params[] = $referenceSql;
            }
        }

        if (empty($conditions)) {
            return [];
        }

        $rows = $this->db->fetchAll(
            "SELECT product_id, variant_id, MIN(recorded_at) AS first_recorded_at
             FROM product_price_history
             WHERE store_hash = ?
               AND currency = ?
               AND (" . implode(' OR ', $conditions) . ")
             GROUP BY product_id, variant_id",
            $params
        );

        $map = [];
        foreach ($rows as $row) {
            if (empty($row['first_recorded_at'])) {
                continue;
            }

            $productId = (int)($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $variantId = isset($row['variant_id']) && $row['variant_id'] !== null
                ? (int)$row['variant_id']
                : null;
            $recordedAt = $this->normalizeOptionalReferenceAt($row['first_recorded_at']);
            if ($recordedAt === null) {
                continue;
            }

            $map[$this->buildPromotionReferenceKey($productId, $variantId)] = $recordedAt;
        }

        return $map;
    }

    private function fetchActivePromotionReferenceMap(array $productIds): array {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($productIds)) {
            return [];
        }

        $termsUpdatedAtSelect = $this->promotionsHasOmnibusTermsUpdatedAt()
            ? 'p.omnibus_terms_updated_at'
            : 'NULL';
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        $now = date('Y-m-d H:i:s');
        $rows = $this->db->fetchAll(
            "SELECT pp.product_id,
                    pp.variant_id,
                    p.start_date,
                    p.created_at,
                    {$termsUpdatedAtSelect} AS omnibus_terms_updated_at
             FROM promotion_products pp
             INNER JOIN promotions p
                ON p.store_hash = pp.store_hash
               AND p.id = pp.promotion_id
             WHERE pp.store_hash = ?
               AND pp.product_id IN ($placeholders)
               AND p.status = 'active'
               AND p.start_date <= ?
               AND p.end_date >= ?
             ORDER BY pp.product_id ASC, pp.variant_id ASC, pp.synced_at DESC, pp.id DESC",
            array_merge([$this->storeHash], $productIds, [$now, $now])
        );

        $map = [];
        foreach ($rows as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $variantId = isset($row['variant_id']) && $row['variant_id'] !== null
                ? (int)$row['variant_id']
                : null;
            $key = $this->buildPromotionReferenceKey($productId, $variantId);
            if (isset($map[$key])) {
                continue;
            }

            $map[$key] = $this->resolvePromotionReferenceAt($row);
        }

        return $map;
    }

    private function getPromotionReferenceForRow(
        int $productId,
        ?int $variantId,
        array $promotionReferenceMap
    ): ?\DateTimeImmutable {
        $exactKey = $this->buildPromotionReferenceKey($productId, $variantId);
        if (isset($promotionReferenceMap[$exactKey])) {
            return $promotionReferenceMap[$exactKey];
        }

        $parentKey = $this->buildPromotionReferenceKey($productId, null);
        return $promotionReferenceMap[$parentKey] ?? null;
    }

    private function getPricingReferenceForRow(
        int $productId,
        ?int $variantId,
        array $promotionReferenceMap,
        array $priceActivationMap,
        $currentPriceObservedAt = null
    ): ?\DateTimeImmutable {
        $exactKey = $this->buildPromotionReferenceKey($productId, $variantId);
        if (isset($priceActivationMap[$exactKey])) {
            return $priceActivationMap[$exactKey];
        }

        $promotionReferenceAt = $this->getPromotionReferenceForRow($productId, $variantId, $promotionReferenceMap);
        $observedAt = $this->normalizeOptionalReferenceAt($currentPriceObservedAt);
        if (
            $promotionReferenceAt !== null
            && $observedAt !== null
            && $observedAt > $promotionReferenceAt
        ) {
            return $observedAt;
        }

        return $promotionReferenceAt;
    }

    private function buildPromotionReferenceKey(int $productId, ?int $variantId): string {
        return $productId . ':' . ($variantId === null ? 'base' : (string)$variantId);
    }

    private function resolvePromotionReferenceAt(array $promotion): \DateTimeImmutable {
        $dates = [];
        foreach (['start_date', 'created_at', 'omnibus_terms_updated_at'] as $field) {
            $dateTime = $this->normalizeOptionalReferenceAt($promotion[$field] ?? null);
            if ($dateTime !== null) {
                $dates[] = $dateTime;
            }
        }

        $latest = null;
        foreach ($dates as $dateTime) {
            if ($latest === null || $dateTime > $latest) {
                $latest = $dateTime;
            }
        }

        return $latest ?? new \DateTimeImmutable('now');
    }

    private function normalizeOptionalReferenceAt($dateTime): ?\DateTimeImmutable {
        if ($dateTime === null || $dateTime === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable((string)$dateTime);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function selectRowsForPricing(array $productRows): array {
        $variantRows = array_values(array_filter($productRows, static function (array $row): bool {
            return isset($row['variant_id']) && $row['variant_id'] !== null;
        }));

        return !empty($variantRows) ? $variantRows : $productRows;
    }

    private function resolveCurrentPrice(array $product): ?float {
        if (isset($product['sale_price']) && $product['sale_price'] !== null && (float)$product['sale_price'] > 0) {
            return (float)$product['sale_price'];
        }

        return isset($product['price']) ? (float)$product['price'] : null;
    }

    private function seedInitialPriceHistory(array $productsById, string $currency): void {
        $candidates = [];

        foreach ($productsById as $productId => $productRows) {
            foreach ($this->selectRowsForPricing($productRows) as $row) {
                $seedPrice = $this->resolveInitialHistoryPrice($row);
                if ($seedPrice === null || $seedPrice <= 0) {
                    continue;
                }

                $variantId = isset($row['variant_id']) && $row['variant_id'] !== null
                    ? (int)$row['variant_id']
                    : null;
                $seedRecordedAt = $this->resolveInitialHistoryRecordedAt($row['cached_at'] ?? null);

                $candidates[] = [
                    'product_id' => (int)$productId,
                    'variant_id' => $variantId,
                    'price' => $seedPrice,
                    'currency' => $currency,
                    'recorded_at' => $seedRecordedAt,
                ];
            }
        }

        if (!empty($candidates)) {
            $this->priceLogger->seedInitialPriceHistoryBatch($this->storeHash, $candidates);
        }
    }

    private function resolveInitialHistoryPrice(array $product): ?float {
        $regularPrice = isset($product['price']) && is_numeric($product['price'])
            ? (float)$product['price']
            : null;
        $salePrice = isset($product['sale_price']) && is_numeric($product['sale_price'])
            ? (float)$product['sale_price']
            : null;

        if ($salePrice !== null && $salePrice > 0) {
            return $regularPrice !== null && $regularPrice > 0 ? $regularPrice : $salePrice;
        }

        return $regularPrice;
    }

    private function resolveInitialHistoryRecordedAt($cachedAt): string {
        try {
            $observedAt = $cachedAt
                ? new \DateTimeImmutable((string)$cachedAt)
                : new \DateTimeImmutable('now');
        } catch (\Throwable $e) {
            $observedAt = new \DateTimeImmutable('now');
        }

        return $observedAt->sub(new \DateInterval('P30D'))->format('Y-m-d H:i:s');
    }

    private function buildParentProductsCacheMap(array $cachedProducts): array {
        $map = [];

        foreach ($cachedProducts as $product) {
            $productId = (int)$product['product_id'];
            $isParent = empty($product['variant_id']) || ($product['type'] ?? null) === 'product';
            if ($isParent || !isset($map[$productId])) {
                $map[$productId] = $product;
            }
        }

        return $map;
    }

    private function baseProductClause(): string {
        return $this->productsCacheHasType() ? " AND type = 'product'" : '';
    }

    private function productsCacheHasType(): bool {
        if ($this->productsCacheHasType !== null) {
            return $this->productsCacheHasType;
        }

        try {
            $column = $this->db->fetchOne("SHOW COLUMNS FROM products_cache LIKE 'type'");
            $this->productsCacheHasType = $column !== false && $column !== null;
        } catch (\Throwable $e) {
            $this->productsCacheHasType = false;
        }

        return $this->productsCacheHasType;
    }

    private function promotionsHasOmnibusTermsUpdatedAt(): bool {
        if ($this->promotionsHasOmnibusTermsUpdatedAt !== null) {
            return $this->promotionsHasOmnibusTermsUpdatedAt;
        }

        try {
            $column = $this->db->fetchOne("SHOW COLUMNS FROM promotions LIKE 'omnibus_terms_updated_at'");
            $this->promotionsHasOmnibusTermsUpdatedAt = $column !== false && $column !== null;
        } catch (\Throwable $e) {
            $this->promotionsHasOmnibusTermsUpdatedAt = false;
        }

        return $this->promotionsHasOmnibusTermsUpdatedAt;
    }
}
