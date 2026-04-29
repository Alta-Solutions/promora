<?php
namespace App\Services;

use App\Models\Database;

class ProductCacheService {
    private $db;
    private $api;
    private $storeHash;
    
    // Konstanta za veličinu batch-a. MySQL obično odlično rukuje sa 1000-5000.
    private const DB_BATCH_SIZE = 100;
    private const CUSTOM_FIELD_INDEX_BACKFILL_BATCH_SIZE = 500;
    
    public function __construct(Database $db = null) {
        $this->db = $db ?? Database::getInstance();
        
        // Store hash se sada dobija iz deljene DB instance
        $this->storeHash = $this->db->getStoreContext();
        
        if (!$this->storeHash) {
            throw new \Exception("Store context required for ProductCacheService");
        }
        $this->ensureProductsCacheSchema();
        $this->ensureCustomFieldFilterIndexSchema();
        $this->ensureCustomFieldFilterIndexData();
        $this->api = new BigCommerceAPI();
    }
    
    public function fullSync() {
        $startTime = microtime(true);
        echo "\n=== Starting Full Product Cache Sync for Store: {$this->storeHash} ===\n";
        
        // IZMENA: Uključujemo varijante, slike i custom polja u API poziv
        $allProducts = $this->api->getProducts([
            'include' => 'variants,images,custom_fields'
        ]);

        $totalProducts = count($allProducts);
        
        echo "Fetched {$totalProducts} products from BigCommerce\n";
        
        $successCount = 0;
        $errorCount = 0;
        
        // 🚀 KLJUČNA PROMENA: Razbijanje svih proizvoda u batch-eve (pakete) za brži unos u bazu
        $batches = array_chunk($allProducts, self::DB_BATCH_SIZE);
        $totalBatches = count($batches);
        
        foreach ($batches as $index => $batch) {
            try {
                // Koristimo novu batch metodu
                $this->batchCacheProducts($batch); 
                $successCount += count($batch);
                
                $progress = round(($successCount / $totalProducts) * 100);
                echo "Progress: " . $successCount . "/{$totalProducts} (Batch " . ($index + 1) . "/{$totalBatches} - {$progress}%)\n";
                
            } catch (\Exception $e) {
                // U slučaju greške, logujemo i računamo greške
                $errorCount += count($batch);
                error_log("Error caching batch (Batch " . ($index + 1) . "): " . $e->getMessage());
            }
        }
        
        $duration = round(microtime(true) - $startTime, 2);
        
        echo "\n=== Full Sync Complete ===\n";
        echo "Success: {$successCount}, Errors: {$errorCount}, Duration: {$duration}s\n\n";
        
        return [
            'total' => $totalProducts,
            'success' => $successCount,
            'errors' => $errorCount,
            'duration' => $duration
        ];
    }
    
    /**
     * Keširanje proizvoda masovnim unosom (Batch Insertion)
     * * @param array $products Array proizvoda iz BigCommerce API-ja
     */
    public function batchCacheProducts($products) {
        if (empty($products)) {
            return;
        }

        $sqlTemplate = "INSERT INTO products_cache 
            (id, store_hash, product_id, variant_id, type, name, sku, price, sale_price, cost_price, retail_price, weight, 
             inventory_level, inventory_warning_level, brand_id, brand_name, 
             categories, is_visible, is_featured, availability, `condition`, 
             option_values, date_created, date_modified, custom_fields, images, cached_at)
             VALUES ";
             
        $valuePlaceholders = [];
        $params = [];
        $now = date('Y-m-d H:i:s');
        
        // Inicijalizacija PriceLogger-a i provera da li je Omnibus modul aktivan
        $priceLogger = new PriceLogger($this->db);
        $storeConfig = $this->db->fetchOne("SELECT enable_omnibus, currency FROM bigcommerce_stores WHERE store_hash = ?", [$this->storeHash]);
        
        // Niz za grupno logovanje cena
        $pricesToLog = [];
        
        foreach ($products as $product) {
            // --- 1. Keširanje osnovnog proizvoda ---
            $compositeId = $this->generateCompositeId($product['id'], null);
            $valuePlaceholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            
            // Priprema parametara za jedan red (mora biti striktan redosled)
            $params = array_merge($params, [
                $compositeId,
                $this->storeHash,
                $product['id'],
                null, // variant_id je NULL za osnovni proizvod
                'product', // type
                $product['name'],
                $product['sku'] ?? null,
                $product['price'] ?? null,
                $product['sale_price'] ?? null,
                $product['cost_price'] ?? null,
                $product['retail_price'] ?? null,
                $product['weight'] ?? null,
                $product['inventory_level'] ?? 0,
                $product['inventory_warning_level'] ?? 0,
                $product['brand_id'] ?? null,
                $product['brand_name'] ?? null,
                json_encode($product['categories'] ?? []),
                $product['is_visible'] ?? false,
                $product['is_featured'] ?? false,
                $product['availability'] ?? 'available',
                $product['condition'] ?? 'new',
                null, // option_values su samo za varijante
                $product['date_created'] ?? null,
                $product['date_modified'] ?? null,
                json_encode($product['custom_fields'] ?? []),
                json_encode($product['images'] ?? []),
                $now // Postavljamo cached_at na trenutno vreme
            ]);

            // Priprema cene osnovnog proizvoda za grupno logovanje
            $effectiveProductPrice = $this->getEffectivePrice($product['price'] ?? null, $product['sale_price'] ?? null);
            if ($storeConfig && $storeConfig['enable_omnibus'] && $effectiveProductPrice !== null && $effectiveProductPrice > 0) {
                $pricesToLog[] = [
                    'product_id' => (int)$product['id'],
                    'variant_id' => null,
                    'price' => $effectiveProductPrice,
                    'currency' => $storeConfig['currency'] ?? 'USD'
                ];
            }

            // --- 2. Keširanje varijanti (ako postoje) ---
            if (!empty($product['variants'])) {
                // --- ISPRAVKA: Logika za preskakanje "lažnih" varijanti ---
                // Ako proizvod ima samo jednu varijantu i ta varijanta nema 'option_values',
                // to znači da je u pitanju jednostavan proizvod bez opcija. BigCommerce ga i dalje
                // vraća unutar 'variants' niza. Preskačemo obradu da ne bismo upisali dupli
                // red u bazu, jer su osnovni podaci već obrađeni u koraku #1.
                $isSimpleProduct = count($product['variants']) === 1 && empty($product['variants'][0]['option_values']);

                if (!$isSimpleProduct) {
                    foreach ($product['variants'] as $variant) {
                        $compositeId = $this->generateCompositeId($product['id'], $variant['id']);
                        $valuePlaceholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                        
                        // Varijanta može imati svoju cenu, ili nasleđuje cenu od osnovnog proizvoda
                        $variantPrice = $variant['price'] ?? $product['price'];
                        $effectiveVariantPrice = $this->getEffectivePrice($variantPrice, $variant['sale_price'] ?? null);

                        // Konstrukcija imena varijante, npr. "Majica (Boja: Plava, Veličina: L)"
                        $variantName = $product['name'];
                        $optionStrings = [];
                        if (!empty($variant['option_values'])) {
                            foreach ($variant['option_values'] as $option) {
                                $optionStrings[] = $option['label'];
                            }
                        }
                        if(!empty($optionStrings)) {
                            $variantName .= ' (' . implode(', ', $optionStrings) . ')';
                        }

                        $params = array_merge($params, [
                            $compositeId,
                            $this->storeHash,
                            $product['id'],
                            $variant['id'],
                            'variant', // type
                            $variantName,
                            $variant['sku'] ?? null,
                            $variantPrice,
                            $variant['sale_price'] ?? null,
                            $variant['cost_price'] ?? null,
                            $variant['retail_price'] ?? null,
                            $variant['weight'] ?? $product['weight'],
                            $variant['inventory_level'] ?? 0,
                            $variant['inventory_warning_level'] ?? 0,
                            $product['brand_id'] ?? null,
                            $product['brand_name'] ?? null,
                            json_encode($product['categories'] ?? []),
                            $product['is_visible'] ?? false,
                            $product['is_featured'] ?? false,
                            $product['availability'] ?? 'available',
                            $product['condition'] ?? 'new',
                            json_encode($variant['option_values'] ?? []),
                            $product['date_created'] ?? null,
                            $product['date_modified'] ?? null,
                            json_encode($product['custom_fields'] ?? []), // Varijante dele custom fields sa parentom
                            json_encode(isset($variant['image_url']) && !empty($variant['image_url']) ? [['url_standard' => $variant['image_url']]] : $product['images'] ?? []),
                            $now
                        ]);

                        // Priprema cene varijante za grupno logovanje
                        if ($storeConfig && $storeConfig['enable_omnibus'] && $effectiveVariantPrice !== null && $effectiveVariantPrice > 0) {
                            $pricesToLog[] = [
                                'product_id' => (int)$product['id'],
                                'variant_id' => (int)$variant['id'],
                                'price' => $effectiveVariantPrice,
                                'currency' => $storeConfig['currency'] ?? 'USD'
                            ];
                        }
                    }
                }
            }
        }

        $sql = $sqlTemplate . implode(', ', $valuePlaceholders) . "
             ON DUPLICATE KEY UPDATE
             name = VALUES(name),
             sku = VALUES(sku),
             price = VALUES(price),
             sale_price = VALUES(sale_price),
             cost_price = VALUES(cost_price),
             retail_price = VALUES(retail_price),
             weight = VALUES(weight),
             inventory_level = VALUES(inventory_level),
             inventory_warning_level = VALUES(inventory_warning_level),
             brand_id = VALUES(brand_id),
             brand_name = VALUES(brand_name),
             categories = VALUES(categories),
             is_visible = VALUES(is_visible),
             is_featured = VALUES(is_featured),
             availability = VALUES(availability),
             `condition` = VALUES(`condition`),
             option_values = VALUES(option_values),
             date_created = VALUES(date_created),
             date_modified = VALUES(date_modified),
             custom_fields = VALUES(custom_fields),
             images = VALUES(images),
             cached_at = NOW()"; // Koristimo NOW() za finalni upis/update

        // --- DEBUGGING: Hvatanje greške iz baze ---
        try {
            // Prvo, proverimo da li imamo prazan batch.
            if (empty($params)) {
                return; // Ništa za upis, izlazimo iz metode.
            }
            $this->db->query($sql, $params);
            $this->syncCustomFieldFilterIndex($products);
        } catch (\PDOException $e) {
            // Ako dođe do greške prilikom izvršavanja upita, ispisujemo sve detalje.
            header('Content-Type: text/plain; charset=utf-8'); // Osigurava ispravan prikaz
            echo "!!! GREŠKA PRILIKOM UPISA U BAZU !!!\n\n";
            echo "Poruka greške: " . $e->getMessage() . "\n\n";
            
            echo "--- SQL UPIT --- (Broj redova za unos: " . count($valuePlaceholders) . ")\n";
            echo $sql . "\n\n";
            
            echo "--- PARAMETRI (" . count($params) . " komada) ---\n";
            print_r($params);
            
            // Zaustavljamo izvršavanje da bismo mogli da analiziramo grešku.
            die();
        }
        // --- KRAJ DEBUGGING KODA ---
        
        // --- 3. Grupni upis svih prikupljenih cena ---
        if ($storeConfig && $storeConfig['enable_omnibus'] && !empty($pricesToLog)) {
            $priceLogger->logPricesBatch($this->storeHash, $pricesToLog);
        }
    }
    
    /**
     * OPTIMIZACIJA: Direktno ažuriranje cena u lokalnom kešu bez API poziva.
     * Ovo eliminiše potrebu za "read-after-write" API pozivom.
     */
    public function updatePriceCacheDirectly(array $updates) {
        if (empty($updates)) return;

        // IZMENA: Ažuriranje se sada radi na osnovu product_id i variant_id
        $sql = "UPDATE products_cache SET price = ?, sale_price = ?, cached_at = NOW() 
                WHERE product_id = ? AND variant_id <=> ? AND store_hash = ?";
        
        foreach ($updates as $update) {
            // Ako je sale_price null, u bazu upisujemo NULL (ili 0 zavisno od strukture, ovde NULL)
            // Ali pazi: products_cache tabela treba da dozvoli NULL za sale_price.
            // Ako API update šalje 0 za brisanje, ovde možemo staviti 0 ili NULL.
            $salePrice = $update['sale_price'] ?? null;
            if ($salePrice === 0 || $salePrice === 0.0) $salePrice = null;

            $this->db->query($sql, [
                $update['price'],     // Originalna cena (koja je sada 'price' na BC)
                $salePrice,           // Nova akcijska cena (ili null)
                $update['product_id'],
                $update['variant_id'] ?? null, // Koristimo <=> pa je null bezbedno
                $this->storeHash
            ]);
        }

        $storeConfig = $this->db->fetchOne(
            "SELECT enable_omnibus, currency FROM bigcommerce_stores WHERE store_hash = ?",
            [$this->storeHash]
        );

        if (!$storeConfig || !$storeConfig['enable_omnibus']) {
            return;
        }

        $pricesToLog = [];
        foreach ($updates as $update) {
            $effectivePrice = $this->getEffectivePrice($update['price'] ?? null, $update['sale_price'] ?? null);
            if ($effectivePrice === null || $effectivePrice <= 0) {
                continue;
            }

            $pricesToLog[] = [
                'product_id' => (int)$update['product_id'],
                'variant_id' => isset($update['variant_id']) ? (int)$update['variant_id'] : null,
                'price' => $effectivePrice,
                'currency' => $storeConfig['currency'] ?? 'USD',
            ];
        }

        if (!empty($pricesToLog)) {
            $priceLogger = new PriceLogger($this->db);
            $priceLogger->logPricesBatch($this->storeHash, $pricesToLog);
        }
    }
    
    // --------------------------------------------------------------------------------------------------
    // OSTATAK KODA JE ISTI KAO PRETHODNO
    // --------------------------------------------------------------------------------------------------

    public function getProductsByFilters($filters = [], $limit = null, $offset = 0) {
        $sql = "SELECT pc.* FROM products_cache pc WHERE pc.store_hash = ?";
        $params = [$this->storeHash];

        $sql .= $this->buildFilterQuery($filters, $params);
                
        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        }
        
        $this->logResponse($sql, $params);

        return $this->db->fetchAll($sql, $params);
    }

    public function logResponse($query, $params) {
        $logFile = __DIR__ . '/../logs/filter-query.log'; 

        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'query'    => $query,
            'params'    => $params,
        ];
        
        $logString = "Query: {$query} \n";
        $logString .= print_r($logEntry, true) . "\n----------------------------------------\n";
        
        file_put_contents($logFile, $logString, FILE_APPEND);
    }
    
    public function getCacheStats() {
        return [
            'total_products' => $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM products_cache WHERE store_hash = ?", 
                [$this->storeHash]
            )['cnt'],
            'visible_products' => $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM products_cache WHERE store_hash = ? AND is_visible = 1", 
                [$this->storeHash]
            )['cnt'],
            'last_cached' => $this->db->fetchOne(
                "SELECT MAX(cached_at) as last FROM products_cache WHERE store_hash = ?", 
                [$this->storeHash]
            )['last']
        ];
    }
    
    public function syncModifiedProducts() {
        $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        $products = $this->api->getProducts([
            'date_modified:min' => $yesterday,
            'include' => 'variants,images,custom_fields'
        ]);
        
        // Podesite da i ova metoda koristi batch unos
        $this->batchCacheProducts($products);
        
        return count($products);
    }
    
    public function clearCache() {
        $this->db->query("DELETE FROM products_cache WHERE store_hash = ?", [$this->storeHash]);
        $this->db->query("DELETE FROM product_custom_field_index WHERE store_hash = ?", [$this->storeHash]);
        return true;
    }
    
    private function generateCompositeId($productId, $variantId = null) {
        // IZMENA: Generiše jedinstveni string ID koji uključuje i store_hash i variant_id
        if ($variantId) {
            return "{$this->storeHash}_{$productId}_{$variantId}";
        }
        return "{$this->storeHash}_{$productId}_base";
    }

    private function ensureProductsCacheSchema(): void {
        $columns = $this->db->fetchAll(
            "SELECT COLUMN_NAME, DATA_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products_cache'"
        );

        if (empty($columns)) {
            throw new \Exception("products_cache tabela ne postoji.");
        }

        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[$column['COLUMN_NAME']] = strtolower($column['DATA_TYPE']);
        }

        $indexes = $this->db->fetchAll(
            "SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products_cache'
             ORDER BY INDEX_NAME, SEQ_IN_INDEX"
        );

        $indexMap = [];
        foreach ($indexes as $index) {
            $indexMap[$index['INDEX_NAME']][] = $index['COLUMN_NAME'];
        }

        $isCompatible =
            (($columnMap['id'] ?? null) === 'varchar') &&
            isset($columnMap['type'], $columnMap['variant_id'], $columnMap['option_values']) &&
            isset($indexMap['store_product_variant']) &&
            $indexMap['store_product_variant'] === ['store_hash', 'product_id', 'variant_id'];

        if ($isCompatible) {
            return;
        }

        $this->db->query("ALTER TABLE products_cache MODIFY COLUMN id VARCHAR(255) NOT NULL");

        if (!isset($columnMap['type'])) {
            $this->db->query("ALTER TABLE products_cache ADD COLUMN type ENUM('product','variant') NOT NULL DEFAULT 'product' AFTER store_hash");
        }

        if (!isset($columnMap['variant_id'])) {
            $this->db->query("ALTER TABLE products_cache ADD COLUMN variant_id INT UNSIGNED NULL AFTER product_id");
        }

        if (!isset($columnMap['option_values'])) {
            $this->db->query("ALTER TABLE products_cache ADD COLUMN option_values JSON NULL AFTER `condition`");
        }

        if (isset($indexMap['unique_store_product'])) {
            $this->db->query("ALTER TABLE products_cache DROP INDEX unique_store_product");
        }

        if (!isset($indexMap['store_product_variant'])) {
            $this->db->query("ALTER TABLE products_cache ADD UNIQUE KEY store_product_variant (store_hash, product_id, variant_id)");
        }

        if (!isset($indexMap['idx_type'])) {
            $this->db->query("ALTER TABLE products_cache ADD INDEX idx_type (type)");
        }
    }

    private function getEffectivePrice($price, $salePrice): ?float {
        $basePrice = is_numeric($price) ? (float)$price : null;
        $discountedPrice = is_numeric($salePrice) ? (float)$salePrice : null;

        if ($discountedPrice !== null && $discountedPrice > 0) {
            return $discountedPrice;
        }

        return $basePrice;
    }

    public function countProductsByFilters($filters = []) {
        $sql = "SELECT COUNT(*) as total FROM products_cache pc WHERE pc.store_hash = ?";
        $params = [$this->storeHash];
        
        $sql .= $this->buildFilterQuery($filters, $params);

        $result = $this->db->fetchOne($sql, $params);
        return (int)$result['total'];
    }

    /**
     * Pomoćna metoda za generisanje SQL WHERE uslova na osnovu filtera.
     * * @param array $filters Niz filtera (ključ => vrijednost)
     * @param array $params Referenca na niz parametara za PDO bindovanje
     * @return string SQL string koji počinje sa AND (npr. " AND brand_id = ? ...")
     */
    private function buildFilterQuery($filters, &$params) {
        if (!is_array($filters)) {
            return "";
        }

        $excludeFilters = [];
        if (isset($filters['exclude']) && is_array($filters['exclude'])) {
            $excludeFilters = $filters['exclude'];
            unset($filters['exclude']);
        }

        $sql = "";

        foreach ($filters as $key => $value) {
            if ($key === 'exclude') {
                continue;
            }

            // Preskačemo prazne vrijednosti, ali dozvoljavamo nulu ('0' ili 0)
            if (empty($value) && $value !== '0' && $value !== 0) {
                continue;
            }

            // ---------------------------------------------------------
            // 1. OBRADA DINAMIČKIH CUSTOM FIELDS FILTERA
            // ---------------------------------------------------------
            if (strpos($key, 'custom_field:') === 0) {
                // Izdvajamo pravi naziv polja (npr. "Materijal" iz "custom_field:Materijal")
                $fieldName = $this->normalizeEscapedUnicodeString(substr($key, 13));
                
                // Provjera da li je vrijednost niz (multiselect -> OR logika)
                $fieldValues = is_array($value) ? $value : [$value];
                $fieldValues = array_values(array_filter(array_map(function($item) {
                    return $this->normalizeEscapedUnicodeString((string)$item);
                }, $fieldValues), function($item) {
                    return $item !== '';
                }));

                if (!empty($fieldValues)) {
                    $placeholders = implode(',', array_fill(0, count($fieldValues), '?'));
                    $sql .= " AND EXISTS (
                        SELECT 1
                        FROM product_custom_field_index pcfi
                        WHERE pcfi.store_hash = ?
                          AND pcfi.product_id = pc.product_id
                          AND pcfi.field_name = ?
                          AND pcfi.field_value IN ($placeholders)
                    )";

                    $params[] = $this->storeHash;
                    $params[] = $fieldName;
                    foreach ($fieldValues as $fieldValue) {
                        $params[] = $fieldValue;
                    }
                }
                
                // Bitno: nastavljamo petlju da ne bi ušli u switch ispod
                continue; 
            }

            // ---------------------------------------------------------
            // 2. OBRADA STANDARDNIH FILTERA
            // ---------------------------------------------------------
            switch ($key) {
                case 'categories:in':
                    // Očekujemo niz ID-eva ili string odvojen zarezima
                    $categoryIds = is_array($value) ? $value : explode(',', $value);
                    $categoryConditions = [];
                    
                    foreach ($categoryIds as $catId) {
                        if (trim($catId) === '') continue;
                        // Pretpostavka: kolona 'categories' je JSON niz ID-eva (npr. [12, 45, 99])
                        $categoryConditions[] = "JSON_CONTAINS(categories, ?, '$')";
                        $params[] = (int)$catId; // JSON integer match
                    }
                    
                    if (!empty($categoryConditions)) {
                        $sql .= " AND (" . implode(' OR ', $categoryConditions) . ")";
                    }
                    break;

                case 'brand_id':
                    $brandIds = is_array($value) ? $value : explode(',', (string)$value);
                    $brandIds = array_values(array_filter(array_map(function($brandId) {
                        return trim((string)$brandId);
                    }, $brandIds), function($brandId) {
                        return $brandId !== '';
                    }));

                    if (count($brandIds) === 1) {
                        $sql .= " AND brand_id = ?";
                        $params[] = (int)$brandIds[0];
                    } elseif (!empty($brandIds)) {
                        $placeholders = implode(',', array_fill(0, count($brandIds), '?'));
                        $sql .= " AND brand_id IN ($placeholders)";

                        foreach ($brandIds as $brandId) {
                            $params[] = (int)$brandId;
                        }
                    }
                    break;

                case 'product_id':
                    $sql .= " AND product_id = ?";
                    $params[] = (int)$value;
                    break;

                case 'price:min':
                    $sql .= " AND price >= ?";
                    $params[] = $value;
                    break;

                case 'price:max':
                    $sql .= " AND price <= ?";
                    $params[] = $value;
                    break;

                case 'inventory_level:min':
                    $sql .= " AND inventory_level >= ?";
                    $params[] = $value;
                    break;

                case 'is_visible':
                    $sql .= " AND is_visible = ?";
                    $params[] = $value ? 1 : 0;
                    break;

                case 'is_featured':
                    $sql .= " AND is_featured = ?";
                    $params[] = $value ? 1 : 0;
                    break;

                case 'sku':
                    // Može biti tačan match ili LIKE, zavisno od potrebe. 
                    // Obično je SKU specifičan pa je tačan match brži.
                    $sql .= " AND sku = ?";
                    $params[] = $value;
                    break;
                
                case 'sku:in':
                // 1. Proveravamo da li je vrednost niz, ako nije, pretvaramo string u niz
                $skuArray = is_array($value) ? $value : explode(',', $value);
                
                // 2. Čistimo prazna mesta i eventualne navodnike iz tvog inputa
                $skuArray = array_map(function($item) {
                    return trim($item, " '\""); // Uklanja razmake, jednostruke i dvostruke navodnike
                }, $skuArray);
                
                // 3. Filtriramo prazne vrednosti (za slučaj da je neko poslao npr. "SKU1,,SKU2")
                $skuArray = array_filter($skuArray);

                if (!empty($skuArray)) {
                    // 4. Pravimo tačan broj upitnika, npr: ?, ?, ?
                    $placeholders = implode(',', array_fill(0, count($skuArray), '?'));
                    $sql .= " AND sku IN ($placeholders)";
                    
                    // 5. Dodajemo svaku pojedinačnu vrednost u $params niz
                    foreach ($skuArray as $sku) {
                        $params[] = $sku;
                    }
                }
                break;

                case 'name:like':
                    $sql .= " AND name LIKE ?";
                    $params[] = '%' . $value . '%';
                    break;
            }
        }

        return $sql . $this->buildExcludeFilterQuery($excludeFilters, $params);
    }

    private function buildExcludeFilterQuery(array $excludeFilters, array &$params): string {
        $sql = "";

        foreach ($excludeFilters as $key => $value) {
            if ($key === 'exclude' || (empty($value) && $value !== '0' && $value !== 0)) {
                continue;
            }

            $subqueryParams = [$this->storeHash];
            $subqueryWhere = $this->buildFilterQuery([$key => $value], $subqueryParams);

            if (trim($subqueryWhere) === '') {
                continue;
            }

            $sql .= " AND pc.id NOT IN (
                SELECT pc.id
                FROM products_cache pc
                WHERE pc.store_hash = ?{$subqueryWhere}
            )";
            $params = array_merge($params, $subqueryParams);
        }

        return $sql;
    }

    public function getCustomFieldFilterValues(string $fieldName, string $search = '', int $limit = 50): array {
        $fieldName = $this->normalizeEscapedUnicodeString($fieldName);
        $search = $this->normalizeEscapedUnicodeString($search);
        $limit = max(1, min(100, $limit));

        if ($fieldName === '') {
            return [];
        }

        $rows = $this->fetchIndexedCustomFieldFilterValueRows($fieldName, $search, $limit);

        if (empty($rows) && !$this->hasIndexedCustomFieldRows($fieldName)) {
            $this->rebuildCustomFieldFilterIndexFromCache();
            $rows = $this->fetchIndexedCustomFieldFilterValueRows($fieldName, $search, $limit);
        }

        if (empty($rows)) {
            $rows = $this->fetchCustomFieldFilterValueRowsFromCache($fieldName, $search, $limit);
        }

        return array_map(function($row) {
            return [
                'value' => $row['field_value'],
                'label' => $row['field_value'],
                'count' => (int)($row['product_count'] ?? 0),
            ];
        }, $rows);
    }

    public function getCustomFieldFilterNames(): array {
        $rows = $this->db->fetchAll(
            "SELECT field_name, COUNT(DISTINCT product_id) AS product_count
             FROM product_custom_field_index
             WHERE store_hash = ?
             GROUP BY field_name
             ORDER BY field_name ASC",
            [$this->storeHash]
        );

        if (empty($rows)) {
            $this->rebuildCustomFieldFilterIndexFromCache();
            $rows = $this->db->fetchAll(
                "SELECT field_name, COUNT(DISTINCT product_id) AS product_count
                 FROM product_custom_field_index
                 WHERE store_hash = ?
                 GROUP BY field_name
                 ORDER BY field_name ASC",
                [$this->storeHash]
            );
        }

        return array_map(function($row) {
            return [
                'name' => (string)$row['field_name'],
                'product_count' => (int)($row['product_count'] ?? 0),
            ];
        }, $rows);
    }

    public function getProductFilterOptions(string $search = '', int $limit = 50): array {
        $search = $this->normalizeEscapedUnicodeString($search);
        $limit = max(1, min(100, $limit));
        $searchTerms = $this->parseProductSearchTerms($search);
        $parentSelectAllPrefix = '__promotion_select_all_parent_variants__:';

        $sql = "SELECT product_id, variant_id, type, name, sku, price, sale_price
                FROM products_cache
                WHERE store_hash = ?
                  AND sku IS NOT NULL
                  AND TRIM(sku) <> ''";
        $params = [$this->storeHash];

        if ($search !== '') {
            $searchLike = '%' . $search . '%';
            $searchConditions = [
                "sku LIKE ?",
                "name LIKE ?",
            ];
            $params[] = $searchLike;
            $params[] = $searchLike;

            if (count($searchTerms) > 1) {
                $placeholders = implode(',', array_fill(0, count($searchTerms), '?'));
                $searchConditions[] = "sku IN ($placeholders)";
                foreach ($searchTerms as $term) {
                    $params[] = $term;
                }

                foreach ($searchTerms as $term) {
                    $searchConditions[] = "sku LIKE ?";
                    $params[] = $term . '%';
                }
            }

            $sql .= " AND (" . implode(' OR ', $searchConditions) . ")";

            $sql .= " ORDER BY
                        CASE
                            WHEN sku = ? THEN 0
                            " . (count($searchTerms) > 1 ? "WHEN sku IN (" . implode(',', array_fill(0, count($searchTerms), '?')) . ") THEN 1" : "") . "
                            WHEN sku LIKE ? THEN 1
                            WHEN name LIKE ? THEN 2
                            ELSE 3
                        END,
                        name ASC,
                        sku ASC";
            $params[] = $search;
            if (count($searchTerms) > 1) {
                foreach ($searchTerms as $term) {
                    $params[] = $term;
                }
            }
            $params[] = $search . '%';
            $params[] = $searchLike;
        } else {
            $sql .= " ORDER BY name ASC, sku ASC";
        }

        $sql .= " LIMIT " . (int)$limit;

        $rows = $this->db->fetchAll($sql, $params);

        if (empty($rows)) {
            return [];
        }

        $productIds = array_values(array_unique(array_map(function($row) {
            return (int)($row['product_id'] ?? 0);
        }, $rows)));
        $productIds = array_values(array_filter($productIds, fn($productId) => $productId > 0));

        $parentNamesByProductId = [];
        $variantRowsByProductId = [];

        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $groupRows = $this->db->fetchAll(
                "SELECT product_id, variant_id, type, name, sku, price, sale_price
                 FROM products_cache
                 WHERE store_hash = ?
                   AND product_id IN ($placeholders)
                 ORDER BY product_id ASC, variant_id IS NULL DESC, name ASC, sku ASC",
                array_merge([$this->storeHash], $productIds)
            );

            foreach ($groupRows as $groupRow) {
                $productId = (int)($groupRow['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                if (empty($groupRow['variant_id'])) {
                    $parentNamesByProductId[$productId] = (string)($groupRow['name'] ?? '');
                    continue;
                }

                if (empty($groupRow['sku']) || trim((string)$groupRow['sku']) === '') {
                    continue;
                }

                if (!isset($variantRowsByProductId[$productId])) {
                    $variantRowsByProductId[$productId] = [];
                }

                $variantRowsByProductId[$productId][] = $groupRow;
            }
        }

        $options = [];
        $emittedProductGroups = [];
        $emittedValues = [];

        foreach ($rows as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            $productVariantRows = $variantRowsByProductId[$productId] ?? [];

            if (!empty($productVariantRows)) {
                if (isset($emittedProductGroups[$productId])) {
                    continue;
                }

                $emittedProductGroups[$productId] = true;
                $parentName = $parentNamesByProductId[$productId] ?: (string)($row['name'] ?? '');
                $variantValues = array_values(array_unique(array_filter(array_map(function($variantRow) {
                    return (string)($variantRow['sku'] ?? '');
                }, $productVariantRows), function($sku) {
                    return trim($sku) !== '';
                })));

                $options[] = [
                    'value' => $parentSelectAllPrefix . $productId,
                    'label' => $parentName,
                    'name' => $parentName,
                    'sku' => '',
                    'regular_price' => null,
                    'sale_price' => null,
                    'product_id' => $productId,
                    'variant_id' => null,
                    'type' => 'parent_variant_group',
                    'is_parent_select_all' => true,
                    'variant_values' => $variantValues,
                    'variant_count' => count($variantValues),
                ];

                foreach ($productVariantRows as $variantRow) {
                    $variantOption = $this->mapProductFilterOptionRow($variantRow);
                    $variantOption['parent_name'] = $parentName;
                    $variantOption['is_variant'] = true;
                    $variantOption['parent_variant_values'] = $variantValues;
                    $variantOption['parent_variant_count'] = count($variantValues);

                    if (isset($emittedValues[$variantOption['value']])) {
                        continue;
                    }

                    $emittedValues[$variantOption['value']] = true;
                    $options[] = $variantOption;
                }

                continue;
            }

            $option = $this->mapProductFilterOptionRow($row);
            if (isset($emittedValues[$option['value']])) {
                continue;
            }

            $emittedValues[$option['value']] = true;
            $options[] = $option;
        }

        return $options;
    }

    public function getProductFilterPage(string $search = '', int $page = 1, int $perPage = 50): array {
        $search = $this->normalizeEscapedUnicodeString($search);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $searchFilter = $this->buildProductFilterSearchFilter($search);
        $where = $searchFilter['where'];
        $matchParams = $searchFilter['params'];

        $countRow = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT product_id) AS total
             FROM products_cache
             WHERE {$where}",
            $matchParams
        );

        $total = (int)($countRow['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $groupRows = $this->db->fetchAll(
            "SELECT
                product_id,
                COALESCE(MAX(CASE WHEN variant_id IS NULL THEN name END), MIN(name)) AS sort_name,
                COALESCE(MAX(CASE WHEN variant_id IS NULL THEN sku END), MIN(sku)) AS sort_sku
             FROM products_cache
             WHERE {$where}
             GROUP BY product_id
             ORDER BY sort_name ASC, sort_sku ASC, product_id ASC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
            $matchParams
        );

        $productIds = array_values(array_filter(array_map(function($row) {
            return (int)($row['product_id'] ?? 0);
        }, $groupRows), function($productId) {
            return $productId > 0;
        }));

        $groups = [];
        $flatProducts = [];

        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $detailRows = $this->db->fetchAll(
                "SELECT product_id, variant_id, type, name, sku, price, sale_price, option_values, images
                 FROM products_cache
                 WHERE store_hash = ?
                   AND product_id IN ($placeholders)
                   AND (
                        variant_id IS NULL
                        OR (sku IS NOT NULL AND TRIM(sku) <> '')
                   )
                 ORDER BY product_id ASC, variant_id IS NULL DESC, name ASC, sku ASC",
                array_merge([$this->storeHash], $productIds)
            );

            $rowsByProductId = [];
            foreach ($detailRows as $detailRow) {
                $productId = (int)($detailRow['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                if (!isset($rowsByProductId[$productId])) {
                    $rowsByProductId[$productId] = [];
                }

                $rowsByProductId[$productId][] = $detailRow;
            }

            foreach ($productIds as $productId) {
                $rows = $rowsByProductId[$productId] ?? [];
                if (empty($rows)) {
                    continue;
                }

                $parentRow = null;
                $variantRows = [];

                foreach ($rows as $row) {
                    if (empty($row['variant_id'])) {
                        $parentRow = $row;
                        continue;
                    }

                    if (!empty($row['sku']) && trim((string)$row['sku']) !== '') {
                        $variantRows[] = $row;
                    }
                }

                if ($parentRow === null && !empty($variantRows)) {
                    $parentRow = $this->buildSyntheticParentFilterRow($productId, $variantRows[0]);
                }

                $variants = array_map(function($row) use ($parentRow) {
                    $variant = $this->mapProductFilterOptionRow($row);
                    $variant['is_parent'] = false;
                    $variant['parent_name'] = (string)($parentRow['name'] ?? '');
                    $variant['is_selectable'] = trim((string)($variant['sku'] ?? '')) !== '';
                    return $variant;
                }, $variantRows);

                $parent = $parentRow ? $this->mapProductFilterOptionRow($parentRow) : null;
                if ($parent) {
                    $parent['is_parent'] = true;
                    $parent['has_variants'] = !empty($variants);
                    $parent['variant_count'] = count($variants);
                    $parent['is_selectable'] = trim((string)($parent['sku'] ?? '')) !== '';
                }

                $groupSkus = [];
                if ($parent && !empty($parent['is_selectable'])) {
                    $groupSkus[] = (string)$parent['sku'];
                    $flatProducts[] = $parent;
                }

                foreach ($variants as $variant) {
                    if (!empty($variant['is_selectable'])) {
                        $groupSkus[] = (string)$variant['sku'];
                        $flatProducts[] = $variant;
                    }
                }

                $groups[] = [
                    'product_id' => $productId,
                    'parent' => $parent,
                    'variants' => $variants,
                    'has_variants' => !empty($variants),
                    'variant_count' => count($variants),
                    'skus' => array_values(array_unique($groupSkus)),
                ];
            }
        }

        return [
            'groups' => $groups,
            'products' => $flatProducts,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    public function getProductFilterSkusForSearch(string $search = ''): array {
        $search = $this->normalizeEscapedUnicodeString($search);
        $searchFilter = $this->buildProductFilterSearchFilter($search);

        $rows = $this->db->fetchAll(
            "SELECT DISTINCT pc.sku
             FROM products_cache pc
             WHERE pc.store_hash = ?
               AND pc.product_id IN (
                    SELECT DISTINCT product_id
                    FROM products_cache
                    WHERE {$searchFilter['where']}
               )
               AND pc.sku IS NOT NULL
               AND TRIM(pc.sku) <> ''
             ORDER BY pc.sku ASC",
            array_merge([$this->storeHash], $searchFilter['params'])
        );

        $skus = array_values(array_filter(array_map(function($row) {
            return trim((string)($row['sku'] ?? ''));
        }, $rows), function($sku) {
            return $sku !== '';
        }));

        return [
            'skus' => $skus,
            'count' => count($skus),
        ];
    }

    private function mapProductFilterOptionRow(array $row): array {
        $salePrice = $row['sale_price'];
        $hasSalePrice = is_numeric($salePrice) && (float)$salePrice > 0;

        return [
            'value' => (string)$row['sku'],
            'label' => (string)$row['name'],
            'name' => (string)$row['name'],
            'sku' => (string)$row['sku'],
            'regular_price' => is_numeric($row['price']) ? (float)$row['price'] : null,
            'sale_price' => $hasSalePrice ? (float)$salePrice : null,
            'product_id' => isset($row['product_id']) ? (int)$row['product_id'] : null,
            'variant_id' => isset($row['variant_id']) ? (int)$row['variant_id'] : null,
            'type' => (string)($row['type'] ?? 'product'),
            'image_url' => $this->extractProductImageUrl($row['images'] ?? null),
            'option_label' => $this->extractProductOptionLabel($row['option_values'] ?? null),
            'is_variant' => !empty($row['variant_id']),
            'is_selectable' => trim((string)($row['sku'] ?? '')) !== '',
        ];
    }

    private function buildSyntheticParentFilterRow(int $productId, array $variantRow): array {
        return [
            'product_id' => $productId,
            'variant_id' => null,
            'type' => 'product',
            'name' => (string)($variantRow['name'] ?? ''),
            'sku' => '',
            'price' => null,
            'sale_price' => null,
            'option_values' => null,
            'images' => $variantRow['images'] ?? null,
        ];
    }

    private function buildProductFilterSearchFilter(string $search): array {
        $search = $this->normalizeEscapedUnicodeString($search);
        $searchTerms = $this->parseProductSearchTerms($search);
        $where = "store_hash = ?
                  AND sku IS NOT NULL
                  AND TRIM(sku) <> ''";
        $params = [$this->storeHash];

        if ($search !== '') {
            $searchLike = '%' . $search . '%';
            $searchConditions = [
                "sku LIKE ?",
                "name LIKE ?",
            ];
            $params[] = $searchLike;
            $params[] = $searchLike;

            if (count($searchTerms) > 1) {
                $placeholders = implode(',', array_fill(0, count($searchTerms), '?'));
                $searchConditions[] = "sku IN ($placeholders)";
                foreach ($searchTerms as $term) {
                    $params[] = $term;
                }

                foreach ($searchTerms as $term) {
                    $searchConditions[] = "sku LIKE ?";
                    $params[] = $term . '%';
                }
            }

            $where .= " AND (" . implode(' OR ', $searchConditions) . ")";
        }

        return [
            'where' => $where,
            'params' => $params,
        ];
    }

    private function extractProductImageUrl($images): string {
        if (is_string($images)) {
            $decodedImages = json_decode($images, true);
            $images = is_array($decodedImages) ? $decodedImages : [];
        }

        if (!is_array($images) || empty($images)) {
            return '';
        }

        foreach ($images as $image) {
            if (is_string($image) && trim($image) !== '') {
                return trim($image);
            }

            if (!is_array($image)) {
                continue;
            }

            foreach (['url_thumbnail', 'url_standard', 'url_tiny', 'url_zoom', 'image_url', 'url'] as $key) {
                if (!empty($image[$key])) {
                    return (string)$image[$key];
                }
            }
        }

        return '';
    }

    private function extractProductOptionLabel($optionValues): string {
        if (is_string($optionValues)) {
            $decodedValues = json_decode($optionValues, true);
            $optionValues = is_array($decodedValues) ? $decodedValues : [];
        }

        if (!is_array($optionValues) || empty($optionValues)) {
            return '';
        }

        $labels = [];
        foreach ($optionValues as $optionValue) {
            if (!is_array($optionValue)) {
                continue;
            }

            $displayName = trim((string)($optionValue['option_display_name'] ?? $optionValue['display_name'] ?? $optionValue['option_name'] ?? ''));
            $label = trim((string)($optionValue['label'] ?? $optionValue['value'] ?? ''));

            if ($label === '') {
                continue;
            }

            $labels[] = $displayName !== '' ? "{$displayName}: {$label}" : $label;
        }

        return implode(', ', $labels);
    }

    private function parseProductSearchTerms(string $search): array {
        if ($search === '') {
            return [];
        }

        $terms = preg_split('/[\s,]+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($terms)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(function($term) {
            return trim($this->normalizeEscapedUnicodeString((string)$term));
        }, $terms), function($term) {
            return $term !== '';
        })));
    }

    private function fetchIndexedCustomFieldFilterValueRows(string $fieldName, string $search, int $limit): array {
        $sql = "SELECT field_value, COUNT(DISTINCT product_id) AS product_count
                FROM product_custom_field_index
                WHERE store_hash = ?
                  AND field_name = ?";
        $params = [$this->storeHash, $fieldName];

        if ($search !== '') {
            $sql .= " AND field_value LIKE ?";
            $params[] = '%' . $search . '%';
        }

        $sql .= " GROUP BY field_value
                  ORDER BY product_count DESC, field_value ASC
                  LIMIT " . (int)$limit;

        return $this->db->fetchAll($sql, $params);
    }

    private function hasIndexedCustomFieldRows(string $fieldName): bool {
        $row = $this->db->fetchOne(
            "SELECT 1
             FROM product_custom_field_index
             WHERE store_hash = ?
               AND field_name = ?
             LIMIT 1",
            [$this->storeHash, $fieldName]
        );

        return !empty($row);
    }

    private function fetchCustomFieldFilterValueRowsFromCache(string $fieldName, string $search, int $limit): array {
        $rows = $this->db->fetchAll(
            "SELECT product_id, custom_fields
             FROM products_cache
             WHERE store_hash = ?
               AND variant_id IS NULL
               AND custom_fields IS NOT NULL",
            [$this->storeHash]
        );

        $counts = [];

        foreach ($rows as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $productFieldRows = $this->extractCustomFieldIndexRows($productId, $row['custom_fields'] ?? []);
            foreach ($productFieldRows as $productFieldRow) {
                if ($productFieldRow['field_name'] !== $fieldName) {
                    continue;
                }

                if ($search !== '' && mb_stripos($productFieldRow['field_value'], $search, 0, 'UTF-8') === false) {
                    continue;
                }

                if (!isset($counts[$productFieldRow['field_value']])) {
                    $counts[$productFieldRow['field_value']] = 0;
                }

                $counts[$productFieldRow['field_value']]++;
            }
        }

        $resultRows = [];
        foreach ($counts as $fieldValue => $productCount) {
            $resultRows[] = [
                'field_value' => $fieldValue,
                'product_count' => $productCount,
            ];
        }

        usort($resultRows, function($left, $right) {
            if ($left['product_count'] === $right['product_count']) {
                return strcmp($left['field_value'], $right['field_value']);
            }

            return $right['product_count'] <=> $left['product_count'];
        });

        return array_slice($resultRows, 0, $limit);
    }

    private function ensureCustomFieldFilterIndexSchema(): void {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `product_custom_field_index` (
                `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `store_hash` VARCHAR(255) NOT NULL,
                `product_id` INT UNSIGNED NOT NULL,
                `field_name` VARCHAR(255) NOT NULL,
                `field_value` VARCHAR(255) NOT NULL,
                UNIQUE KEY `uniq_store_product_field_value` (`store_hash`, `product_id`, `field_name`, `field_value`),
                INDEX `idx_store_field_name_value` (`store_hash`, `field_name`, `field_value`),
                INDEX `idx_store_product` (`store_hash`, `product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function ensureCustomFieldFilterIndexData(): void {
        $existingIndexRow = $this->db->fetchOne(
            "SELECT id
             FROM product_custom_field_index
             WHERE store_hash = ?
             LIMIT 1",
            [$this->storeHash]
        );

        if ($existingIndexRow) {
            return;
        }

        $existingCacheRow = $this->db->fetchOne(
            "SELECT product_id
             FROM products_cache
             WHERE store_hash = ?
               AND variant_id IS NULL
             LIMIT 1",
            [$this->storeHash]
        );

        if (!$existingCacheRow) {
            return;
        }

        $this->rebuildCustomFieldFilterIndexFromCache();
    }

    private function rebuildCustomFieldFilterIndexFromCache(): void {
        $this->db->query(
            "DELETE FROM product_custom_field_index WHERE store_hash = ?",
            [$this->storeHash]
        );

        $offset = 0;

        do {
            $rows = $this->db->fetchAll(
                "SELECT product_id, custom_fields
                 FROM products_cache
                 WHERE store_hash = ?
                   AND variant_id IS NULL
                 ORDER BY product_id ASC
                 LIMIT " . self::CUSTOM_FIELD_INDEX_BACKFILL_BATCH_SIZE . " OFFSET " . (int)$offset,
                [$this->storeHash]
            );

            if (empty($rows)) {
                break;
            }

            $indexRows = [];
            foreach ($rows as $row) {
                $indexRows = array_merge(
                    $indexRows,
                    $this->extractCustomFieldIndexRows((int)$row['product_id'], $row['custom_fields'] ?? [])
                );
            }

            $this->insertCustomFieldIndexRows($indexRows);
            $offset += self::CUSTOM_FIELD_INDEX_BACKFILL_BATCH_SIZE;
        } while (count($rows) === self::CUSTOM_FIELD_INDEX_BACKFILL_BATCH_SIZE);
    }

    private function syncCustomFieldFilterIndex(array $products): void {
        if (empty($products)) {
            return;
        }

        $productIds = [];
        $indexRows = [];

        foreach ($products as $product) {
            $productId = (int)($product['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $productIds[$productId] = $productId;
            $indexRows = array_merge(
                $indexRows,
                $this->extractCustomFieldIndexRows($productId, $product['custom_fields'] ?? [])
            );
        }

        if (empty($productIds)) {
            return;
        }

        $this->deleteCustomFieldIndexRowsByProductIds(array_values($productIds));
        $this->insertCustomFieldIndexRows($indexRows);
    }

    private function extractCustomFieldIndexRows(int $productId, $customFields): array {
        if (is_string($customFields)) {
            $customFields = json_decode($customFields, true);
        }

        if (!is_array($customFields)) {
            return [];
        }

        $rows = [];
        $seen = [];

        foreach ($customFields as $field) {
            $fieldName = $this->normalizeEscapedUnicodeString((string)($field['name'] ?? ''));
            $fieldValue = $this->normalizeEscapedUnicodeString((string)($field['value'] ?? ''));

            if ($fieldName === '' || $fieldValue === '') {
                continue;
            }

            $dedupeKey = $productId . '|' . $fieldName . '|' . $fieldValue;
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $rows[] = [
                'product_id' => $productId,
                'field_name' => $fieldName,
                'field_value' => $fieldValue,
            ];
        }

        return $rows;
    }

    private function deleteCustomFieldIndexRowsByProductIds(array $productIds): void {
        if (empty($productIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $params = array_merge([$this->storeHash], array_map('intval', $productIds));

        $this->db->query(
            "DELETE FROM product_custom_field_index
             WHERE store_hash = ?
               AND product_id IN ($placeholders)",
            $params
        );
    }

    private function insertCustomFieldIndexRows(array $rows): void {
        if (empty($rows)) {
            return;
        }

        $placeholders = [];
        $params = [];

        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?)';
            $params[] = $this->storeHash;
            $params[] = (int)$row['product_id'];
            $params[] = $row['field_name'];
            $params[] = $row['field_value'];
        }

        $this->db->query(
            "INSERT INTO product_custom_field_index
             (store_hash, product_id, field_name, field_value)
             VALUES " . implode(', ', $placeholders) . "
             ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)",
            $params
        );
    }

    public function deleteProductCustomFieldIndex(int $productId): void {
        $this->deleteCustomFieldIndexRowsByProductIds([$productId]);
    }

    private function normalizeEscapedUnicodeString(string $value): string {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($matches) {
            return json_decode('"\\u' . $matches[1] . '"');
        }, trim($value));
    }

}
