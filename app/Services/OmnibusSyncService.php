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
    private $productsCacheHasType;

    private const BATCH_SIZE = 50;

    public function __construct(string $storeHash) {
        $this->storeHash = $storeHash;
        $this->db = Database::getInstance();
        $this->db->setStoreContext($storeHash);

        $this->api = new BigCommerceAPI($storeHash);
        $this->cacheService = new ProductCacheService($this->db);
        $this->omnibusFieldService = new OmnibusFieldService($this->api, $this->db);
        $this->omnibusPricingService = new OmnibusPricingService($this->db);
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

        $updates = [];

        foreach ($productsById as $productId => $productRows) {
            $updates[] = $this->buildAggregatedUpdateForProduct($productId, $productRows, $currency);
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

    private function buildAggregatedUpdateForProduct(int $productId, array $productRows, string $currency): array {
        $rowsForPricing = $this->selectRowsForPricing($productRows);
        $lowestReference = null;
        $lastDto = null;

        foreach ($rowsForPricing as $row) {
            $currentPrice = $this->resolveCurrentPrice($row);
            if ($currentPrice === null || $currentPrice <= 0) {
                continue;
            }

            $variantId = isset($row['variant_id']) && $row['variant_id'] !== null
                ? (int)$row['variant_id']
                : null;

            $dto = $this->omnibusPricingService->getDisplayData(
                $this->storeHash,
                $productId,
                $variantId,
                $currency,
                $currentPrice,
                null,
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
            if ($lowestReference === null || $referencePrice < $lowestReference) {
                $lowestReference = $referencePrice;
            }
        }

        return [
            'product_id' => $productId,
            'current_price' => $lastDto['current_price'] ?? null,
            'rolling_lowest_price_last_30_days' => $lastDto['rolling_lowest_price_last_30_days'] ?? null,
            'lowest_price_last_30_days' => $lastDto['lowest_price_last_30_days'] ?? null,
            'is_discounted_now' => $lowestReference !== null,
            'omnibus_reference_price' => $lowestReference,
            'effective_currency' => $lastDto['effective_currency'] ?? $currency,
        ];
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
}
