<?php
namespace App\Services;

use App\Models\Promotion;
use App\Models\Database;
use App\Services\QueueService;

class PromotionService {
    private $promotionModel;
    private $api;
    private $customFieldService;
    private $db;
    private $cacheService;
    private $storeHash;
    private $omnibusPricingService;
    private $archiveService = null;
    private $storeConfigCache = null;
    private $priceHistoryHasVariantId;
    private const API_PRODUCT_LIMIT = 250;
    private const BLOCK_BELOW_COST_PRICE_FILTER_KEY = '_block_below_cost_price';
    private const MANUAL_UNBLOCK_FILTER_KEY = '_manual_unblocked_items';
    private const COST_PRICE_BLOCK_ENABLED_ITEM_FLAG = '_cost_price_block_enabled';
    private const MANUAL_UNBLOCK_ITEM_FLAG = '_manual_cost_price_unblock';
    private const CHANGE_TYPE_STANDARD = 'standard';
    private const CHANGE_TYPE_ACTIVE_DISCOUNT_CORRECTION = 'active_discount_correction';
    private const MAX_CORRECTION_REASON_LENGTH = 1000;
    
public function __construct(Database $db = null) {
        $this->promotionModel = new Promotion();
        $this->api = new BigCommerceAPI();
        $this->db = $db ?? Database::getInstance();
        $this->customFieldService = new CustomFieldService($this->api, $this->db);
        
        $this->storeHash = $this->db->getStoreContext();
        
        if (!$this->storeHash) {
            throw new \Exception("Store context required");
        }
        
        // ISPRAVKA: Prosleđujemo DB instancu, a ne storeHash, da bi se delila konekcija.
        $this->cacheService = new ProductCacheService($this->db);
        $this->omnibusPricingService = new OmnibusPricingService($this->db);
        $this->archiveService = new PromotionArchiveService($this->db, $this->storeHash);
    }
    
    /**
     * Kreira novu promociju i zakazuje sync samo ako promocija pocinje odmah.
     */
    public function createPromotion(array $data) {
        $data['discount_percent'] = $this->validateDiscountPercent($data['discount_percent'] ?? null);
        $status = $this->determinePromotionStatus($data['start_date'] ?? null, $data['end_date'] ?? null);

        // 1. Unos promocije u bazu
        $sql = "INSERT INTO promotions (store_hash, name, custom_field_value, discount_percent, start_date, end_date, priority, filters, status, color, description, created_at, omnibus_terms_updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $filtersJson = is_array($data['filters']) ? json_encode($data['filters']) : $data['filters'];
        
        $this->db->query($sql, [
            $this->storeHash,
            $data['name'],
            $data['custom_field_value'] ?? $data['name'],
            $data['discount_percent'],
            $data['start_date'],
            $data['end_date'],
            $data['priority'] ?? 0,
            $filtersJson,
            $status,
            $data['color'] ?? '#3b82f6',
            $data['description'] ?? ''
        ]);
        
        $promotionId = $this->db->lastInsertId();
        
        if ($status === 'active') {
            $this->queueActivationJobsForPromotion($promotionId, $data['filters'] ?? []);
        }
        
        return $promotionId;
    }

    public function updatePromotion($promotionId, array $data, array $context = []) {
        $existingPromotion = $this->promotionModel->findById($promotionId);
        if (!$existingPromotion) {
            throw new \InvalidArgumentException("Promotion not found.");
        }

        $data['discount_percent'] = $this->validateDiscountPercent($data['discount_percent'] ?? null);
        $status = $this->determinePromotionStatus($data['start_date'] ?? null, $data['end_date'] ?? null);
        $data['status'] = $status;
        $syncRelevantChanges = $this->hasPromotionSyncRelevantChanges($existingPromotion, $data);
        $omnibusTermsChanged = $this->hasPromotionOmnibusTermsChanged($existingPromotion, $data);
        $correctionRevision = $this->validateActiveDiscountCorrectionRequest(
            $existingPromotion,
            $data,
            $context
        );
        if ($omnibusTermsChanged && $correctionRevision === null) {
            $data['omnibus_terms_updated_at'] = date('Y-m-d H:i:s');
        }

        $result = $correctionRevision === null
            ? $this->promotionModel->update($promotionId, $data)
            : $this->savePromotionUpdateWithCorrectionRevision($promotionId, $data, $correctionRevision);

        if ($status === 'active' && $syncRelevantChanges) {
            $this->queueActivationJobsForPromotion($promotionId, $data['filters'] ?? []);
        } else {
            $appliedProducts = $this->countPromotionProducts($promotionId);
            if ($this->shouldQueueCleanupAfterPromotionUpdate($status, $appliedProducts)) {
                $this->queuePromotionCleanup($promotionId, $appliedProducts);
            } elseif ($status === 'expired') {
                $this->finalizePromotionArchive((int)$promotionId, 'promotion_update');
            }
        }

        return $result;
    }

    public function deletePromotion($promotionId) {
        $promotion = $this->promotionModel->findById($promotionId);
        if (!$promotion) {
            return false;
        }

        $totalItems = $this->countPromotionProducts($promotionId);

        if ($totalItems > 0) {
            $this->finalizePromotionArchive((int)$promotionId, 'manual_delete');
            $this->queuePromotionCleanup($promotionId, $totalItems);
            return $this->promotionModel->update($promotionId, ['status' => 'expired']);
        }

        if ($this->shouldArchivePromotionBeforeDelete($promotion, $totalItems)) {
            $this->finalizePromotionArchive((int)$promotionId, 'manual_delete');
        }

        return $this->promotionModel->delete($promotionId);
    }

    public function queuePromotionCleanup($promotionId, ?int $totalItems = null, bool $queueOmnibus = true): array {
        $totalItems = $totalItems ?? $this->countPromotionProducts($promotionId);
        if ($totalItems <= 0) {
            return ['created' => false, 'job_id' => null, 'total' => 0];
        }

        $existingJob = $this->findOpenJob('cleanup_single', $promotionId);
        if ($existingJob) {
            return [
                'created' => false,
                'job_id' => (int)$existingJob['id'],
                'total' => (int)$existingJob['total_items'],
            ];
        }

        $queue = new QueueService($this->storeHash);
        $jobId = $queue->createJob('cleanup_single', $promotionId, $totalItems > 0 ? $totalItems : 1);

        return ['created' => true, 'job_id' => (int)$jobId, 'total' => $totalItems];
    }

    private function queueActivationJobsForPromotion($promotionId, $filters): void {
        $queue = new QueueService($this->storeHash);

        $this->queueJobForPromotion($queue, $promotionId, $filters);
    }

    private function queueJobForPromotion(QueueService $queue, $promotionId, $filters): void {
        $filtersArray = is_array($filters) ? $filters : json_decode($filters, true);
        if (!is_array($filtersArray)) {
            $filtersArray = [];
        }

        $totalItems = $this->cacheService->countProductsByFilters($filtersArray);

        // Kreiramo posao odmah
        $queue->createJobIfNotOpen('sync_promotion', $promotionId, $totalItems > 0 ? $totalItems : 1);
    }

    private function queueOmnibusSyncJobIfEnabled(QueueService $queue): void {
        $storeConfig = $this->db->fetchOne(
            "SELECT enable_omnibus FROM bigcommerce_stores WHERE store_hash = ?",
            [$this->storeHash]
        );

        if (!$storeConfig || empty($storeConfig['enable_omnibus'])) {
            return;
        }

        $queue->createOmnibusSyncJob($this->countOmnibusParentProducts());
    }

    private function countOmnibusParentProducts(): int {
        $typeColumn = $this->db->fetchOne("SHOW COLUMNS FROM products_cache LIKE 'type'");
        $baseProductClause = $typeColumn ? " AND type = 'product'" : '';
        $row = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT product_id) AS total FROM products_cache WHERE store_hash = ?" . $baseProductClause,
            [$this->storeHash]
        );

        return (int)($row['total'] ?? 0);
    }

    private function countPromotionProducts($promotionId): int {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM promotion_products WHERE store_hash = ? AND promotion_id = ?",
            [$this->storeHash, $promotionId]
        );

        return (int)($row['cnt'] ?? 0);
    }

    private function countAllPromotionProducts(): int {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM promotion_products WHERE store_hash = ?",
            [$this->storeHash]
        );

        return (int)($row['cnt'] ?? 0);
    }

    private function findOpenJob(string $jobType, $promotionId = null) {
        return $this->db->fetchOne(
            "SELECT *
             FROM sync_jobs
             WHERE store_hash = ?
               AND job_type = ?
               AND promotion_id <=> ?
               AND status IN ('pending', 'processing')
             ORDER BY created_at ASC, id ASC
             LIMIT 1",
            [$this->storeHash, $jobType, $promotionId]
        );
    }

    private function findOpenJobByType(string $jobType) {
        return $this->db->fetchOne(
            "SELECT *
             FROM sync_jobs
             WHERE store_hash = ?
               AND job_type = ?
               AND status IN ('pending', 'processing')
             ORDER BY created_at ASC, id ASC
             LIMIT 1",
            [$this->storeHash, $jobType]
        );
    }

    private function determinePromotionStatus($startDate, $endDate): string {
        $now = time();
        $startTimestamp = strtotime((string)$startDate);
        $endTimestamp = strtotime((string)$endDate);

        if ($endTimestamp !== false && $endTimestamp < $now) {
            return 'expired';
        }

        if ($startTimestamp !== false && $startTimestamp > $now) {
            return 'scheduled';
        }

        return 'active';
    }

    private function shouldQueueCleanupAfterPromotionUpdate(string $status, int $appliedProducts): bool {
        return $status !== 'active' && $appliedProducts > 0;
    }

    /**
     * Umesto da sinhronizuje sve odjednom, ova metoda kreira Job-ove
     * za svaku aktivnu promociju pojedinačno.
     */
    public function queueAllPromotions() {
        // 1. Održavanje: Ažuriraj statuse i obriši istekle
        $queue = new QueueService($this->storeHash);
        $this->promotionModel->updateStatuses();
        
        $promotions = $this->promotionModel->findActive();
        $expiredCleanupJobs = 0;
        $jobsCreated = 0;
        $jobsSkipped = 0;

        if (empty($promotions)) {
            // Ako nema promocija, kreiraj posao za čišćenje svega
            $totalItems = $this->countAllPromotionProducts();
            if ($totalItems > 0 && !$this->findOpenJobByType('cleanup_single') && !$this->findOpenJob('cleanup')) {
                $queue->createJob('cleanup', null, $totalItems);
                $jobsCreated++;
            }
            return ['message' => 'Nema aktivnih promocija. Zakazan posao čišćenja.', 'jobs' => $jobsCreated];
        }

        $expiredCleanupJobs = $this->queueExpiredPromotionCleanupJobs($queue);
        $jobsCreated += $expiredCleanupJobs;

        foreach ($promotions as $promo) {
            $filters = json_decode($promo['filters'], true);
            $totalItems = $this->cacheService->countProductsByFilters($filters);
            
            // Kreiramo posao čak i ako je 0, da bi worker mogao da evidentira prolaz
            $jobResult = $queue->createJobIfNotOpen('sync_promotion', $promo['id'], $totalItems > 0 ? $totalItems : 1);
            if (!empty($jobResult['created'])) {
                $jobsCreated++;
            } else {
                $jobsSkipped++;
            }
        }

        return [
            'message' => "Uspešno zakazano {$jobsCreated} poslova sinhronizacije. Preskočeno postojećih: {$jobsSkipped}.",
            'jobs' => $jobsCreated,
            'skipped' => $jobsSkipped
        ];
    }

    /**
     * Zakazuje cleanup jobove za istekle promocije koje jos imaju vezane proizvode.
     */
    private function queueExpiredPromotionCleanupJobs(QueueService $queue): int {
        $expiredPromotions = $this->db->fetchAll(
            "SELECT p.id, COUNT(pp.id) AS total_items
             FROM promotions p
             INNER JOIN promotion_products pp
                ON pp.store_hash = p.store_hash
               AND pp.promotion_id = p.id
             WHERE p.store_hash = ?
               AND p.status = 'expired'
               AND p.end_date < NOW()
             GROUP BY p.id",
            [$this->storeHash]
        );

        $jobsCreated = 0;
        foreach ($expiredPromotions as $promotion) {
            $totalItems = (int)($promotion['total_items'] ?? 0);
            if ($totalItems <= 0 || $this->findOpenJob('cleanup_single', $promotion['id'])) {
                continue;
            }

            $queue->createJob('cleanup_single', $promotion['id'], $totalItems);
            $jobsCreated++;
        }

        return $jobsCreated;
    }

    /**
     * Sync all promotions using LOCAL cache (fast!)
     */
    public function syncAllPromotions() {
        $startTime = microtime(true);
        $debugLog = [];
        
        $debugLog[] = "=== Starting sync at " . date('Y-m-d H:i:s') . " ===";
        $debugLog[] = "Mode: LOCAL CACHE (Fast)";
        
        // Update promotion statuses
        $expiredCleanupResult = $this->updateExpiredPromotions();
        $expiredCleanedCount = (int)($expiredCleanupResult['processed'] ?? 0);
        if ($expiredCleanedCount > 0) {
            $debugLog[] = "Cleaned {$expiredCleanedCount} products from expired promotions";
        }
        
        $this->promotionModel->updateStatuses();
        
        $promotions = $this->promotionModel->findActive();
        $debugLog[] = "Found " . count($promotions) . " active promotions";
        
        if (empty($promotions)) {
            $debugLog[] = "No active promotions - cleaning up all promotional products";
            $cleanupResult = $this->cleanupAllProductsBatch();
            $cleanedCount = (int)($cleanupResult['processed'] ?? 0);
            $debugLog[] = "Cleaned {$cleanedCount} products";

            if (($cleanedCount + $expiredCleanedCount) > 0) {
                $this->queueOmnibusSyncJobIfEnabled(new QueueService($this->storeHash));
            }
            
            $duration = microtime(true) - $startTime;
            $message = implode("\n", $debugLog);
            $this->logSync(null, 0, 0, $duration, $message, 'full');
            
            return [
                'promotions' => 0,
                'products' => 0,
                'success' => 0,
                'errors' => 0,
                'cleaned' => $cleanedCount,
                'duration' => round($duration, 2),
                'debug' => $debugLog,
                'message' => 'Sve promocije su očišćene. Uklonjeno ' . $cleanedCount . ' proizvoda.'
            ];
        }
        
        // Map: product/variant key => [promotion details]
        $productPromotions = [];
        $existingFieldsMap = [];
        $omnibusSkippedCount = 0;
        
        // Process each promotion using LOCAL CACHE
        foreach ($promotions as $promotion) {
            $debugLog[] = "\n--- Processing: {$promotion['name']} (ID: {$promotion['id']}) ---";
            
            $filters = json_decode($promotion['filters'], true) ?: [];
            $debugLog[] = "Filters: " . json_encode($filters);
            
            $filterStart = microtime(true);
            
            // FAST: Get products from local cache instead of BigCommerce API
            $products = $this->cacheService->getProductsByFilters($filters);
            
            $filterDuration = round((microtime(true) - $filterStart) * 1000, 2);
            $debugLog[] = "✓ Found " . count($products) . " products from cache in {$filterDuration}ms";
            
            foreach ($products as $product) {
                if (empty($product['variant_id'])) {
                    $existingFieldsMap[$product['product_id']] = is_string($product['custom_fields'])
                        ? json_decode($product['custom_fields'], true)
                        : $product['custom_fields'];
                }

                $candidate = $this->buildPromotionCandidate($product, $promotion);
                if (!$candidate || empty($candidate['will_apply'])) {
                    if ($candidate && ($candidate['omnibus_status'] ?? '') === 'invalid') {
                        $omnibusSkippedCount++;
                    }
                    continue;
                }

                $itemKey = $this->getPromotionItemKey($candidate['product_id'], $candidate['variant_id'] ?? null);

                if (!isset($productPromotions[$itemKey]) || 
                    $this->isBetterPromotion($candidate, $productPromotions[$itemKey])) {
                    $productPromotions[$itemKey] = $candidate;
                }
            }
        }
        
        $debugLog[] = "\n=== Batch Processing to BigCommerce ===";
        $debugLog[] = "Total products to update: " . count($productPromotions);
        if ($omnibusSkippedCount > 0) {
            $debugLog[] = "Skipped {$omnibusSkippedCount} products because promo price is not below the Omnibus reference price.";
        }
        
        // BATCH UPDATE: Apply sale prices to BigCommerce
        [$productUpdates, $variantUpdates] = $this->buildPriceUpdateBatches($productPromotions);
        
        $productPriceResults = !empty($productUpdates) ? $this->api->batchUpdateProducts($productUpdates) : [];
        $variantPriceResults = !empty($variantUpdates) ? $this->api->batchUpdateVariants($variantUpdates) : [];
        $priceResults = array_merge($productPriceResults, $variantPriceResults);
        $appliedPromotions = $this->filterPromotionsWithSuccessfulPriceUpdates($productPromotions, $priceResults);
        $successCount = count($appliedPromotions);
        $errorCount = count($productPromotions) - $successCount;
        
        $debugLog[] = "Price updates: {$successCount} success, {$errorCount} errors";
        
        // BATCH UPDATE: Custom fields (KORIŠĆENJE MULTI CURLA)
        $customFieldUpdates = $this->buildUniquePromotionFieldUpdates($appliedPromotions);
        
        // 🚀 IZMENA: Korišćenje multi-cURL (batch) metode
        // OPTIMIZACIJA: Prosleđujemo custom fields iz keša da izbegnemo GET requestove
        // OPTIMIZACIJA: Dohvatanje poznatih ID-eva iz promotion_products tabele (Backup ako je keš zastareo)
        
        $knownFieldIds = $this->getKnownPromotionFieldIds(
            array_values(array_unique(array_column($appliedPromotions, 'product_id')))
        );
        $fieldResults = $this->customFieldService->upsertCustomFields($customFieldUpdates, $existingFieldsMap, $knownFieldIds);

        $fieldIdMap = [];
        foreach ($fieldResults as $res) {
            if (!empty($res['success']) && !empty($res['custom_field_id'])) { 
                $fieldIdMap[$res['product_id']] = $res['custom_field_id'];
            }
        }
        
        foreach ($appliedPromotions as &$promo) {
            $pid = $promo['product_id'];
            $promo['custom_field_id'] = $fieldIdMap[$pid] ?? null;
        }
        unset($promo);

        $fieldSuccess = count(array_filter($fieldResults, fn($r) => $r['success']));
        $debugLog[] = "Custom field updates (Multi-cURL): {$fieldSuccess} success";
        
        // 🚀 IZMENA: Zameniti petlju za individualne INSERT-e jednim BATCH INSERT-om
        $promotionAppliedAt = $this->getDatabaseTimestamp();
        $this->batchSavePromotionProducts($appliedPromotions, $promotionAppliedAt);
        $debugLog[] = "Database records saved/updated in batch.";
        
        // Cleanup expired products (sada će koristiti batch metode)
        $cleanupResult = $this->cleanupExpiredProductsBatch($productPromotions);
        $cleanedCount = (int)($cleanupResult['processed'] ?? 0);
        $debugLog[] = "Cleaned {$cleanedCount} expired products";
        
        // 🚀 OPTIMIZACIJA: Direktno ažuriranje lokalnog keša (bez API poziva)
        $cachePriceUpdates = $this->buildPromotionCachePriceUpdates($appliedPromotions, $promotionAppliedAt);
        $this->cacheService->updatePriceCacheDirectly($cachePriceUpdates);

        if (($cleanedCount + $expiredCleanedCount) > 0) {
            $this->queueOmnibusSyncJobIfEnabled(new QueueService($this->storeHash));
        }
        
        // Log sync
        $duration = microtime(true) - $startTime;
        $debugLog[] = "\n=== Sync completed in " . round($duration, 2) . "s ===";
        
        $this->logSync(null, $successCount, $errorCount, $duration, implode("\n", $debugLog), 'full');
        
        return [
            'promotions' => count($promotions),
            'products' => count($productPromotions),
            'success' => $successCount,
            'errors' => $errorCount,
            'cleaned' => $cleanedCount + $expiredCleanedCount,
            'duration' => round($duration, 2),
            'debug' => $debugLog,
            'message' => sprintf(
                'Sinhronizovano %d promocija sa %d proizvoda. Očišćeno: %d.',
                count($promotions),
                $successCount,
                $cleanedCount + $expiredCleanedCount
            )
        ];
    }
    
    private function updateCacheForProductsBatch($productIds) {
        if (empty($productIds)) {
            return;
        }

        $idsString = implode(',', $productIds);
        
        try {
            // JEDAN API POZIV za sve proizvode, koristeći BigCommerce filter `id:in`
            $response = $this->api->call('GET', "catalog/products?id:in={$idsString}&include=variants,images,custom_fields&limit=" . self::API_PRODUCT_LIMIT);
            
            $updatedProducts = $response['body']['data'] ?? [];
            
            if (!empty($updatedProducts)) {
                $this->cacheService->batchCacheProducts($updatedProducts); 
            }
        } catch (\Exception $e) {
            error_log("Error updating cache in batch: " . $e->getMessage());
        }
    }
    
    /**
     * Preview products that match promotion filters (WITHOUT applying promotion)
     */
    public function previewPromotionProducts($filters, $discountPercent, $referenceAt = null, array $context = []) {
        $discountPercent = $this->validateDiscountPercent($discountPercent);
        $costPriceBlockEnabled = $this->isCostPriceBlockingEnabledFromFilters($filters);
        $manualUnblockedItems = $this->getManualUnblockedItemKeysFromFilters($filters);
        $previewContext = $context + [
            'filters' => $filters,
            'discount_percent' => $discountPercent,
            'start_date' => $referenceAt,
        ];
        $existingPreviewPromotion = $this->findPromotionFromPreviewContext($previewContext);
        $skipOmnibusRevalidation = is_array($existingPreviewPromotion)
            && (
                !$this->hasPromotionOmnibusTermsChanged($existingPreviewPromotion, $previewContext)
                || $this->isActiveDiscountCorrectionPreview($existingPreviewPromotion, $previewContext)
            );
        $existingPromotionReferenceAt = is_array($existingPreviewPromotion)
            ? $this->resolvePromotionOmnibusReferenceAt($existingPreviewPromotion)
            : null;
        $newPreviewItemReferenceAt = $this->resolveNewPreviewItemOmnibusReferenceAt($referenceAt);
        // getProductsByFilters sada vraća i proizvode i varijante
        $items = $this->cacheService->getProductsByFilters($filters);
        
        $preview = [];
        foreach ($items as $item) {
            $item[self::COST_PRICE_BLOCK_ENABLED_ITEM_FLAG] = $costPriceBlockEnabled;
            $item[self::MANUAL_UNBLOCK_ITEM_FLAG] = $this->isItemManuallyUnblocked($item, $manualUnblockedItems);
            $skipItemOmnibusRevalidation = $skipOmnibusRevalidation
                && $this->isExistingPromotionProductCurrentForTerms($item, $existingPreviewPromotion);
            $omnibusReferenceAt = $skipItemOmnibusRevalidation && $existingPromotionReferenceAt !== null
                ? $existingPromotionReferenceAt
                : $newPreviewItemReferenceAt;
            $row = $this->buildPromotionPreviewRow(
                $item,
                $discountPercent,
                $omnibusReferenceAt,
                true,
                $skipItemOmnibusRevalidation
            );
            if ($row !== null) {
                $preview[] = $row;
            }
        }
        
        return [
            'total_products' => count($preview),
            'total_invalid_products' => count(array_filter($preview, fn($row) => empty($row['will_apply']))),
            'total_savings' => array_sum(array_column($preview, 'savings')),
            'products' => $preview
        ];
    }
    
    /**
     * Get statistics about filters
     */
    public function getFilterStats($filters) {
        $products = $this->cacheService->getProductsByFilters($filters);
        
        $totalValue = array_sum(array_column($products, 'price'));
        $avgPrice = count($products) > 0 ? $totalValue / count($products) : 0;
        
        return [
            'total_products' => count($products),
            'total_inventory' => array_sum(array_column($products, 'inventory_level')),
            'total_value' => round($totalValue, 2),
            'average_price' => round($avgPrice, 2),
            'visible_products' => count(array_filter($products, fn($p) => $p['is_visible'])),
            'featured_products' => count(array_filter($products, fn($p) => $p['is_featured'])),
        ];
    }
    
    /**
     * Update cache for specific products after BigCommerce update
     */
    private function updateCacheForProducts($productIds) {
        foreach ($productIds as $productId) {
            try {
                // Fetch updated data from BigCommerce
                $response = $this->api->call('GET', "catalog/products/{$productId}?include=custom_fields");
                $product = $response['body']['data'] ?? null;
                
                if ($product) {
                    $this->cacheService->batchCacheProducts([$product]);
                }
                
            } catch (\Exception $e) {
                error_log("Error updating cache for product {$productId}: " . $e->getMessage());
            }
        }
    }
    
    private function updateExpiredPromotions(): array {
        $now = date('Y-m-d H:i:s');
        $cleanedProductsCount = 0;
        $omnibusProductIds = [];
        
        $expiredPromotions = $this->db->fetchAll(
            "SELECT id, name FROM promotions WHERE store_hash = ? AND status = 'active' AND end_date < ?",
            [$this->storeHash, $now]
        );
        
        $allItemsToClean = [];

        foreach ($expiredPromotions as $promo) {
            $items = $this->fetchPromotionProductsWithCachePrice(
                "pp.promotion_id = ? AND pp.store_hash = ?",
                [$promo['id'], $this->storeHash]
            );
            
            if (!empty($items)) {
                $this->finalizePromotionArchive((int)$promo['id'], 'expired_cleanup');
                $allItemsToClean = array_merge($allItemsToClean, $items);

                // Batch uklanjanje sale_price (postojeće)
                [$productUpdates, $variantUpdates, $cacheUpdates] = $this->buildRestoreUpdates($items);
                $productResults = !empty($productUpdates) ? $this->api->batchUpdateProducts($productUpdates) : [];
                $variantResults = !empty($variantUpdates) ? $this->api->batchUpdateVariants($variantUpdates) : [];
                $omnibusProductIds = $this->mergeProductIdLists(
                    $omnibusProductIds,
                    $this->extractProductIdsForSuccessfulRestoreUpdates(
                        array_merge($productUpdates, $variantUpdates),
                        array_merge($productResults, $variantResults)
                    )
                );
                if (!empty($cacheUpdates)) {
                    $this->cacheService->updatePriceCacheDirectly($cacheUpdates);
                }

                // Brisanje iz DB za ovu promociju
                $this->recordRemovedPromotionItems((int)$promo['id'], $items, 'expired_cleanup');
                $this->db->query(
                    "DELETE FROM promotion_products WHERE promotion_id = ? AND store_hash = ?",
                    [$promo['id'], $this->storeHash]
                );
                $this->markPromotionArchiveCleanupCompleted((int)$promo['id']);
            }
        }
        
        // 2. KORAK: Globalno uklanjanje Custom Fieldsa za sve proizvode u batch-u (MULTI CURL)
        if (!empty($allItemsToClean)) {
            $productIdsToClean = $this->getProductsWithoutActivePromotionEntries($allItemsToClean);
            $cleanResults = !empty($productIdsToClean)
                ? $this->customFieldService->batchRemovePromotionFields($productIdsToClean)
                : [];

            $cleanedProductsCount = count(array_filter($cleanResults, fn($r) => $r['success']));
        }
        
        return [
            'processed' => $cleanedProductsCount,
            'omnibus_product_ids' => $omnibusProductIds,
        ];
    }
    
    public function cleanupAllProductsBatch(): array {
        $allProducts = $this->fetchPromotionProductsWithCachePrice(
            "pp.store_hash = ?",
            [$this->storeHash]
        );
        
        if (empty($allProducts)) {
            return ['processed' => 0, 'omnibus_product_ids' => []];
        }

        foreach ($this->extractPromotionIds($allProducts) as $promotionId) {
            $this->finalizePromotionArchive($promotionId, 'global_cleanup');
        }
        
        [$productUpdates, $variantUpdates, $cacheUpdates] = $this->buildRestoreUpdates($allProducts);

        // Batch uklanjanje sale_price (postojeće)
        $productResults = !empty($productUpdates) ? $this->api->batchUpdateProducts($productUpdates) : [];
        $variantResults = !empty($variantUpdates) ? $this->api->batchUpdateVariants($variantUpdates) : [];
        $cleanedCount = count(array_filter(array_merge($productResults, $variantResults), fn($r) => !empty($r['success'])));
        $productIds = array_values(array_unique(array_column($allProducts, 'product_id')));
        
        // 🚀 IZMENA: Uklanjanje custom fields u BATCH-u (MULTI CURL)
        if (!empty($productIds)) {
            $this->customFieldService->batchRemovePromotionFields($productIds);
        }
        
        // 🚀 IZMENA: Update cache u BATCH-u
        if (!empty($cacheUpdates)) {
            $this->cacheService->updatePriceCacheDirectly($cacheUpdates);
        }
        
        // Delete all (postojeće)
        if ($cleanedCount > 0) {
            $this->recordRemovedPromotionItems(null, $allProducts, 'global_cleanup');
            $this->db->query("DELETE FROM promotion_products WHERE store_hash = ?", [$this->storeHash]);
            foreach ($this->extractPromotionIds($allProducts) as $promotionId) {
                $this->markPromotionArchiveCleanupCompleted($promotionId);
            }
        }
        
        return [
            'processed' => $cleanedCount,
            'omnibus_product_ids' => $this->extractProductIdsForSuccessfulRestoreUpdates(
                array_merge($productUpdates, $variantUpdates),
                array_merge($productResults, $variantResults)
            ),
        ];
    }
    
    private function cleanupExpiredProductsBatch($activeProducts): array {
        $activeItemKeys = array_fill_keys(array_keys($activeProducts), true);
        $allExistingItems = $this->fetchPromotionProductsWithCachePrice(
            "pp.store_hash = ?",
            [$this->storeHash]
        );
        $toClean = array_values(array_filter($allExistingItems, function ($item) use ($activeItemKeys) {
            $itemKey = $this->getPromotionItemKey($item['product_id'], $item['variant_id'] ?? null);
            return !isset($activeItemKeys[$itemKey]);
        }));
        
        if (empty($toClean)) {
            return ['processed' => 0, 'omnibus_product_ids' => []];
        }
        
        [$productUpdates, $variantUpdates, $cacheUpdates] = $this->buildRestoreUpdates($toClean);
        $productIds = $this->getProductsWithoutActivePromotionEntries($toClean, array_column($toClean, 'id'));

        // Batch uklanjanje sale_price (postojeće)
        $productResults = !empty($productUpdates) ? $this->api->batchUpdateProducts($productUpdates) : [];
        $variantResults = !empty($variantUpdates) ? $this->api->batchUpdateVariants($variantUpdates) : [];
        $cleanedCount = count(array_filter(array_merge($productResults, $variantResults), fn($r) => !empty($r['success'])));
        
        // 🚀 IZMENA: Uklanjanje custom fields u BATCH-u (MULTI CURL)
        if (!empty($productIds)) {
            $this->customFieldService->batchRemovePromotionFields($productIds);
        }
        
        // 🚀 IZMENA: Update cache u BATCH-u
        if (!empty($cacheUpdates)) {
            $this->cacheService->updatePriceCacheDirectly($cacheUpdates);
        }

        // 🚀 IZMENA: Brisanje iz baze jednim BATCH SQL upitom
        $dbIdsToDelete = array_column($toClean, 'id');
        $placeholders = str_repeat('?,', count($dbIdsToDelete) - 1) . '?';
        $this->recordRemovedPromotionItems(null, $toClean, 'no_longer_applicable');
        $this->db->query(
            "DELETE FROM promotion_products WHERE store_hash = ? AND id IN ($placeholders)",
            array_merge([$this->storeHash], $dbIdsToDelete)
        );
        
        return [
            'processed' => $cleanedCount,
            'omnibus_product_ids' => $this->extractProductIdsForSuccessfulRestoreUpdates(
                array_merge($productUpdates, $variantUpdates),
                array_merge($productResults, $variantResults)
            ),
        ];
    }

    /**
     * Batch čišćenje proizvoda za JEDNU specifičnu promociju.
     * Koristi se kada promocija istekne, a korisnik klikne "Sync".
     */
    public function cleanupSinglePromotionBatch($promotionId, $limit = 50) {
        $this->finalizePromotionArchive((int)$promotionId, 'expired_cleanup');

        // 1. Dohvati proizvode i varijante vezane za ovu promociju, uključujući njihov PK
        $items = $this->fetchPromotionProductsWithCachePrice(
            "pp.promotion_id = ? AND pp.store_hash = ?",
            [$promotionId, $this->storeHash],
            "LIMIT " . (int)$limit
        );
        
        if (empty($items)) {
            return ['processed' => 0, 'errors' => 0, 'omnibus_product_ids' => []];
        }
        
        $productIds = array_column($items, 'product_id');
        [$productUpdates, $variantUpdates, $cacheUpdates] = $this->buildRestoreUpdates($items);
        
        // 3. Ukloni sale_price na BigCommerce
        $productResults = !empty($productUpdates) ? $this->api->batchUpdateProducts($productUpdates) : [];
        $variantResults = !empty($variantUpdates) ? $this->api->batchUpdateVariants($variantUpdates) : [];
        $errors = count(array_filter($productResults, fn($r) => !$r['success'])) + count(array_filter($variantResults, fn($r) => !$r['success']));
        $omnibusProductIds = $this->extractProductIdsForSuccessfulRestoreUpdates(
            array_merge($productUpdates, $variantUpdates),
            array_merge($productResults, $variantResults)
        );

        // 4. Ukloni Custom Fields (Batch)
        $productsToClearFields = [];
        
        // 5. Obriši iz lokalne baze koristeći primarne ključeve
        $dbIdsToDelete = array_column($items, 'id');
        $placeholders = str_repeat('?,', count($dbIdsToDelete) - 1) . '?';
        $this->recordRemovedPromotionItems((int)$promotionId, $items, 'promotion_cleanup');
        $this->db->query(
            "DELETE FROM promotion_products WHERE store_hash = ? AND id IN ($placeholders)",
            array_merge([$this->storeHash], $dbIdsToDelete)
        );
        
        // 6. Ažuriraj keš direktno
        $productsToClearFields = $this->getProductsWithoutActivePromotionEntries($items, $dbIdsToDelete);
        if (!empty($productsToClearFields)) {
            $this->customFieldService->batchRemovePromotionFields($productsToClearFields);
        }

        $this->cacheService->updatePriceCacheDirectly($cacheUpdates);
        
        return [
            'processed' => count($items),
            'errors' => $errors,
            'omnibus_product_ids' => $omnibusProductIds,
        ];
    }
    
    private function batchSavePromotionProducts($promotions, ?string $lifecycleAt = null) {
        if (empty($promotions)) {
            return;
        }

        $this->batchSavePromotionProductsNullSafe($promotions, $lifecycleAt);
    }

    private function batchSavePromotionProductsNullSafe(array $promotions, ?string $lifecycleAt = null): void {
        $promotions = $this->normalizePromotionProductRows($promotions);
        if (empty($promotions)) {
            return;
        }

        foreach (array_chunk($promotions, 100) as $chunk) {
            $existingRows = $this->fetchExistingPromotionProductRows($chunk);
            $existingRowsByKey = [];

            foreach ($existingRows as $row) {
                $key = $this->getPromotionItemKey(
                    (int)$row['product_id'],
                    $row['variant_id'] !== null ? (int)$row['variant_id'] : null
                );
                $existingRowsByKey[$key][] = $row;
            }

            $rowsToInsert = [];
            $duplicateIdsToDelete = [];

            foreach ($chunk as $promo) {
                $key = $this->getPromotionItemKey($promo['product_id'], $promo['variant_id']);
                $existingForItem = $existingRowsByKey[$key] ?? [];

                if (!empty($existingForItem)) {
                    $primaryRow = array_shift($existingForItem);
                    foreach ($existingForItem as $duplicateRow) {
                        $duplicateIdsToDelete[] = (int)$duplicateRow['id'];
                    }

                    $promotionChanged = (int)($primaryRow['promotion_id'] ?? 0) !== $promo['promotion_id'];
                    $lifecycleResetSql = '';
                    $bindings = [
                        $promo['promotion_id'],
                        $promo['custom_field_id'],
                    ];
                    if ($promotionChanged) {
                        $this->recordReplacedPromotionItems([$primaryRow]);

                        if ($lifecycleAt !== null) {
                            $lifecycleResetSql = ', first_applied_at = ?, omnibus_reference_at = ?';
                            $bindings[] = $lifecycleAt;
                            $bindings[] = $lifecycleAt;
                        } else {
                            $lifecycleResetSql = ', first_applied_at = NOW(), omnibus_reference_at = NOW()';
                        }
                    }

                    if ($lifecycleAt !== null) {
                        $syncedAtSql = '?';
                        $bindings[] = $lifecycleAt;
                    } else {
                        $syncedAtSql = 'NOW()';
                    }

                    $bindings[] = $this->storeHash;
                    $bindings[] = (int)$primaryRow['id'];
                    $this->db->query(
                        "UPDATE promotion_products
                         SET promotion_id = ?, custom_field_id = ?{$lifecycleResetSql}, synced_at = {$syncedAtSql}
                         WHERE store_hash = ? AND id = ?",
                        $bindings
                    );
                    continue;
                }

                $rowsToInsert[] = $promo;
            }

            if (!empty($duplicateIdsToDelete)) {
                $placeholders = str_repeat('?,', count($duplicateIdsToDelete) - 1) . '?';
                $this->db->query(
                    "DELETE FROM promotion_products WHERE store_hash = ? AND id IN ($placeholders)",
                    array_merge([$this->storeHash], $duplicateIdsToDelete)
                );
            }

            $this->insertPromotionProductRows($rowsToInsert, $lifecycleAt);
        }
    }

    private function normalizePromotionProductRows(array $promotions): array {
        $normalized = [];

        foreach ($promotions as $promo) {
            $productId = (int)($promo['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $variantId = isset($promo['variant_id']) && $promo['variant_id'] !== null && $promo['variant_id'] !== ''
                ? (int)$promo['variant_id']
                : null;
            $key = $this->getPromotionItemKey($productId, $variantId);

            $normalized[$key] = [
                'promotion_id' => (int)($promo['promotion_id'] ?? 0),
                'product_id' => $productId,
                'variant_id' => $variantId,
                'custom_field_id' => isset($promo['custom_field_id']) && $promo['custom_field_id'] !== null
                    ? (int)$promo['custom_field_id']
                    : null,
            ];
        }

        return array_values(array_filter($normalized, static function (array $promo): bool {
            return $promo['promotion_id'] > 0;
        }));
    }

    private function fetchExistingPromotionProductRows(array $promotions): array {
        if (empty($promotions)) {
            return [];
        }

        $conditions = [];
        $params = [$this->storeHash];

        foreach ($promotions as $promo) {
            $conditions[] = '(product_id = ? AND variant_id <=> ?)';
            $params[] = $promo['product_id'];
            $params[] = $promo['variant_id'];
        }

        return $this->db->fetchAll(
            "SELECT id, promotion_id, product_id, variant_id
             FROM promotion_products
             WHERE store_hash = ? AND (" . implode(' OR ', $conditions) . ")
             ORDER BY product_id ASC, variant_id ASC, synced_at DESC, id DESC",
            $params
        );
    }

    private function insertPromotionProductRows(array $promotions, ?string $lifecycleAt = null): void {
        if (empty($promotions)) {
            return;
        }

        $values = [];
        $bindings = [];

        foreach ($promotions as $promo) {
            if ($lifecycleAt !== null) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                $bindings = array_merge($bindings, [
                    $this->storeHash,
                    $promo['promotion_id'],
                    $promo['product_id'],
                    $promo['variant_id'],
                    $promo['custom_field_id'],
                    $lifecycleAt,
                    $lifecycleAt,
                    $lifecycleAt,
                ]);
                continue;
            }

            $values[] = '(?, ?, ?, ?, ?, NOW(), NOW(), NOW())';
            $bindings = array_merge($bindings, [
                $this->storeHash,
                $promo['promotion_id'],
                $promo['product_id'],
                $promo['variant_id'],
                $promo['custom_field_id'],
            ]);
        }

        $this->db->query(
            "INSERT INTO promotion_products
             (store_hash, promotion_id, product_id, variant_id, custom_field_id, first_applied_at, omnibus_reference_at, synced_at)
             VALUES " . implode(', ', $values),
            $bindings
        );
    }
    
    private function isBetterPromotion($newPromo, $existingPromo) {
        return $newPromo['priority'] > $existingPromo['priority'] ||
               ($newPromo['priority'] == $existingPromo['priority'] && 
                $newPromo['discount_percent'] > $existingPromo['discount_percent']);
    }
    
    public function logSync($promotionId, $synced, $errors, $duration, $message, $type = 'full') {
        $this->db->query(
            "INSERT INTO sync_log (store_hash, promotion_id, sync_type, products_synced, errors, duration_seconds, log_message)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$this->storeHash, $promotionId, $type, $synced, $errors, round($duration, 2), $message]
        );
    }

    private function getDatabaseTimestamp(): string {
        try {
            $row = $this->db->fetchOne("SELECT NOW() AS current_time");
            if (!empty($row['current_time'])) {
                return (new \DateTimeImmutable((string)$row['current_time']))->format('Y-m-d H:i:s');
            }
        } catch (\Throwable $e) {
            // Fallback keeps non-DB tests and degraded environments operational.
        }

        return date('Y-m-d H:i:s');
    }

    public function syncSinglePromotion($promotionId) {
        $startTime = microtime(true);
        
        $promotion = $this->promotionModel->findById($promotionId);
        if (!$promotion) {
            throw new \Exception("Promocija ID: {$promotionId} nije pronađena.");
        }

        $products = $this->cacheService->getProductsByFilters(json_decode($promotion['filters'], true));
        $activePromotions = $this->promotionModel->findActive();
        $reconciliationResult = $this->reconcilePromotionProductsForPromotion($promotion, $activePromotions);
        $reconciledCount = $reconciliationResult['changed'] ?? 0;
        
        $stats = $this->processProductsBatch($products, $promotionId);
        $stats['cleaned'] = $reconciledCount;
        $stats['omnibus_product_ids'] = $this->mergeProductIdLists(
            $stats['omnibus_product_ids'] ?? [],
            $reconciliationResult['omnibus_product_ids'] ?? []
        );

        $duration = round(microtime(true) - $startTime, 2);
        
        $this->logSync($promotionId, $stats['synced'], $stats['errors'], $duration, "Single Sync: " . $promotion['name']);

        return $stats;
    }

    public function syncSinglePromotionBatch($promotionId, $limit = 50, $offset = 0) {
        $promotion = $this->promotionModel->findById($promotionId);
        if (!$promotion) throw new \Exception("Promocija nije pronađena.");

        $filters = json_decode($promotion['filters'], true);
        $activePromotions = $this->promotionModel->findActive();
        $reconciliationResult = $offset === 0
            ? $this->reconcilePromotionProductsForPromotion($promotion, $activePromotions)
            : ['changed' => 0, 'omnibus_product_ids' => []];
        $reconciledCount = $reconciliationResult['changed'] ?? 0;
        
        // 1. Dohvati proizvode i varijante
        $items = $this->cacheService->getProductsByFilters($filters, $limit, $offset);
        if (empty($items)) {
            return [
                'processed' => 0,
                'errors' => 0,
                'cleaned' => $reconciledCount,
                'omnibus_product_ids' => $reconciliationResult['omnibus_product_ids'] ?? [],
            ];
        }

        $productPromotions = [];

        // 2. Priprema podataka (samo logika, bez API poziva)
        foreach ($items as $item) {
            // Provera da li postoji bolja promocija od ove trenutne
            $bestPromo = $this->calculateBestPromotionCandidate($item, $activePromotions);
            
            // Ako je ova promocija ($promotion) ta koja je najbolja (ili jednako dobra), primeni je
            if ($bestPromo && $bestPromo['promotion_id'] == $promotion['id']) {
                $originalPrice = (float)$item['price'];
                if ($originalPrice <= 0) {
                    continue;
                } // Skip items without a price

                $discount = (float)$promotion['discount_percent'];
                $promoPrice = round($originalPrice * (1 - $discount / 100), 2);

                // Koristimo kompozitni ključ da razlikujemo proizvode i varijante
                $key = $item['variant_id'] ? "v_{$item['variant_id']}" : "p_{$item['product_id']}";

                $productPromotions[$key] = [
                    'promotion_id'   => $promotion['id'],
                    'product_id'     => $item['product_id'],
                    'variant_id'     => $item['variant_id'] ?? null,
                    'product_name'   => $item['name'],
                    'original_price' => $originalPrice,
                    'discount_percent' => $discount,
                    'promo_price'    => $promoPrice,
                    'promotion_name' => $promotion['name'],
                    'custom_field_value' => $promotion['custom_field_value'] ?? $promotion['name']
                ];
            }
        }

        if (empty($productPromotions)) {
            return [
                'processed' => 0, 
                'errors' => count($items),
                'cleaned' => $reconciledCount,
                'omnibus_product_ids' => $reconciliationResult['omnibus_product_ids'] ?? [],
            ];
        }
        // 3. Razdvajanje ažuriranja za proizvode i za varijante
        $applyResults = $this->applyPromotionCandidatesBatch($productPromotions, $items);

        return [
            'processed' => $applyResults['processed'],
            'errors' => count($items) - $applyResults['processed'],
            'cleaned' => $reconciledCount,
            'omnibus_product_ids' => $this->mergeProductIdLists(
                $applyResults['omnibus_product_ids'] ?? [],
                $reconciliationResult['omnibus_product_ids'] ?? []
            ),
        ];
    }

    private function applyPromotionCandidatesBatch(array $productPromotions, array $sourceItems = []): array {
        if (empty($productPromotions)) {
            return ['processed' => 0, 'errors' => 0, 'applied' => [], 'omnibus_product_ids' => []];
        }

        [$productUpdates, $variantUpdates] = $this->buildPriceUpdateBatches($productPromotions);

        $productResults = !empty($productUpdates) ? $this->api->batchUpdateProducts($productUpdates) : [];
        $variantResults = !empty($variantUpdates) ? $this->api->batchUpdateVariants($variantUpdates) : [];
        $priceResults = array_merge($productResults, $variantResults);
        $appliedPromotions = $this->filterPromotionsWithSuccessfulPriceUpdates($productPromotions, $priceResults);

        if (empty($appliedPromotions)) {
            return ['processed' => 0, 'errors' => count($productPromotions), 'applied' => [], 'omnibus_product_ids' => []];
        }

        $existingFieldsMap = $this->buildExistingFieldsMap($sourceItems);
        $knownFieldIds = $this->getKnownPromotionFieldIds(
            array_values(array_unique(array_column($appliedPromotions, 'product_id')))
        );
        $cfUpdates = $this->buildUniquePromotionFieldUpdates($appliedPromotions);
        $cfResults = $this->customFieldService->upsertCustomFields($cfUpdates, $existingFieldsMap, $knownFieldIds);

        $fieldIdMap = [];
        foreach ($cfResults as $result) {
            if (!empty($result['success']) && !empty($result['custom_field_id'])) {
                $fieldIdMap[$result['product_id']] = $result['custom_field_id'];
            }
        }

        foreach ($appliedPromotions as &$promo) {
            $promo['custom_field_id'] = $fieldIdMap[$promo['product_id']] ?? null;
        }
        unset($promo);

        $promotionAppliedAt = $this->getDatabaseTimestamp();
        foreach ($appliedPromotions as &$promo) {
            $promo['applied_at'] = $promotionAppliedAt;
            $promo['last_seen_at'] = $promotionAppliedAt;
        }
        unset($promo);

        $this->batchSavePromotionProducts($appliedPromotions, $promotionAppliedAt);
        $this->recordAppliedPromotionItems($this->buildPromotionArchiveMetaFromAppliedItems($appliedPromotions), $appliedPromotions);

        $cachePriceUpdates = $this->buildPromotionCachePriceUpdates($appliedPromotions, $promotionAppliedAt);
        $this->cacheService->updatePriceCacheDirectly($cachePriceUpdates);

        return [
            'processed' => count($appliedPromotions),
            'errors' => count($productPromotions) - count($appliedPromotions),
            'applied' => $appliedPromotions,
            'omnibus_product_ids' => $this->extractUniqueProductIds($appliedPromotions),
        ];
    }

    private function buildExistingFieldsMap(array $items): array {
        $existingFieldsMap = [];

        foreach ($items as $item) {
            if (!empty($item['variant_id']) || empty($item['product_id']) || !array_key_exists('custom_fields', $item)) {
                continue;
            }

            $existingFieldsMap[$item['product_id']] = is_string($item['custom_fields'])
                ? json_decode($item['custom_fields'], true)
                : $item['custom_fields'];
        }

        return $existingFieldsMap;
    }

    private function getKnownPromotionFieldIds(array $productIds): array {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $id): bool {
            return $id > 0;
        })));

        if (empty($productIds)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        $rows = $this->db->fetchAll(
            "SELECT product_id, custom_field_id
             FROM promotion_products
             WHERE store_hash = ? AND product_id IN ($placeholders)",
            array_merge([$this->storeHash], $productIds)
        );

        return array_column($rows, 'custom_field_id', 'product_id');
    }

    private function reconcilePromotionProductsForPromotion(array $promotion, array $activePromotions): array {
        $promotionId = (int)($promotion['id'] ?? 0);
        if ($promotionId <= 0) {
            return ['changed' => 0, 'omnibus_product_ids' => []];
        }

        $existingItems = $this->fetchPromotionProductsWithCachePrice(
            "pp.promotion_id = ? AND pp.store_hash = ?",
            [$promotionId, $this->storeHash]
        );

        if (empty($existingItems)) {
            return ['changed' => 0, 'omnibus_product_ids' => []];
        }

        $reconciliation = $this->getPromotionItemsNoLongerApplicable($existingItems, $promotionId, $activePromotions);
        $changedCount = 0;
        $omnibusProductIds = [];

        if (!empty($reconciliation['apply'])) {
            $applyResults = $this->applyPromotionCandidatesBatch($reconciliation['apply'], $existingItems);
            $changedCount += $applyResults['processed'];
            $omnibusProductIds = $this->mergeProductIdLists(
                $omnibusProductIds,
                $applyResults['omnibus_product_ids'] ?? []
            );
        }

        if (!empty($reconciliation['restore'])) {
            $cleanupResults = $this->cleanupPromotionProductItems($reconciliation['restore']);
            $changedCount += $cleanupResults['processed'];
            $omnibusProductIds = $this->mergeProductIdLists(
                $omnibusProductIds,
                $cleanupResults['omnibus_product_ids'] ?? []
            );
        }

        return ['changed' => $changedCount, 'omnibus_product_ids' => $omnibusProductIds];
    }

    private function getPromotionItemsNoLongerApplicable(array $items, int $promotionId, array $activePromotions): array {
        $toRestore = [];
        $toApply = [];

        foreach ($items as $item) {
            $bestCandidate = $this->calculateBestPromotionCandidate($item, $activePromotions);

            if ($bestCandidate && (int)$bestCandidate['promotion_id'] === $promotionId) {
                continue;
            }

            if ($bestCandidate) {
                $key = $this->getPromotionItemKey($bestCandidate['product_id'], $bestCandidate['variant_id'] ?? null);
                $toApply[$key] = $bestCandidate;
                continue;
            }

            $toRestore[] = $item;
        }

        return [
            'restore' => $toRestore,
            'apply' => array_values($toApply),
        ];
    }

    private function cleanupPromotionProductItems(array $items): array {
        if (empty($items)) {
            return ['processed' => 0, 'omnibus_product_ids' => []];
        }

        [$productUpdates, $variantUpdates, $cacheUpdates] = $this->buildRestoreUpdates($items);
        $dbIdsToDelete = array_values(array_filter(array_map('intval', array_column($items, 'id')), static function (int $id): bool {
            return $id > 0;
        }));
        $productIds = $this->getProductsWithoutActivePromotionEntries($items, $dbIdsToDelete);

        $productResults = !empty($productUpdates) ? $this->api->batchUpdateProducts($productUpdates) : [];
        $variantResults = !empty($variantUpdates) ? $this->api->batchUpdateVariants($variantUpdates) : [];
        $cleanedCount = count(array_filter(array_merge($productResults, $variantResults), fn($result) => !empty($result['success'])));

        if (!empty($productIds)) {
            $this->customFieldService->batchRemovePromotionFields($productIds);
        }

        if (!empty($cacheUpdates)) {
            $this->cacheService->updatePriceCacheDirectly($cacheUpdates);
        }

        if (!empty($dbIdsToDelete)) {
            $placeholders = str_repeat('?,', count($dbIdsToDelete) - 1) . '?';
            $this->recordRemovedPromotionItems(null, $items, 'no_longer_applicable');
            $this->db->query(
                "DELETE FROM promotion_products WHERE store_hash = ? AND id IN ($placeholders)",
                array_merge([$this->storeHash], $dbIdsToDelete)
            );
        }

        return [
            'processed' => $cleanedCount,
            'omnibus_product_ids' => $this->extractProductIdsForSuccessfulRestoreUpdates(
                array_merge($productUpdates, $variantUpdates),
                array_merge($productResults, $variantResults)
            ),
        ];
    }

    private function processProductsBatch($products, $specificPromoId = null) {
        $synced = 0;
        $errors = 0;
        $omnibusProductIds = [];
        
        // Dohvati sve trenutno aktivne promocije za poređenje prioriteta
        $activePromotions = $this->promotionModel->findActive();

        foreach ($products as $product) {
            try {
                // Pronađi najbolju promociju za ovaj konkretan proizvod
                $bestPromo = $this->calculateBestPromotionCandidate($product, $activePromotions);
                
                if ($bestPromo) {
                    // Primeni cenu i custom field
                    $this->applyPromotionToProduct($product, $bestPromo);
                    $synced++;
                    $omnibusProductIds = $this->mergeProductIdLists(
                        $omnibusProductIds,
                        [(int)$product['product_id']]
                    );
                } else {
                    // Ako proizvod više ne upada ni u jednu promociju, vrati originalnu cenu
                    $this->removePromotionFromProduct($product);
                    $omnibusProductIds = $this->mergeProductIdLists(
                        $omnibusProductIds,
                        [(int)$product['product_id']]
                    );
                }
            } catch (\Exception $e) {
                $errors++;
                error_log("Error syncing product {$product['product_id']}: " . $e->getMessage());
            }
        }

        return ['synced' => $synced, 'errors' => $errors, 'omnibus_product_ids' => $omnibusProductIds];
    }

    /**
     * Sinhronizuje jedan proizvod na osnovu njegovog ID-a.
     * Koristi se primarno za Webhook-ove.
     */
    public function syncProduct($productId) {
        // 1. Dohvati podatke o proizvodu i svim njegovim varijantama iz keša
        $items = $this->db->fetchAll("SELECT * FROM products_cache WHERE product_id = ? AND store_hash = ?", [$productId, $this->storeHash]);
        
        if (empty($items)) {
            return ['synced' => 0, 'errors' => 1, 'message' => 'Product not found in cache'];
        }

        // 2. Pozovi postojeću logiku za obradu (kao niz od 1 elementa)
        // Ovo će automatski naći najbolju promociju ili ukloniti postojeću
        return $this->processProductsBatch($items);
    }

    public function validateDiscountPercent($discountPercent): float {
        $normalized = is_string($discountPercent)
            ? str_replace(',', '.', trim($discountPercent))
            : $discountPercent;

        if (!is_numeric($normalized)) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.discount_numeric',
                [],
                'Discount must be a number.'
            ));
        }

        $value = (float)$normalized;
        if ($value <= 0 || $value >= 100) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.discount_range',
                [],
                'Discount must be greater than 0 and lower than 100.'
            ));
        }

        return round($value, 2);
    }

    private function buildPromotionPreviewRow(
        array $item,
        float $discountPercent,
        $referenceAt = null,
        bool $referenceAtResolved = false,
        bool $skipOmnibusRevalidation = false
    ): ?array {
        if (empty($item['price']) || (float)$item['price'] <= 0) {
            return null;
        }

        $originalPrice = (float)$item['price'];
        $promoPrice = $this->calculatePromoPrice($originalPrice, $discountPercent);
        $savings = $originalPrice - $promoPrice;
        $omnibusReferenceAt = $referenceAtResolved
            ? $this->normalizeReferenceAt($referenceAt)
            : $this->resolvePreviewOmnibusReferenceAt($referenceAt);
        $omnibus = $this->validatePromotionPriceAgainstOmnibus(
            $item,
            $promoPrice,
            $omnibusReferenceAt
        );
        if ($skipOmnibusRevalidation) {
            $omnibus = $this->markOmnibusRevalidationSkipped($omnibus);
        }
        $validation = $this->applyCostPriceGuard($omnibus, $item, $promoPrice);

        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'sku' => $item['sku'],
            'product_id' => (int)$item['product_id'],
            'variant_id' => isset($item['variant_id']) ? (int)$item['variant_id'] : null,
            'original_price' => $originalPrice,
            'promo_price' => $promoPrice,
            'savings' => $savings,
            'savings_percent' => $discountPercent,
            'inventory' => $item['inventory_level'],
            'brand' => $item['brand_name'],
            'is_visible' => $item['is_visible'],
        ] + $validation;
    }

    private function buildPromotionCandidate(array $product, array $promotion): ?array {
        if (empty($product['price']) || (float)$product['price'] <= 0) {
            return null;
        }

        $promotionFilters = $promotion['filters'] ?? [];
        $product[self::COST_PRICE_BLOCK_ENABLED_ITEM_FLAG] = $this->isCostPriceBlockingEnabledFromFilters($promotionFilters);
        $manualUnblockedItems = $this->getManualUnblockedItemKeysFromFilters($promotionFilters);
        $product[self::MANUAL_UNBLOCK_ITEM_FLAG] = $this->isItemManuallyUnblocked($product, $manualUnblockedItems);

        try {
            $discount = $this->validateDiscountPercent($promotion['discount_percent'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        $originalPrice = (float)$product['price'];
        $promoPrice = $this->calculatePromoPrice($originalPrice, $discount);
        $existingPromotionProductCurrentForTerms = $this->isExistingPromotionProductCurrentForTerms($product, $promotion);
        $omnibusReferenceAt = $existingPromotionProductCurrentForTerms
            ? $this->resolvePromotionOmnibusReferenceAt($promotion)
            : $this->resolveNewPromotionProductOmnibusReferenceAt($promotion);
        $omnibus = $this->validatePromotionPriceAgainstOmnibus(
            $product,
            $promoPrice,
            $omnibusReferenceAt
        );
        if (
            empty($omnibus['will_apply'])
            && $existingPromotionProductCurrentForTerms
        ) {
            $omnibus = $this->markOmnibusRevalidationSkipped($omnibus);
        }
        $validation = $this->applyCostPriceGuard($omnibus, $product, $promoPrice);

        return [
            'id' => $promotion['id'],
            'promotion_id' => $promotion['id'],
            'promotion_name' => $promotion['name'],
            'custom_field_value' => $promotion['custom_field_value'] ?? $promotion['name'],
            'product_name' => $product['name'],
            'product_id' => $product['product_id'],
            'variant_id' => $product['variant_id'] ?? null,
            'original_price' => $originalPrice,
            'discount_percent' => $discount,
            'promo_price' => $promoPrice,
            'priority' => $promotion['priority']
        ] + $validation;
    }

    private function calculateBestPromotionCandidate($product, $activePromotions) {
        $bestCandidate = null;

        foreach ($activePromotions as $promo) {
            $filters = json_decode($promo['filters'], true) ?: [];
            if (!$this->productMatchesFilters($product, $filters)) {
                continue;
            }

            $candidate = $this->buildPromotionCandidate($product, $promo);
            if (!$candidate || empty($candidate['will_apply'])) {
                continue;
            }

            if (!$bestCandidate || $this->isBetterPromotion($candidate, $bestCandidate)) {
                $bestCandidate = $candidate;
            }
        }

        return $bestCandidate;
    }

    private function validatePromotionPriceAgainstOmnibus(array $item, float $promoPrice, $referenceAt = null): array {
        $base = [
            'lowest_price_30d' => null,
            'rolling_lowest_price_30d' => null,
            'omnibus_reference_price' => null,
            'omnibus_reference_at' => null,
            'omnibus_valid' => true,
            'will_apply' => true,
            'omnibus_status' => 'disabled',
            'omnibus_warning' => $this->translateMessage(
                'promotions.preview.omnibus_disabled',
                [],
                'Omnibus tracker is disabled; 30-day lowest price was not checked.'
            ),
            'omnibus_invalid_reason' => null,
        ];

        if (!$this->isOmnibusEnabled()) {
            return $base;
        }

        $effectiveReferenceAt = $this->normalizeReferenceAt($referenceAt);
        $dto = $this->omnibusPricingService->getDisplayData(
            $this->storeHash,
            (int)$item['product_id'],
            isset($item['variant_id']) ? (int)$item['variant_id'] : null,
            $this->getStoreCurrency(),
            $promoPrice,
            $effectiveReferenceAt
        );

        $rollingLowest = isset($dto['rolling_lowest_price_last_30_days']) && $dto['rolling_lowest_price_last_30_days'] !== null
            ? (float)$dto['rolling_lowest_price_last_30_days']
            : null;

        $referencePrice = $dto['candidate_omnibus_reference_price']
            ?? $dto['omnibus_reference_price']
            ?? null;
        $usedFallbackReference = false;
        if ($referencePrice === null) {
            $fallbackReferencePrice = $this->getBasePriceOmnibusReference($item, $promoPrice);
            if ($fallbackReferencePrice !== null) {
                $referencePrice = $fallbackReferencePrice;
                $usedFallbackReference = true;
            }
        }

        $displayLowestPrice = $rollingLowest ?? ($referencePrice !== null ? (float)$referencePrice : null);
        $validationReferencePrice = $referencePrice !== null ? (float)$referencePrice : null;
        if (
            $rollingLowest !== null
            && (
                $validationReferencePrice === null
                || $usedFallbackReference
                || $this->isPromoPriceBelowOmnibusReference($rollingLowest, $validationReferencePrice)
            )
        ) {
            $validationReferencePrice = $rollingLowest;
        }

        $reason = $dto['invalid_reduction_reason'] ?? null;
        if ($validationReferencePrice === null) {
            $reason = 'missing_reference_price';
            $isValid = false;
        } else {
            $isValid = $this->isPromoPriceBelowOmnibusReference($promoPrice, $validationReferencePrice);
            if ($isValid) {
                $reason = null;
            } elseif ($rollingLowest !== null && !$this->isPromoPriceBelowOmnibusReference($promoPrice, $rollingLowest)) {
                $reason = 'not_below_30_day_lowest';
            } elseif (empty($dto['is_price_drop_candidate']) && !$usedFallbackReference) {
                $reason = $reason ?: 'not_price_reduction';
            } else {
                $reason = $reason ?: 'not_below_30_day_lowest';
            }
        }

        if ($displayLowestPrice === null && $usedFallbackReference) {
            $displayLowestPrice = (float)$referencePrice;
        }

        return [
            'lowest_price_30d' => $displayLowestPrice,
            'rolling_lowest_price_30d' => $rollingLowest ?? ($usedFallbackReference ? (float)$referencePrice : null),
            'omnibus_reference_price' => $validationReferencePrice,
            'omnibus_reference_at' => $effectiveReferenceAt->format('Y-m-d H:i:s'),
            'omnibus_valid' => $isValid,
            'will_apply' => $isValid,
            'omnibus_status' => $isValid ? 'valid' : 'invalid',
            'omnibus_warning' => $isValid ? '' : $this->buildOmnibusWarning($reason, $validationReferencePrice),
            'omnibus_invalid_reason' => $isValid ? null : $reason,
        ];
    }

    private function getBasePriceOmnibusReference(array $item, float $promoPrice): ?float {
        if (!isset($item['price']) || $item['price'] === null || $item['price'] === '') {
            return null;
        }

        $basePrice = (float)$item['price'];
        if ($basePrice <= 0 || !$this->isPromoPriceBelowOmnibusReference($promoPrice, $basePrice)) {
            return null;
        }

        return $basePrice;
    }

    private function isPromoPriceBelowOmnibusReference(float $promoPrice, float $referencePrice): bool {
        return round($promoPrice, 4) < round($referencePrice, 4);
    }

    private function calculatePromoPrice(float $originalPrice, float $discountPercent): float {
        return round($originalPrice * (1 - $discountPercent / 100), 2);
    }

    private function buildOmnibusWarning(?string $reason, $referencePrice = null): string {
        if ($reason === 'not_below_30_day_lowest') {
            return $this->translateMessage(
                'promotions.preview.omnibus_not_below_reference',
                [],
                'Promo price must be lower than the lowest price in the previous 30 days.'
            );
        }

        if ($reason === 'not_price_reduction') {
            return $this->translateMessage(
                'promotions.preview.omnibus_not_reduction',
                [],
                'This is not a price reduction.'
            );
        }

        return $this->translateMessage(
            'promotions.preview.omnibus_missing_reference',
            [],
            'Missing complete 30-day price history for this item.'
        );
    }

    private function applyCostPriceGuard(array $validation, array $item, float $promoPrice): array {
        $costPriceBlockEnabled = !empty($item[self::COST_PRICE_BLOCK_ENABLED_ITEM_FLAG]);
        $costPrice = $this->normalizeCostPrice($item['cost_price'] ?? null);
        $taxRate = $this->normalizeTaxRate($item['tax_rate'] ?? null);
        $promoPriceExTax = $this->calculateTaxExclusivePrice($promoPrice, $taxRate);
        $promoMargin = $costPrice !== null && $promoPriceExTax !== null
            ? round($promoPriceExTax - $costPrice, 2)
            : null;
        $isValid = $costPrice === null
            || $promoPriceExTax === null
            || !$this->isPromoPriceBelowCostPrice($promoPriceExTax, $costPrice);
        $isManualOverride = $costPriceBlockEnabled && !$isValid && !empty($item[self::MANUAL_UNBLOCK_ITEM_FLAG]);

        $validation['cost_price'] = $costPrice;
        $validation['cost_price_block_enabled'] = $costPriceBlockEnabled;
        $validation['tax_rate'] = $taxRate;
        $validation['promo_price_ex_tax'] = $promoPriceExTax;
        $validation['promo_margin'] = $promoMargin;
        $validation['margin_after_discount'] = $promoMargin;
        $validation['cost_price_valid'] = $isValid;
        $validation['cost_price_overridden'] = $isManualOverride;
        $validation['cost_price_status'] = $costPrice === null || $promoPriceExTax === null
            ? 'not_checked'
            : (!$costPriceBlockEnabled ? 'disabled' : ($isValid ? 'valid' : ($isManualOverride ? 'overridden' : 'invalid')));
        $validation['cost_price_warning'] = '';

        if ($costPriceBlockEnabled && !$isValid && !$isManualOverride) {
            $validation['will_apply'] = false;
        }

        if ($costPriceBlockEnabled && !$isValid) {
            $validation['cost_price_warning'] = $this->buildCostPriceWarning($costPrice);
        }

        $warnings = [];
        $invalidReasons = [];

        if (empty($validation['omnibus_valid']) && !empty($validation['omnibus_warning'])) {
            $warnings[] = $validation['omnibus_warning'];
        }
        if (!empty($validation['omnibus_invalid_reason'])) {
            $invalidReasons[] = $validation['omnibus_invalid_reason'];
        }

        if ($costPriceBlockEnabled && !$isValid) {
            $warnings[] = $validation['cost_price_warning'];
            if ($isManualOverride) {
                $warnings[] = $this->buildCostPriceOverrideWarning();
            } else {
                $invalidReasons[] = 'below_cost_price';
            }
        }

        $validation['promotion_warning'] = implode(' ', array_values(array_unique(array_filter($warnings))));
        $validation['promotion_invalid_reasons'] = array_values(array_unique(array_filter($invalidReasons)));
        $validation['promotion_invalid_reason'] = $validation['promotion_invalid_reasons'][0] ?? null;

        return $validation;
    }

    private function normalizeCostPrice($costPrice): ?float {
        if ($costPrice === null || $costPrice === '' || !is_numeric($costPrice)) {
            return null;
        }

        $costPrice = (float)$costPrice;
        return $costPrice > 0 ? $costPrice : null;
    }

    private function normalizeTaxRate($taxRate): ?float {
        if ($taxRate === null || $taxRate === '' || !is_numeric($taxRate)) {
            return null;
        }

        $taxRate = (float)$taxRate;
        return $taxRate >= 0 ? $taxRate : null;
    }

    private function calculateTaxExclusivePrice(float $taxInclusivePrice, ?float $taxRate): ?float {
        if ($taxRate === null) {
            return null;
        }

        return round($taxInclusivePrice / (1 + ($taxRate / 100)), 4);
    }

    private function isPromoPriceBelowCostPrice(float $promoPrice, float $costPrice): bool {
        return round($promoPrice, 4) < round($costPrice, 4);
    }

    private function buildCostPriceWarning(float $costPrice): string {
        return $this->translateMessage(
            'promotions.preview.below_cost_price',
            ['cost_price' => number_format($costPrice, 2, '.', '')],
            'Promo price is below cost price.'
        );
    }

    private function buildCostPriceOverrideWarning(): string {
        return $this->translateMessage(
            'promotions.preview.cost_price_override_warning',
            [],
            'Cost price block was manually removed by an administrator.'
        );
    }

    private function isOmnibusEnabled(): bool {
        $config = $this->getStoreConfig();
        return !empty($config['enable_omnibus']);
    }

    private function getStoreCurrency(): string {
        $config = $this->getStoreConfig();
        return $config['currency'] ?? 'USD';
    }

    private function getStoreConfig(): array {
        if ($this->storeConfigCache !== null) {
            return $this->storeConfigCache;
        }

        $config = $this->db->fetchOne(
            "SELECT enable_omnibus, currency FROM bigcommerce_stores WHERE store_hash = ?",
            [$this->storeHash]
        );

        $this->storeConfigCache = is_array($config) ? $config : [
            'enable_omnibus' => 0,
            'currency' => 'USD',
        ];

        return $this->storeConfigCache;
    }

    private function normalizeReferenceAt($referenceAt): \DateTimeImmutable {
        if ($referenceAt instanceof \DateTimeImmutable) {
            return $referenceAt;
        }

        if ($referenceAt instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($referenceAt);
        }

        $value = trim((string)$referenceAt);
        if ($value === '') {
            $value = 'now';
        }

        return new \DateTimeImmutable($value);
    }

    private function resolvePreviewOmnibusReferenceAt($submittedStartDate, array $context = []): \DateTimeImmutable {
        $existingPromotion = $this->findPromotionFromPreviewContext($context);

        if (
            is_array($existingPromotion)
            && (
                !$this->hasPromotionOmnibusTermsChanged($existingPromotion, $context)
                || $this->isActiveDiscountCorrectionPreview($existingPromotion, $context)
            )
        ) {
            return $this->resolvePromotionOmnibusReferenceAt($existingPromotion);
        }

        return $this->latestDateTime([
            $this->normalizeReferenceAt($submittedStartDate),
            $this->normalizeReferenceAt('now'),
        ]);
    }

    private function resolvePromotionOmnibusReferenceAt(array $promotion): \DateTimeImmutable {
        $dates = [];
        foreach (['start_date', 'created_at', 'omnibus_terms_updated_at'] as $field) {
            $dateTime = $this->normalizeOptionalReferenceAt($promotion[$field] ?? null);
            if ($dateTime !== null) {
                $dates[] = $dateTime;
            }
        }

        if (empty($dates)) {
            return $this->normalizeReferenceAt('now');
        }

        return $this->latestDateTime($dates);
    }

    private function resolveNewPreviewItemOmnibusReferenceAt($submittedStartDate): \DateTimeImmutable {
        return $this->latestDateTime([
            $this->normalizeReferenceAt($submittedStartDate),
            $this->normalizeReferenceAt('now'),
        ]);
    }

    private function resolveNewPromotionProductOmnibusReferenceAt(array $promotion): \DateTimeImmutable {
        return $this->latestDateTime([
            $this->resolvePromotionOmnibusReferenceAt($promotion),
            $this->normalizeReferenceAt('now'),
        ]);
    }

    private function normalizeOptionalReferenceAt($referenceAt): ?\DateTimeImmutable {
        if ($referenceAt instanceof \DateTimeImmutable) {
            return $referenceAt;
        }

        if ($referenceAt instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($referenceAt);
        }

        $value = trim((string)$referenceAt);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function latestDateTime(array $dateTimes): \DateTimeImmutable {
        $latest = null;

        foreach ($dateTimes as $dateTime) {
            if (!$dateTime instanceof \DateTimeImmutable) {
                continue;
            }

            if ($latest === null || $dateTime > $latest) {
                $latest = $dateTime;
            }
        }

        return $latest ?? $this->normalizeReferenceAt('now');
    }

    private function isEditPreviewWithoutOmnibusTermChanges(array $context): bool {
        $existingPromotion = $this->findPromotionFromPreviewContext($context);

        return is_array($existingPromotion)
            && (
                !$this->hasPromotionOmnibusTermsChanged($existingPromotion, $context)
                || $this->isActiveDiscountCorrectionPreview($existingPromotion, $context)
            );
    }

    private function findPromotionFromPreviewContext(array $context): ?array {
        $promotionId = isset($context['promotion_id']) ? (int)$context['promotion_id'] : 0;
        if ($promotionId <= 0 || !$this->promotionModel) {
            return null;
        }

        $promotion = $this->promotionModel->findById($promotionId);
        return is_array($promotion) ? $promotion : null;
    }

    private function markOmnibusRevalidationSkipped(array $omnibus): array {
        $omnibus['omnibus_valid'] = true;
        $omnibus['will_apply'] = true;
        $omnibus['omnibus_status'] = 'valid';
        $omnibus['omnibus_warning'] = '';
        $omnibus['omnibus_invalid_reason'] = null;
        $omnibus['omnibus_revalidation_skipped'] = true;

        return $omnibus;
    }

    private function isActiveDiscountCorrectionPreview(array $existingPromotion, array $context): bool {
        if (($context['change_type'] ?? self::CHANGE_TYPE_STANDARD) !== self::CHANGE_TYPE_ACTIVE_DISCOUNT_CORRECTION) {
            return false;
        }

        if ((string)($existingPromotion['status'] ?? '') !== 'active') {
            return false;
        }

        if (
            $this->normalizeDateForComparison($existingPromotion['start_date'] ?? null)
                !== $this->normalizeDateForComparison($context['start_date'] ?? null)
        ) {
            return false;
        }

        return round((float)($existingPromotion['discount_percent'] ?? 0), 2)
            !== round((float)($context['discount_percent'] ?? 0), 2);
    }

    private function isExistingPromotionProductCurrentForTerms(array $product, array $promotion): bool {
        if (empty($promotion['id']) || empty($product['product_id']) || !$this->db) {
            return false;
        }

        $row = $this->db->fetchOne(
            "SELECT synced_at
             FROM promotion_products
             WHERE store_hash = ?
               AND promotion_id = ?
               AND product_id = ?
               AND variant_id <=> ?
             ORDER BY synced_at DESC, id DESC
             LIMIT 1",
            [
                $this->storeHash,
                (int)$promotion['id'],
                (int)$product['product_id'],
                isset($product['variant_id']) && $product['variant_id'] !== null
                    ? (int)$product['variant_id']
                    : null,
            ]
        );

        if (empty($row['synced_at'])) {
            return false;
        }

        $syncedAt = $this->normalizeOptionalReferenceAt($row['synced_at']);
        $termsChangedAt = $this->resolvePromotionTermsChangedAt($promotion);

        return $syncedAt !== null
            && $termsChangedAt !== null
            && $syncedAt >= $termsChangedAt;
    }

    private function resolvePromotionTermsChangedAt(array $promotion): ?\DateTimeImmutable {
        foreach (['omnibus_terms_updated_at', 'created_at', 'updated_at', 'start_date'] as $field) {
            $dateTime = $this->normalizeOptionalReferenceAt($promotion[$field] ?? null);
            if ($dateTime !== null) {
                return $dateTime;
            }
        }

        return null;
    }

    private function validateActiveDiscountCorrectionRequest(
        array $existingPromotion,
        array $newData,
        array $context
    ): ?array {
        $changeType = trim((string)($context['change_type'] ?? self::CHANGE_TYPE_STANDARD));
        if ($changeType === '') {
            $changeType = self::CHANGE_TYPE_STANDARD;
        }

        if ($changeType === self::CHANGE_TYPE_STANDARD) {
            return null;
        }

        if ($changeType !== self::CHANGE_TYPE_ACTIVE_DISCOUNT_CORRECTION) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_mode_invalid',
                [],
                'Unsupported promotion correction mode.'
            ));
        }

        if (
            (string)($existingPromotion['status'] ?? '') !== 'active'
            || (string)($newData['status'] ?? '') !== 'active'
        ) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_active_only',
                [],
                'An Omnibus correction can be used only while the promotion remains active.'
            ));
        }

        $oldDiscount = round((float)($existingPromotion['discount_percent'] ?? 0), 2);
        $newDiscount = round((float)($newData['discount_percent'] ?? 0), 2);
        if ($oldDiscount === $newDiscount) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_discount_required',
                [],
                'An Omnibus correction requires a discount percentage change.'
            ));
        }

        if (
            $this->normalizeDateForComparison($existingPromotion['start_date'] ?? null)
                !== $this->normalizeDateForComparison($newData['start_date'] ?? null)
        ) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_start_date_unchanged',
                [],
                'The promotion start date cannot be changed during an Omnibus correction.'
            ));
        }

        $reason = trim((string)($context['correction_reason'] ?? ''));
        if ($reason === '') {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_reason_required',
                [],
                'Enter the reason for the Omnibus correction.'
            ));
        }

        $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
        if ($reasonLength > self::MAX_CORRECTION_REASON_LENGTH) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_reason_too_long',
                ['max' => self::MAX_CORRECTION_REASON_LENGTH],
                'The Omnibus correction reason may contain at most {max} characters.'
            ));
        }

        $actorSource = trim((string)($context['actor_source'] ?? ''));
        $actorUserId = trim((string)($context['actor_user_id'] ?? ''));
        if ($actorSource !== 'bigcommerce' || $actorUserId === '') {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_bigcommerce_identity_required',
                [],
                'Open the app from the BigCommerce control panel before using an Omnibus correction.'
            ));
        }

        return [
            'change_type' => self::CHANGE_TYPE_ACTIVE_DISCOUNT_CORRECTION,
            'reason' => $reason,
            'actor_source' => $actorSource,
            'actor_user_id' => $actorUserId,
            'actor_email' => trim((string)($context['actor_email'] ?? '')) ?: null,
            'actor_is_owner' => !empty($context['actor_is_owner']) ? 1 : 0,
            'old_discount_percent' => $oldDiscount,
            'new_discount_percent' => $newDiscount,
            'lifecycle_reference_at' => $this->resolvePromotionOmnibusReferenceAt($existingPromotion)->format('Y-m-d H:i:s'),
            'old_terms' => $this->encodePromotionRevisionTerms($existingPromotion),
            'new_terms' => $this->encodePromotionRevisionTerms($newData),
        ];
    }

    private function savePromotionUpdateWithCorrectionRevision(
        $promotionId,
        array $data,
        array $revision
    ) {
        $transactionStarted = $this->db->beginTransaction();

        try {
            if ($this->isOmnibusEnabled()) {
                $this->lockExistingPromotionProductOmnibusReferences(
                    (int)$promotionId,
                    $revision['lifecycle_reference_at']
                );
            }
            $result = $this->promotionModel->update($promotionId, $data);
            $this->db->query(
                "INSERT INTO promotion_revisions
                 (store_hash, promotion_id, change_type, reason, actor_source, actor_user_id, actor_email, actor_is_owner, old_discount_percent, new_discount_percent, old_terms, new_terms, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $this->storeHash,
                    (int)$promotionId,
                    $revision['change_type'],
                    $revision['reason'],
                    $revision['actor_source'],
                    $revision['actor_user_id'],
                    $revision['actor_email'],
                    $revision['actor_is_owner'],
                    $revision['old_discount_percent'],
                    $revision['new_discount_percent'],
                    $revision['old_terms'],
                    $revision['new_terms'],
                ]
            );

            if ($transactionStarted) {
                $this->db->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $this->db->rollback();
            }

            throw $e;
        }
    }

    private function lockExistingPromotionProductOmnibusReferences(
        int $promotionId,
        string $fallbackReferenceAt
    ): void {
        $historyVariantSql = $this->priceHistoryHasVariantId()
            ? 'AND ph.variant_id <=> pp.variant_id'
            : 'AND pp.variant_id IS NULL';
        $this->db->query(
            "UPDATE promotion_products pp
             LEFT JOIN products_cache pc
                ON pc.store_hash COLLATE utf8mb4_unicode_ci = pp.store_hash COLLATE utf8mb4_unicode_ci
               AND pc.product_id = pp.product_id
               AND pc.variant_id <=> pp.variant_id
             SET pp.omnibus_reference_at = COALESCE(
                    pp.omnibus_reference_at,
                    (
                        SELECT MIN(ph.recorded_at)
                        FROM product_price_history ph
                        WHERE ph.store_hash COLLATE utf8mb4_unicode_ci = pp.store_hash COLLATE utf8mb4_unicode_ci
                          AND ph.product_id = pp.product_id
                          {$historyVariantSql}
                          AND ROUND(ph.price, 4) = ROUND(
                              CASE
                                  WHEN pc.sale_price IS NOT NULL AND pc.sale_price > 0 THEN pc.sale_price
                                  ELSE pc.price
                              END,
                              4
                          )
                          AND ph.recorded_at >= ?
                    ),
                    ?
                 ),
                 pp.first_applied_at = COALESCE(pp.first_applied_at, ?)
             WHERE pp.store_hash = ?
               AND pp.promotion_id = ?
               AND pp.omnibus_reference_at IS NULL",
            [
                $fallbackReferenceAt,
                $fallbackReferenceAt,
                $fallbackReferenceAt,
                $this->storeHash,
                $promotionId,
            ]
        );
    }

    private function priceHistoryHasVariantId(): bool {
        if ($this->priceHistoryHasVariantId !== null) {
            return $this->priceHistoryHasVariantId;
        }

        try {
            $column = $this->db->fetchOne("SHOW COLUMNS FROM product_price_history LIKE 'variant_id'");
            $this->priceHistoryHasVariantId = $column !== false && $column !== null;
        } catch (\Throwable $e) {
            $this->priceHistoryHasVariantId = false;
        }

        return $this->priceHistoryHasVariantId;
    }

    private function encodePromotionRevisionTerms(array $promotion): string {
        $terms = [];
        foreach (['discount_percent', 'start_date', 'end_date', 'priority', 'filters'] as $field) {
            if (array_key_exists($field, $promotion)) {
                $terms[$field] = $promotion[$field];
            }
        }

        return json_encode($terms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function hasPromotionOmnibusTermsChanged(array $existingPromotion, array $newData): bool {
        if (
            array_key_exists('discount_percent', $newData)
            && round((float)$existingPromotion['discount_percent'], 2) !== round((float)$newData['discount_percent'], 2)
        ) {
            return true;
        }

        if (
            array_key_exists('start_date', $newData)
            && $this->normalizeDateForComparison($existingPromotion['start_date'] ?? null)
                !== $this->normalizeDateForComparison($newData['start_date'] ?? null)
        ) {
            return true;
        }

        return false;
    }

    private function hasPromotionSyncRelevantChanges(array $existingPromotion, array $newData): bool {
        $existingStatus = (string)($existingPromotion['status'] ?? '');
        $newStatus = (string)($newData['status'] ?? $existingStatus);
        if ($existingStatus !== $newStatus) {
            return true;
        }

        if (
            array_key_exists('discount_percent', $newData)
            && round((float)$existingPromotion['discount_percent'], 2) !== round((float)$newData['discount_percent'], 2)
        ) {
            return true;
        }

        if (
            array_key_exists('priority', $newData)
            && (int)($existingPromotion['priority'] ?? 0) !== (int)$newData['priority']
        ) {
            return true;
        }

        foreach (['start_date', 'end_date'] as $field) {
            if (
                array_key_exists($field, $newData)
                && $this->normalizeDateForComparison($existingPromotion[$field] ?? null)
                    !== $this->normalizeDateForComparison($newData[$field] ?? null)
            ) {
                return true;
            }
        }

        if (
            array_key_exists('filters', $newData)
            && $this->normalizeFiltersForComparison($existingPromotion['filters'] ?? [])
                !== $this->normalizeFiltersForComparison($newData['filters'] ?? [])
        ) {
            return true;
        }

        if (
            array_key_exists('custom_field_value', $newData)
            && trim((string)($existingPromotion['custom_field_value'] ?? $existingPromotion['name'] ?? ''))
                !== trim((string)$newData['custom_field_value'])
        ) {
            return true;
        }

        return false;
    }

    private function normalizeDateForComparison($date): ?string {
        $dateTime = $this->normalizeOptionalReferenceAt($date);
        return $dateTime ? $dateTime->format('Y-m-d H:i') : null;
    }

    private function normalizeFiltersForComparison($filters, bool $includeSyncControls = true): string {
        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            $filters = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($filters)) {
            $filters = [];
        }

        if (!$includeSyncControls) {
            unset($filters[self::BLOCK_BELOW_COST_PRICE_FILTER_KEY]);
            unset($filters[self::MANUAL_UNBLOCK_FILTER_KEY]);
        }

        $normalized = $this->sortFilterValueForComparison($filters);
        return json_encode($normalized, JSON_UNESCAPED_UNICODE);
    }

    private function sortFilterValueForComparison($value) {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $normalizedList = array_map([$this, 'sortFilterValueForComparison'], $value);
            usort($normalizedList, static function ($left, $right): int {
                return strcmp(json_encode($left, JSON_UNESCAPED_UNICODE), json_encode($right, JSON_UNESCAPED_UNICODE));
            });

            return $normalizedList;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortFilterValueForComparison($item);
        }

        return $value;
    }

    private function translateMessage(string $key, array $replace, string $fallback): string {
        if (!function_exists('trans')) {
            return $fallback;
        }

        $message = \trans($key, $replace);
        return $message === $key ? $fallback : $message;
    }

    // --- NEDOSTAJUĆE METODE ---

    /**
     * Nalazi najbolju promociju za proizvod na osnovu prioriteta i popusta.
     */
    private function calculateBestPromotion($product, $activePromotions) {
        $bestPromo = null;

        foreach ($activePromotions as $promo) {
            $filters = json_decode($promo['filters'], true) ?: [];
            
            // Provera da li proizvod ispunjava uslove ove promocije
            if ($this->productMatchesFilters($product, $filters)) {
                if (!$bestPromo || $this->isBetterPromotion($promo, $bestPromo)) {
                    $bestPromo = $promo;
                }
            }
        }
        return $bestPromo;
    }

    /**
     * Proverava da li proizvod (PHP niz) odgovara filterima.
     * Ovo je PHP ekvivalent SQL WHERE klauzula iz ProductCacheService.
     */
    private function productMatchesFilters($product, $filters) {
        if (!is_array($filters)) {
            return true;
        }

        $excludeFilters = [];
        if (isset($filters['exclude']) && is_array($filters['exclude'])) {
            $excludeFilters = $filters['exclude'];
            unset($filters['exclude']);
        }

        foreach ($filters as $key => $value) {
            if ($key === 'exclude') {
                continue;
            }

            if (empty($value) && $value !== '0' && $value !== 0) continue;

            if (strpos($key, 'custom_field:') === 0) {
                $fieldName = $this->normalizeEscapedUnicodeString(substr($key, 13));
                $productFields = $product['custom_fields'] ?? [];
                if (is_string($productFields)) $productFields = json_decode($productFields, true);
                if (!is_array($productFields)) $productFields = [];

                $requiredValues = is_array($value) ? $value : [$value];
                $requiredValues = array_values(array_filter(array_map(function($item) {
                    return $this->normalizeEscapedUnicodeString((string)$item);
                }, $requiredValues), function($item) {
                    return $item !== '';
                }));

                $match = false;
                foreach ($productFields as $field) {
                    $normalizedFieldName = $this->normalizeEscapedUnicodeString((string)($field['name'] ?? ''));
                    $normalizedFieldValue = $this->normalizeEscapedUnicodeString((string)($field['value'] ?? ''));

                    if ($normalizedFieldName === $fieldName && in_array($normalizedFieldValue, $requiredValues, true)) {
                        $match = true;
                        break;
                    }
                }

                if (!$match) return false;
                continue;
            }

            switch ($key) {
                case 'brand_id':
                    $requiredBrands = is_array($value) ? $value : explode(',', (string)$value);
                    $requiredBrands = array_values(array_filter(array_map(function($brandId) {
                        return trim((string)$brandId);
                    }, $requiredBrands), function($brandId) {
                        return $brandId !== '';
                    }));

                    if (!in_array((string)($product['brand_id'] ?? ''), $requiredBrands, true)) return false;
                    break;
                case 'categories:in':
                    $productCats = $product['categories'] ?? [];
                    if (is_string($productCats)) $productCats = json_decode($productCats, true);
                    
                    $requiredCats = is_array($value) ? $value : explode(',', $value);
                    // Proveri presek nizova (da li ima bar jednu zajedničku kategoriju)
                    if (empty(array_intersect($productCats, $requiredCats))) return false;
                    break;
                case 'price:min':
                    if (!isset($product['price']) || $product['price'] === '' || (float)$product['price'] < (float)$value) return false;
                    break;
                case 'price:max':
                    if (!isset($product['price']) || $product['price'] === '' || (float)$product['price'] > (float)$value) return false;
                    break;
                case 'product_id':
                    if ((int)$product['product_id'] !== (int)$value) return false;
                    break;
                case 'inventory_level:min':
                    if ((int)$product['inventory_level'] < (int)$value) return false;
                    break;
                case 'is_visible':
                    if ((bool)$product['is_visible'] !== (bool)$value) return false;
                    break;
                case 'is_featured':
                    if ((bool)$product['is_featured'] !== (bool)$value) return false;
                    break;
                case 'sku':
                    if ($product['sku'] !== $value) return false;
                    break;
                case 'sku:in':
                    $skuArray = is_array($value) ? $value : explode(',', $value);
                    // Očistimo potencijalne navodnike i prazna mesta kao u SQL-u
                    $skuArray = array_map(function($item) {
                        return trim($item, " '\"");
                    }, $skuArray);
                    // Ako SKU proizvoda nije u nizu traženih SKU-ova, ne poklapa se
                    if (!in_array(trim($product['sku'] ?? ''), $skuArray)) return false;
                    break;
                case 'name:like':
                    if (stripos($product['name'] ?? '', (string)$value) === false) return false;
                    break;
            }
        }

        if ($this->productMatchesAnyExcludeFilter($product, $excludeFilters)) {
            return false;
        }

        return true;
    }

    private function productMatchesAnyExcludeFilter($product, array $excludeFilters): bool {
        foreach ($excludeFilters as $key => $value) {
            if ($key === 'exclude' || (empty($value) && $value !== '0' && $value !== 0)) {
                continue;
            }

            if ($this->productMatchesFilters($product, [$key => $value])) {
                return true;
            }
        }

        return false;
    }

    private function normalizeEscapedUnicodeString(string $value): string {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($matches) {
            return json_decode('"\\u' . $matches[1] . '"');
        }, trim($value));
    }

    /**
     * Pojedinačna primena promocije (koristi se kao fallback ili unutar petlje ako nije batch).
     * Ipak, preporuka je koristiti batch gde god je moguće.
     */
    private function applyPromotionToProduct($product, $promotion) {
        $originalPrice = (float)$product['price'];
        $discount = (float)$promotion['discount_percent'];
        $promoPrice = round($originalPrice * (1 - $discount / 100), 2);

        // 1. Update na BC (Ovo je sporo ako se radi u petlji!)
        if (!empty($product['variant_id'])) {
            $priceResults = $this->api->batchUpdateVariants([[
                'product_id' => $product['product_id'],
                'id' => $product['variant_id'],
                'sale_price' => $promoPrice
            ]]);

            if (!$this->hasSuccessfulPriceUpdateForItem($product['product_id'], $product['variant_id'], $priceResults)) {
                throw new \RuntimeException("Variant price update failed for product {$product['product_id']} variant {$product['variant_id']}.");
            }
        } else {
            $this->api->updateProductSalePrice($product['product_id'], $promoPrice);
        }

        // 2. Custom Field
        $customFieldId = $this->customFieldService->setPromotionField(
            $product['product_id'],
            $promotion['custom_field_value'] ?? $promotion['name']
        );

        // 3. Save to DB
        $promotionAppliedAt = $this->getDatabaseTimestamp();
        $appliedPromotion = [[
            'promotion_id' => $promotion['id'],
            'product_id' => $product['product_id'],
            'variant_id' => $product['variant_id'] ?? null,
            'product_name' => $product['name'],
            'original_price' => $originalPrice,
            'discount_percent' => $discount,
            'promo_price' => $promoPrice,
            'custom_field_id' => $customFieldId,
            'applied_at' => $promotionAppliedAt,
            'last_seen_at' => $promotionAppliedAt
        ]];
        $this->batchSavePromotionProducts($appliedPromotion, $promotionAppliedAt);
        $this->recordAppliedPromotionItems($promotion, $appliedPromotion);

        $this->cacheService->updatePriceCacheDirectly([[
            'product_id' => $product['product_id'],
            'variant_id' => $product['variant_id'] ?? null,
            'sale_price' => $promoPrice,
            'recorded_at' => $promotionAppliedAt
        ]]);
    }

    /**
     * Uklanja promociju sa proizvoda.
     */
    private function removePromotionFromProduct($product) {
        $existingPromotionItems = $this->fetchPromotionProductsWithCachePrice(
            "pp.product_id = ? AND pp.variant_id <=> ? AND pp.store_hash = ?",
            [$product['product_id'], $product['variant_id'] ?? null, $this->storeHash]
        );

        if (!empty($product['variant_id'])) {
            $this->api->batchUpdateVariants([[
                'product_id' => $product['product_id'],
                'id' => $product['variant_id'],
                'sale_price' => null
            ]]);
            $this->recordRemovedPromotionItems(null, $existingPromotionItems, 'product_resync');
            $this->db->query(
                "DELETE FROM promotion_products WHERE product_id = ? AND variant_id = ? AND store_hash = ?",
                [$product['product_id'], $product['variant_id'], $this->storeHash]
            );
        } else {
            $this->api->updateProductSalePrice($product['product_id'], null);
            $this->recordRemovedPromotionItems(null, $existingPromotionItems, 'product_resync');
            $this->db->query(
                "DELETE FROM promotion_products WHERE product_id = ? AND variant_id IS NULL AND store_hash = ?",
                [$product['product_id'], $this->storeHash]
            );
        }

        if (!$this->hasActivePromotionEntriesForProduct($product['product_id'])) {
            $this->customFieldService->removePromotionField($product['product_id']);
        }

        $this->cacheService->updatePriceCacheDirectly([[
            'product_id' => $product['product_id'],
            'variant_id' => $product['variant_id'] ?? null,
            'sale_price' => null
        ]]);
    }

    private function filterPromotionsWithSuccessfulPriceUpdates(array $promotions, array $priceResults): array {
        $successfulKeys = $this->getSuccessfulPriceUpdateKeys($priceResults);
        if (empty($successfulKeys)) {
            return [];
        }

        return array_filter($promotions, function (array $promo) use ($successfulKeys): bool {
            $key = $this->getPromotionItemKey($promo['product_id'], $promo['variant_id'] ?? null);
            return isset($successfulKeys[$key]);
        });
    }

    private function hasSuccessfulPriceUpdateForItem($productId, $variantId, array $priceResults): bool {
        $key = $this->getPromotionItemKey($productId, $variantId);
        $successfulKeys = $this->getSuccessfulPriceUpdateKeys($priceResults);

        return isset($successfulKeys[$key]);
    }

    private function getSuccessfulPriceUpdateKeys(array $priceResults): array {
        $keys = [];

        foreach ($priceResults as $result) {
            if (empty($result['success'])) {
                continue;
            }

            $variantId = $result['variant_id'] ?? null;
            $productId = $result['product_id'] ?? null;

            if (($productId === null || $productId === '') && ($variantId === null || $variantId === '')) {
                $productId = $result['id'] ?? null;
            }

            if ($variantId !== null && $variantId !== '') {
                $keys[$this->getPromotionItemKey((int)($productId ?: 0), (int)$variantId)] = true;
                continue;
            }

            if ($productId !== null && $productId !== '') {
                $keys[$this->getPromotionItemKey((int)$productId, null)] = true;
            }
        }

        return $keys;
    }

    private function buildUniquePromotionFieldUpdates(array $promotions): array {
        $updates = [];

        foreach ($promotions as $promo) {
            $productId = (int)($promo['product_id'] ?? 0);
            if ($productId <= 0 || isset($updates[$productId])) {
                continue;
            }

            $updates[$productId] = [
                'product_id' => $productId,
                'field_value' => $promo['custom_field_value'] ?? $promo['promotion_name']
            ];
        }

        return array_values($updates);
    }

    private function extractUniqueProductIds(array $items): array {
        $productIds = [];

        foreach ($items as $item) {
            $productId = is_array($item) ? ($item['product_id'] ?? null) : $item;
            if (!is_numeric($productId)) {
                continue;
            }

            $productId = (int)$productId;
            if ($productId > 0) {
                $productIds[$productId] = true;
            }
        }

        $productIds = array_keys($productIds);
        sort($productIds, SORT_NUMERIC);
        return $productIds;
    }

    private function mergeProductIdLists(array ...$lists): array {
        $merged = [];

        foreach ($lists as $list) {
            foreach ($this->extractUniqueProductIds($list) as $productId) {
                $merged[$productId] = true;
            }
        }

        $productIds = array_keys($merged);
        sort($productIds, SORT_NUMERIC);
        return $productIds;
    }

    private function extractProductIdsForSuccessfulRestoreUpdates(array $updates, array $priceResults): array {
        $successfulKeys = $this->getSuccessfulPriceUpdateKeys($priceResults);
        if (empty($successfulKeys)) {
            return [];
        }

        $productIds = [];
        foreach ($updates as $update) {
            $productId = (int)($update['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $variantId = $update['id'] ?? ($update['variant_id'] ?? null);
            $key = $this->getPromotionItemKey(
                $productId,
                $variantId !== null && $variantId !== '' ? (int)$variantId : null
            );

            if (isset($successfulKeys[$key])) {
                $productIds[$productId] = true;
            }
        }

        $productIds = array_keys($productIds);
        sort($productIds, SORT_NUMERIC);
        return $productIds;
    }

    private function buildPromotionCachePriceUpdates(array $promotions, ?string $recordedAt = null): array {
        $updates = [];

        foreach ($promotions as $promo) {
            $update = [
                'product_id' => $promo['product_id'],
                'variant_id' => $promo['variant_id'] ?? null,
                'sale_price' => $promo['promo_price']
            ];

            if ($recordedAt !== null) {
                $update['recorded_at'] = $recordedAt;
            }

            $updates[] = $update;
        }

        return $updates;
    }

    private function buildPriceUpdateBatches(array $productPromotions): array {
        $productUpdates = [];
        $variantUpdates = [];

        foreach ($productPromotions as $promo) {
            $productId = (int)($promo['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            if (!empty($promo['variant_id'])) {
                $variantUpdates[] = [
                    'product_id' => $productId,
                    'id' => (int)$promo['variant_id'],
                    'sale_price' => $promo['promo_price'],
                ];
                continue;
            }

            $productUpdates[] = [
                'product_id' => $productId,
                'sale_price' => $promo['promo_price'],
            ];
        }

        return [$productUpdates, $variantUpdates];
    }

    private function getPromotionItemKey($productId, $variantId = null) {
        return $variantId ? "v_{$variantId}" : "p_{$productId}";
    }

    private function isCostPriceBlockingEnabledFromFilters($filters): bool {
        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            $filters = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($filters)) {
            return false;
        }

        return !empty($filters[self::BLOCK_BELOW_COST_PRICE_FILTER_KEY]);
    }

    private function getManualUnblockedItemKeysFromFilters($filters): array {
        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            $filters = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($filters)) {
            return [];
        }

        $keys = $filters[self::MANUAL_UNBLOCK_FILTER_KEY] ?? [];
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        $normalized = [];
        foreach ($keys as $key) {
            $key = trim((string)$key);
            if ($key !== '' && preg_match('/^[pv]_\d+$/', $key)) {
                $normalized[$key] = true;
            }
        }

        return $normalized;
    }

    private function isItemManuallyUnblocked(array $item, array $manualUnblockedItems): bool {
        $productId = (int)($item['product_id'] ?? 0);
        if ($productId <= 0) {
            return false;
        }

        $variantId = isset($item['variant_id']) && $item['variant_id'] !== null && $item['variant_id'] !== ''
            ? (int)$item['variant_id']
            : null;

        return isset($manualUnblockedItems[$this->getPromotionItemKey($productId, $variantId)]);
    }

    private function buildRestoreUpdates(array $items): array {
        $productUpdates = [];
        $variantUpdates = [];
        $cacheUpdates = [];

        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $cacheUpdates[] = [
                'product_id' => $productId,
                'variant_id' => $item['variant_id'] ?? null,
                'sale_price' => null
            ];

            if (!empty($item['variant_id'])) {
                $variantUpdates[] = [
                    'product_id' => $productId,
                    'id' => (int)$item['variant_id'],
                    'sale_price' => null
                ];
            } else {
                $productUpdates[] = [
                    'product_id' => $productId,
                    'sale_price' => null
                ];
            }
        }

        return [$productUpdates, $variantUpdates, $cacheUpdates];
    }

    private function fetchPromotionProductsWithCachePrice(string $whereSql, array $params = [], string $suffixSql = ''): array {
        $sql = "
            SELECT
                pp.id,
                pp.promotion_id,
                pp.product_id,
                pp.variant_id,
                pp.custom_field_id,
                pc.id AS cache_id,
                pc.type,
                pc.name,
                pc.sku,
                pc.price,
                pc.sale_price,
                pc.cost_price,
                pc.retail_price,
                pc.tax_class_id,
                pc.tax_rate,
                pc.weight,
                pc.inventory_level,
                pc.inventory_warning_level,
                pc.brand_id,
                pc.brand_name,
                pc.categories,
                pc.is_visible,
                pc.is_featured,
                pc.availability,
                pc.`condition`,
                pc.option_values,
                pc.date_created,
                pc.date_modified,
                pc.custom_fields,
                pc.images,
                pc.cached_at,
                pc.price AS original_price
            FROM promotion_products pp
            LEFT JOIN products_cache pc
                ON pc.store_hash = pp.store_hash
               AND pc.product_id = pp.product_id
               AND pc.variant_id <=> pp.variant_id
            WHERE {$whereSql}
            {$suffixSql}
        ";

        return $this->db->fetchAll($sql, $params);
    }

    private function hasActivePromotionEntriesForProduct($productId, array $excludedIds = []): bool {
        $sql = "SELECT COUNT(*) AS cnt FROM promotion_products WHERE store_hash = ? AND product_id = ?";
        $params = [$this->storeHash, $productId];

        if (!empty($excludedIds)) {
            $placeholders = str_repeat('?,', count($excludedIds) - 1) . '?';
            $sql .= " AND id NOT IN ($placeholders)";
            $params = array_merge($params, $excludedIds);
        }

        $result = $this->db->fetchOne($sql, $params);
        return !empty($result['cnt']);
    }

    private function getProductsWithoutActivePromotionEntries(array $items, array $deletedIds = []): array {
        $candidateProductIds = array_values(array_unique(array_column($items, 'product_id')));
        $productsToClear = [];

        foreach ($candidateProductIds as $productId) {
            if (!$this->hasActivePromotionEntriesForProduct($productId, $deletedIds)) {
                $productsToClear[] = $productId;
            }
        }

        return $productsToClear;
    }

    private function recordAppliedPromotionItems(array $promotion, array $items): void {
        if (!$this->hasArchiveServiceMethod('recordAppliedItems')) {
            return;
        }

        $this->archiveService->recordAppliedItems($promotion, $items);
    }

    private function buildPromotionArchiveMetaFromAppliedItems(array $items): array {
        $first = reset($items);
        if (!is_array($first)) {
            return [];
        }

        return [
            'id' => $first['promotion_id'] ?? ($first['id'] ?? null),
            'promotion_id' => $first['promotion_id'] ?? ($first['id'] ?? null),
            'name' => $first['promotion_name'] ?? '',
            'discount_percent' => $first['discount_percent'] ?? null,
        ];
    }

    private function recordRemovedPromotionItems(?int $promotionId, array $items, string $reason): void {
        if (!$this->hasArchiveServiceMethod('recordRemovedItems')) {
            return;
        }

        $this->archiveService->recordRemovedItems($promotionId, $items, $reason);
    }

    private function recordReplacedPromotionItems(array $items): void {
        if (empty($items)) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', array_column($items, 'id')), static function (int $id): bool {
            return $id > 0;
        }));

        if (!empty($ids)) {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $detailedItems = $this->fetchPromotionProductsWithCachePrice(
                "pp.store_hash = ? AND pp.id IN ($placeholders)",
                array_merge([$this->storeHash], $ids)
            );

            if (!empty($detailedItems)) {
                $items = $detailedItems;
            }
        }

        $this->recordRemovedPromotionItems(null, $items, 'promotion_replaced');
    }

    private function extractPromotionIds(array $items): array {
        $promotionIds = [];

        foreach ($items as $item) {
            $promotionId = (int)($item['promotion_id'] ?? 0);
            if ($promotionId > 0) {
                $promotionIds[$promotionId] = true;
            }
        }

        return array_keys($promotionIds);
    }

    private function finalizePromotionArchive(int $promotionId, string $reason): void {
        if (!$this->hasArchiveServiceMethod('finalizeArchive')) {
            return;
        }

        $this->archiveService->finalizeArchive($promotionId, $reason);
    }

    public function markPromotionArchiveCleanupCompleted(int $promotionId): void {
        if (!$this->hasArchiveServiceMethod('markCleanupCompleted')) {
            return;
        }

        $this->archiveService->markCleanupCompleted($promotionId);
    }

    private function shouldArchivePromotionBeforeDelete(array $promotion, int $appliedProducts): bool {
        if ($appliedProducts > 0) {
            return true;
        }

        $status = (string)($promotion['status'] ?? '');
        if ($status === 'active' || $status === 'expired') {
            return true;
        }

        if ($this->hasArchiveServiceMethod('hasHistoricalProducts')) {
            return $this->archiveService->hasHistoricalProducts((int)($promotion['id'] ?? 0));
        }

        return false;
    }

    private function hasArchiveServiceMethod(string $method): bool {
        return is_object($this->archiveService) && method_exists($this->archiveService, $method);
    }
}
