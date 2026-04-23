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
    private const API_PRODUCT_LIMIT = 250;
    
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
    }
    
    /**
     * Kreira novu promociju i ODMAH zakazuje posao za sinhronizaciju.
     * Ovo rešava problem da promocija bude "Aktivna" a neprimenjena.
     */
    public function createPromotion(array $data) {
        // 1. Unos promocije u bazu
        // Status postavljamo na 'active', ali odmah kreiramo posao da to opravdamo
        $sql = "INSERT INTO promotions (store_hash, name, custom_field_value, discount_percent, start_date, end_date, priority, filters, status, color, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())";
        
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
            $data['color'] ?? '#3b82f6'
        ]);
        
        $promotionId = $this->db->lastInsertId();
        
        // 2. Odmah kreiraj Job za sinhronizaciju
        $this->queueJobForPromotion($promotionId, $data['filters']);
        
        return $promotionId;
    }

    private function queueJobForPromotion($promotionId, $filters) {
        $filtersArray = is_array($filters) ? $filters : json_decode($filters, true);
        $totalItems = $this->cacheService->countProductsByFilters($filtersArray);
        
        $queue = new QueueService($this->storeHash);
        // Kreiramo posao odmah
        $queue->createJob('sync_promotion', $promotionId, $totalItems > 0 ? $totalItems : 1);
    }

    /**
     * Umesto da sinhronizuje sve odjednom, ova metoda kreira Job-ove
     * za svaku aktivnu promociju pojedinačno.
     */
    public function queueAllPromotions() {
        // 1. Održavanje: Ažuriraj statuse i obriši istekle
        $this->updateExpiredPromotions();
        $this->promotionModel->updateStatuses();
        
        $promotions = $this->promotionModel->findActive();
        $queue = new QueueService($this->storeHash);
        $jobsCreated = 0;

        if (empty($promotions)) {
            // Ako nema promocija, kreiraj posao za čišćenje svega
            $queue->createJob('cleanup', null, 1);
            return ['message' => 'Nema aktivnih promocija. Zakazan posao čišćenja.', 'jobs' => 1];
        }

        foreach ($promotions as $promo) {
            $filters = json_decode($promo['filters'], true);
            $totalItems = $this->cacheService->countProductsByFilters($filters);
            
            // Kreiramo posao čak i ako je 0, da bi worker mogao da evidentira prolaz
            $queue->createJob('sync_promotion', $promo['id'], $totalItems > 0 ? $totalItems : 1);
            $jobsCreated++;
        }

        return ['message' => "Uspešno zakazano {$jobsCreated} poslova sinhronizacije.", 'jobs' => $jobsCreated];
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
        $expiredCleanedCount = $this->updateExpiredPromotions();
        if ($expiredCleanedCount > 0) {
            $debugLog[] = "Cleaned {$expiredCleanedCount} products from expired promotions";
        }
        
        $this->promotionModel->updateStatuses();
        
        $promotions = $this->promotionModel->findActive();
        $debugLog[] = "Found " . count($promotions) . " active promotions";
        
        if (empty($promotions)) {
            $debugLog[] = "No active promotions - cleaning up all promotional products";
            $cleanedCount = $this->cleanupAllProductsBatch();
            $debugLog[] = "Cleaned {$cleanedCount} products";
            
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

                $productId = $product['product_id'];
                $variantId = $product['variant_id'] ?? null;
                $originalPrice = (float)$product['price'];
                
                if ($originalPrice <= 0) continue;
                
                $discount = (float)$promotion['discount_percent'];
                $promoPrice = round($originalPrice * (1 - $discount / 100), 2);
                
                $itemKey = $this->getPromotionItemKey($productId, $variantId);

                if (!isset($productPromotions[$itemKey]) || 
                    $this->isBetterPromotion($promotion, $productPromotions[$itemKey])) {
                    
                    $productPromotions[$itemKey] = [
                        'promotion_id' => $promotion['id'],
                        'promotion_name' => $promotion['name'],
                        'custom_field_value' => $promotion['custom_field_value'] ?? $promotion['name'],
                        'product_name' => $product['name'],
                        'product_id' => $productId,
                        'variant_id' => $variantId,
                        'original_price' => $originalPrice,
                        'discount_percent' => $discount,
                        'promo_price' => $promoPrice,
                        'priority' => $promotion['priority']
                    ];
                }
            }
        }
        
        $debugLog[] = "\n=== Batch Processing to BigCommerce ===";
        $debugLog[] = "Total products to update: " . count($productPromotions);
        
        // BATCH UPDATE: Apply prices to BigCommerce
        $productUpdates = [];
        $variantUpdates = [];
        $cachePriceUpdates = [];
        foreach ($productPromotions as $promo) {
            $cachePriceUpdates[] = [
                'product_id' => $promo['product_id'],
                'variant_id' => $promo['variant_id'] ?? null,
                'price' => $promo['original_price'],
                'sale_price' => $promo['promo_price']
            ];

            if (!empty($promo['variant_id'])) {
                $variantUpdates[] = [
                    'product_id' => $promo['product_id'],
                    'id' => $promo['variant_id'],
                    'price' => $promo['original_price'],
                    'sale_price' => $promo['promo_price']
                ];
            } else {
                $productUpdates[] = [
                    'product_id' => $promo['product_id'],
                    'price' => $promo['original_price'],
                    'sale_price' => $promo['promo_price']
                ];
            }
        }
        
        $productPriceResults = !empty($productUpdates) ? $this->api->batchUpdateProducts($productUpdates) : [];
        $variantPriceResults = !empty($variantUpdates) ? $this->api->batchUpdateVariants($variantUpdates) : [];
        $priceResults = array_merge($productPriceResults, $variantPriceResults);
        $successCount = count(array_filter($priceResults, fn($r) => !empty($r['success'])));
        $errorCount = count($priceResults) - $successCount;
        
        $debugLog[] = "Price updates: {$successCount} success, {$errorCount} errors";
        
        // BATCH UPDATE: Custom fields (KORIŠĆENJE MULTI CURLA)
        $customFieldUpdates = [];
        foreach ($productPromotions as $promo) {
            $customFieldUpdates[] = [
                'product_id' => $promo['product_id'],
                'field_value' => $promo['custom_field_value'] ?? $promo['promotion_name']
            ];
        }
        
        // 🚀 IZMENA: Korišćenje multi-cURL (batch) metode
        // OPTIMIZACIJA: Prosleđujemo custom fields iz keša da izbegnemo GET requestove
        // OPTIMIZACIJA: Dohvatanje poznatih ID-eva iz promotion_products tabele (Backup ako je keš zastareo)
        $productIds = array_values(array_unique(array_column($productPromotions, 'product_id')));
        $knownFieldIds = [];
        if (!empty($productIds)) {
             $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
             $rows = $this->db->fetchAll(
                 "SELECT product_id, custom_field_id FROM promotion_products WHERE product_id IN ($placeholders) AND store_hash = ?", 
                 array_merge($productIds, [$this->storeHash])
             );
             $knownFieldIds = array_column($rows, 'custom_field_id', 'product_id');
        }
        
        $fieldResults = $this->customFieldService->upsertCustomFields($customFieldUpdates, $existingFieldsMap, $knownFieldIds);

        $fieldIdMap = [];
        foreach ($fieldResults as $res) {
            if (!empty($res['success']) && !empty($res['custom_field_id'])) { 
                $fieldIdMap[$res['product_id']] = $res['custom_field_id'];
            }
        }
        
        foreach ($productPromotions as &$promo) {
            $pid = $promo['product_id'];
            $promo['custom_field_id'] = $fieldIdMap[$pid] ?? null;
        }
        unset($promo);

        $fieldSuccess = count(array_filter($fieldResults, fn($r) => $r['success']));
        $debugLog[] = "Custom field updates (Multi-cURL): {$fieldSuccess} success";
        
        // 🚀 IZMENA: Zameniti petlju za individualne INSERT-e jednim BATCH INSERT-om
        $this->batchSavePromotionProducts($productPromotions);
        $debugLog[] = "Database records saved/updated in batch.";
        
        // Cleanup expired products (sada će koristiti batch metode)
        $cleanedCount = $this->cleanupExpiredProductsBatch($productPromotions);
        $debugLog[] = "Cleaned {$cleanedCount} expired products";
        
        // 🚀 OPTIMIZACIJA: Direktno ažuriranje lokalnog keša (bez API poziva)
        $this->cacheService->updatePriceCacheDirectly($cachePriceUpdates);
        
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
    public function previewPromotionProducts($filters, $discountPercent) {
        // getProductsByFilters sada vraća i proizvode i varijante
        $items = $this->cacheService->getProductsByFilters($filters);
        
        $preview = [];
        foreach ($items as $item) {
            // Preskačemo stavke bez cene (npr. neki osnovni proizvodi)
            if (empty($item['price']) || (float)$item['price'] <= 0) {
                continue;
            }

            $originalPrice = (float)$item['price'];
            $promoPrice = round($originalPrice * (1 - $discountPercent / 100), 2);
            $savings = $originalPrice - $promoPrice;
            
            $preview[] = [
                'id' => $item['id'], // Ovo je kompozitni ID
                'name' => $item['name'], // Ime je već formatirano za varijante
                'sku' => $item['sku'],
                'original_price' => $originalPrice,
                'promo_price' => $promoPrice,
                'savings' => $savings,
                'savings_percent' => $discountPercent,
                'inventory' => $item['inventory_level'],
                'brand' => $item['brand_name'],
                'is_visible' => $item['is_visible']
            ];
        }
        
        return [
            'total_products' => count($preview),
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
    
    private function updateExpiredPromotions() {
        $now = date('Y-m-d H:i:s');
        $cleanedProductsCount = 0;
        
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
                $allItemsToClean = array_merge($allItemsToClean, $items);

                // Batch update prices (postojeće)
                [$productUpdates, $variantUpdates, $cacheUpdates] = $this->buildRestoreUpdates($items);
                if (!empty($productUpdates)) {
                    $this->api->batchUpdateProducts($productUpdates);
                }
                if (!empty($variantUpdates)) {
                    $this->api->batchUpdateVariants($variantUpdates);
                }
                if (!empty($cacheUpdates)) {
                    $this->cacheService->updatePriceCacheDirectly($cacheUpdates);
                }

                // Brisanje iz DB za ovu promociju
                $this->db->query(
                    "DELETE FROM promotion_products WHERE promotion_id = ? AND store_hash = ?",
                    [$promo['id'], $this->storeHash]
                );
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
        
        return $cleanedProductsCount;
    }
    
    public function cleanupAllProductsBatch() {
        $allProducts = $this->fetchPromotionProductsWithCachePrice(
            "pp.store_hash = ?",
            [$this->storeHash]
        );
        
        if (empty($allProducts)) {
            return 0;
        }
        
        [$productUpdates, $variantUpdates, $cacheUpdates] = $this->buildRestoreUpdates($allProducts);

        // Batch update prices (postojeće)
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
            $this->db->query("DELETE FROM promotion_products WHERE store_hash = ?", [$this->storeHash]);
        }
        
        return $cleanedCount;
    }
    
    private function cleanupExpiredProductsBatch($activeProducts) {
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
            return 0;
        }
        
        [$productUpdates, $variantUpdates, $cacheUpdates] = $this->buildRestoreUpdates($toClean);
        $productIds = $this->getProductsWithoutActivePromotionEntries($toClean, array_column($toClean, 'id'));

        // Batch update prices (postojeće)
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
        $this->db->query(
            "DELETE FROM promotion_products WHERE store_hash = ? AND id IN ($placeholders)",
            array_merge([$this->storeHash], $dbIdsToDelete)
        );
        
        return $cleanedCount;
    }

    /**
     * Batch čišćenje proizvoda za JEDNU specifičnu promociju.
     * Koristi se kada promocija istekne, a korisnik klikne "Sync".
     */
    public function cleanupSinglePromotionBatch($promotionId, $limit = 50) {
        // 1. Dohvati proizvode i varijante vezane za ovu promociju, uključujući njihov PK
        $items = $this->fetchPromotionProductsWithCachePrice(
            "pp.promotion_id = ? AND pp.store_hash = ?",
            [$promotionId, $this->storeHash],
            "LIMIT " . (int)$limit
        );
        
        if (empty($items)) {
            return ['processed' => 0, 'errors' => 0];
        }
        
        $productIds = array_column($items, 'product_id');
        $productUpdates = [];
        $variantUpdates = [];

        // 2. Pripremi vraćanje originalnih cena
        foreach ($items as $item) {
            if ($item['variant_id']) {
                $variantUpdates[] = ['product_id' => $item['product_id'], 'id' => $item['variant_id'], 'price' => $item['original_price'], 'sale_price' => null];
            } else {
                $productUpdates[] = ['product_id' => $item['product_id'], 'price' => $item['original_price'], 'sale_price' => null];
            }
        }
        
        // 3. Vrati cene na BigCommerce
        $productResults = !empty($productUpdates) ? $this->api->batchUpdateProducts($productUpdates) : [];
        $variantResults = !empty($variantUpdates) ? $this->api->batchUpdateVariants($variantUpdates) : [];
        $errors = count(array_filter($productResults, fn($r) => !$r['success'])) + count(array_filter($variantResults, fn($r) => !$r['success']));

        // 4. Ukloni Custom Fields (Batch)
        $productsToClearFields = [];
        
        // 5. Obriši iz lokalne baze koristeći primarne ključeve
        $dbIdsToDelete = array_column($items, 'id');
        $placeholders = str_repeat('?,', count($dbIdsToDelete) - 1) . '?';
        $this->db->query(
            "DELETE FROM promotion_products WHERE store_hash = ? AND id IN ($placeholders)",
            array_merge([$this->storeHash], $dbIdsToDelete)
        );
        
        // 6. Ažuriraj keš direktno
        $productsToClearFields = $this->getProductsWithoutActivePromotionEntries($items, $dbIdsToDelete);
        if (!empty($productsToClearFields)) {
            $this->customFieldService->batchRemovePromotionFields($productsToClearFields);
        }

        $cacheUpdates = [];
        foreach(array_merge($productUpdates, $variantUpdates) as $upd) {
            $cacheUpdates[] = ['product_id' => $upd['product_id'], 'variant_id' => $upd['id'] ?? null, 'price' => $upd['price'], 'sale_price' => $upd['sale_price']];
        }
        $this->cacheService->updatePriceCacheDirectly($cacheUpdates);
        
        return [
            'processed' => count($items),
            'errors' => $errors
        ];
    }
    
    private function batchSavePromotionProducts($promotions) {
        if (empty($promotions)) {
            return;
        }
        
        $values = [];
        $bindings = [];
        
        foreach ($promotions as $promo) {
            // Svaki $promo je sada niz koji sadrži detalje o promociji za jedan proizvod/varijantu
            $values[] = '(?, ?, ?, ?, ?, NOW())';
            $bindings = array_merge($bindings, [
                $this->storeHash,
                $promo['promotion_id'],
                $promo['product_id'],
                $promo['variant_id'] ?? null,
                $promo['custom_field_id'] ?? null
            ]);
        }

        $valuesPlaceholder = implode(', ', $values);
        
        // Tabela `promotion_products` ima UNIQUE KEY na (store_hash, product_id, variant_id)
        $sql = "INSERT INTO promotion_products 
                 (store_hash, promotion_id, product_id, variant_id, custom_field_id, synced_at)
                 VALUES {$valuesPlaceholder}
                 ON DUPLICATE KEY UPDATE 
                 promotion_id = VALUES(promotion_id),
                 custom_field_id = VALUES(custom_field_id),
                 synced_at = VALUES(synced_at)";
                 
        $this->db->query($sql, $bindings);
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

    public function syncSinglePromotion($promotionId) {
        $startTime = microtime(true);
        
        $promotion = $this->promotionModel->findById($promotionId);
        if (!$promotion) {
            throw new \Exception("Promocija ID: {$promotionId} nije pronađena.");
        }

        $products = $this->cacheService->getProductsByFilters(json_decode($promotion['filters'], true));
        
        $stats = $this->processProductsBatch($products, $promotionId);

        $duration = round(microtime(true) - $startTime, 2);
        
        $this->logSync($promotionId, $stats['synced'], $stats['errors'], $duration, "Single Sync: " . $promotion['name']);

        return $stats;
    }

    public function syncSinglePromotionBatch($promotionId, $limit = 50, $offset = 0) {
        $promotion = $this->promotionModel->findById($promotionId);
        if (!$promotion) throw new \Exception("Promocija nije pronađena.");

        $filters = json_decode($promotion['filters'], true);
        
        // 1. Dohvati proizvode i varijante
        $items = $this->cacheService->getProductsByFilters($filters, $limit, $offset);
        if (empty($items)) {
            return ['processed' => 0, 'errors' => 0];
        }

        $productPromotions = [];
        $activePromotions = $this->promotionModel->findActive(); // Za proveru prioriteta

        // 2. Priprema podataka (samo logika, bez API poziva)
        foreach ($items as $item) {
            // Provera da li postoji bolja promocija od ove trenutne
            $bestPromo = $this->calculateBestPromotion($item, $activePromotions);
            
            // Ako je ova promocija ($promotion) ta koja je najbolja (ili jednako dobra), primeni je
            if ($bestPromo && $bestPromo['id'] == $promotion['id']) {
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
                    'promo_price'    => $promoPrice,
                    'promotion_name' => $promotion['name'],
                    'custom_field_value' => $promotion['custom_field_value'] ?? $promotion['name']
                ];
            }
        }

        if (empty($productPromotions)) {
            return [
                'processed' => 0, 
                'errors' => count($items) 
            ];
        }
        // 3. Razdvajanje ažuriranja za proizvode i za varijante
        $productUpdates = [];
        $variantUpdates = [];
        foreach ($productPromotions as $p) {
            if ($p['variant_id']) {
                $variantUpdates[] = [
                    'product_id' => $p['product_id'],
                    'id'         => $p['variant_id'], // Za variant API, 'id' je ID varijante
                    'price'      => $p['original_price'],
                    'sale_price' => $p['promo_price']
                ];
            } else {
                $productUpdates[] = [
                    'product_id' => $p['product_id'],
                    'price'      => $p['original_price'],
                    'sale_price' => $p['promo_price']
                ];
            }
        }

        // 4. BATCH API pozivi za cene
        if (!empty($productUpdates)) {
            $this->api->batchUpdateProducts($productUpdates);
        }
        if (!empty($variantUpdates)) {
            $this->api->batchUpdateVariants($variantUpdates);
        }

        // 5. BATCH CUSTOM FIELDS (Multi Curl)
        $cfUpdates = [];
        foreach ($productPromotions as $p) {
            // Custom field se postavlja samo na osnovni proizvod, ne na varijantu
            $cfUpdates[] = [
                'product_id' => $p['product_id'],
                'field_value' => $p['custom_field_value'] ?? $p['promotion_name']
            ];
        }
        // Uklanjamo duplikate jer više varijanti može biti vezano za isti osnovni proizvod
        $cfUpdates = array_values(array_unique($cfUpdates, SORT_REGULAR));
        
        // OPTIMIZACIJA: Mapiranje postojećih polja iz keša
        $existingFieldsMap = [];
        foreach ($items as $item) {
            // Potrebna su nam samo polja sa osnovnog proizvoda
            if (empty($item['variant_id'])) {
                $existingFieldsMap[$item['product_id']] = is_string($item['custom_fields']) ? json_decode($item['custom_fields'], true) : $item['custom_fields'];
            }
        }
        
        // OPTIMIZACIJA: Dohvatanje poznatih ID-eva iz promotion_products tabele
        $productIds = array_column($productPromotions, 'product_id');
        $knownFieldIds = [];
        if (!empty($productIds)) {
             $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
             $rows = $this->db->fetchAll(
                 "SELECT product_id, custom_field_id FROM promotion_products WHERE product_id IN ($placeholders) AND store_hash = ?", 
                 array_merge($productIds, [$this->storeHash])
             );
             $knownFieldIds = array_column($rows, 'custom_field_id', 'product_id');
        }
        
        $cfResults = $this->customFieldService->upsertCustomFields($cfUpdates, $existingFieldsMap, $knownFieldIds);

        // Mapiranje CF ID-eva za bazu
        $fieldIdMap = [];
        foreach ($cfResults as $res) {
            if (!empty($res['success']) && !empty($res['custom_field_id'])) {
                $fieldIdMap[$res['product_id']] = $res['custom_field_id'] ?? null;
            }
        }
        foreach ($productPromotions as &$p) {
            // Dodeljujemo ID custom polja svim stavkama istog proizvoda
            $p['custom_field_id'] = $fieldIdMap[$p['product_id']] ?? null;
        }
        unset($p);

        // 6. BATCH DB SAVE
        $this->batchSavePromotionProducts($productPromotions);

        // 7. UPDATE CACHE DIRECTLY
        $cachePriceUpdates = [];
        foreach ($productPromotions as $p) {
            $cachePriceUpdates[] = [
                'product_id' => $p['product_id'],
                'variant_id' => $p['variant_id'],
                'price'      => $p['original_price'],
                'sale_price' => $p['promo_price']
            ];
        }
        $this->cacheService->updatePriceCacheDirectly($cachePriceUpdates);

        return [
            'processed' => count($productPromotions),
            'errors' => 0
        ];

        return [
            'processed' => count($productPromotions),
            'errors' => count($items) - count($productPromotions) // Razlika su oni koji nisu prošli proveru prioriteta
        ];
    }

    private function processProductsBatch($products, $specificPromoId = null) {
        $synced = 0;
        $errors = 0;
        
        // Dohvati sve trenutno aktivne promocije za poređenje prioriteta
        $activePromotions = $this->promotionModel->findActive();

        foreach ($products as $product) {
            try {
                // Pronađi najbolju promociju za ovaj konkretan proizvod
                $bestPromo = $this->calculateBestPromotion($product, $activePromotions);
                
                if ($bestPromo) {
                    // Primeni cenu i custom field
                    $this->applyPromotionToProduct($product, $bestPromo);
                    $synced++;
                } else {
                    // Ako proizvod više ne upada ni u jednu promociju, vrati originalnu cenu
                    $this->removePromotionFromProduct($product);
                }
            } catch (\Exception $e) {
                $errors++;
                error_log("Error syncing product {$product['product_id']}: " . $e->getMessage());
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
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
        foreach ($filters as $key => $value) {
            if (empty($value) && $value !== '0' && $value !== 0) continue;

            if (strpos($key, 'custom_field:') === 0) {
                $fieldName = substr($key, 13);
                $productFields = $product['custom_fields'] ?? [];
                // Ako je string (iz baze), dekodiraj ga
                if (is_string($productFields)) $productFields = json_decode($productFields, true);
                
                $match = false;
                foreach ($productFields as $field) {
                    if ($field['name'] === $fieldName) {
                        // Podrška za niz vrednosti (OR) ili jednu vrednost
                        if (is_array($value)) {
                            if (in_array($field['value'], $value)) $match = true;
                        } else {
                            if ($field['value'] == $value) $match = true;
                        }
                    }
                }
                if (!$match) return false;
                continue;
            }

            switch ($key) {
                case 'brand_id':
                    if ($product['brand_id'] != $value) return false;
                    break;
                case 'categories:in':
                    $productCats = $product['categories'] ?? [];
                    if (is_string($productCats)) $productCats = json_decode($productCats, true);
                    
                    $requiredCats = is_array($value) ? $value : explode(',', $value);
                    // Proveri presek nizova (da li ima bar jednu zajedničku kategoriju)
                    if (empty(array_intersect($productCats, $requiredCats))) return false;
                    break;
                case 'price:min':
                    if ($product['price'] < $value) return false;
                    break;
                case 'price:max':
                    if ($product['price'] > $value) return false;
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
        return true;
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
            $this->api->batchUpdateVariants([[
                'product_id' => $product['product_id'],
                'id' => $product['variant_id'],
                'price' => $originalPrice,
                'sale_price' => $promoPrice
            ]]);
        } else {
            $this->api->updateProductPrice($product['product_id'], $originalPrice, $promoPrice);
        }

        // 2. Custom Field
        $customFieldId = $this->customFieldService->setPromotionField(
            $product['product_id'],
            $promotion['custom_field_value'] ?? $promotion['name']
        );

        // 3. Save to DB
        $this->batchSavePromotionProducts([[
            'promotion_id' => $promotion['id'],
            'product_id' => $product['product_id'],
            'variant_id' => $product['variant_id'] ?? null,
            'product_name' => $product['name'],
            'original_price' => $originalPrice,
            'promo_price' => $promoPrice,
            'custom_field_id' => $customFieldId
        ]]);

        $this->cacheService->updatePriceCacheDirectly([[
            'product_id' => $product['product_id'],
            'variant_id' => $product['variant_id'] ?? null,
            'price' => $originalPrice,
            'sale_price' => $promoPrice
        ]]);
    }

    /**
     * Uklanja promociju sa proizvoda (vraća staru cenu).
     */
    private function removePromotionFromProduct($product) {
        if (!empty($product['variant_id'])) {
            $this->api->batchUpdateVariants([[
                'product_id' => $product['product_id'],
                'id' => $product['variant_id'],
                'price' => $product['price'],
                'sale_price' => null
            ]]);
            $this->db->query(
                "DELETE FROM promotion_products WHERE product_id = ? AND variant_id = ? AND store_hash = ?",
                [$product['product_id'], $product['variant_id'], $this->storeHash]
            );
        } else {
            $this->api->updateProductPrice($product['product_id'], $product['price'], null);
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
            'price' => $product['price'],
            'sale_price' => null
        ]]);
        return;
        // Vraćamo originalnu cenu (koja je u 'price' polju u products_cache jer cache čuva original)
        // ILI ako je proizvod već snižen, moramo paziti. 
        // Pretpostavka: $product['price'] iz keša je bazna cena.
        
        $this->api->updateProductPrice($product['product_id'], $product['price'], null); // null briše sale_price
        
        // Brisanje custom field-a bi zahtevalo da znamo ID fielda, što je komplikovano u single modu.
        // Zato je batch cleanupAll mnogo bolji.
        
        $shouldRemoveField = !$this->hasActivePromotionEntriesForProduct($product['product_id'], []);

        // Brisanje iz baze
        $this->db->query(
            "DELETE FROM promotion_products WHERE product_id = ? AND store_hash = ?",
            [$product['product_id'], $this->storeHash]
        );
    }

    private function getPromotionItemKey($productId, $variantId = null) {
        return $variantId ? "v_{$variantId}" : "p_{$productId}";
    }

    private function buildRestoreUpdates(array $items): array {
        $productUpdates = [];
        $variantUpdates = [];
        $cacheUpdates = [];

        foreach ($items as $item) {
            $originalPrice = isset($item['original_price']) ? (float)$item['original_price'] : null;
            if ($originalPrice === null || $originalPrice <= 0) {
                continue;
            }

            $cacheUpdates[] = [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'price' => $originalPrice,
                'sale_price' => null
            ];

            if (!empty($item['variant_id'])) {
                $variantUpdates[] = [
                    'product_id' => $item['product_id'],
                    'id' => $item['variant_id'],
                    'price' => $originalPrice,
                    'sale_price' => null
                ];
            } else {
                $productUpdates[] = [
                    'product_id' => $item['product_id'],
                    'price' => $originalPrice,
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
}
