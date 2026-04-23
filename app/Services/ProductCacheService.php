<?php
namespace App\Services;

use App\Models\Database;

class ProductCacheService {
    private $db;
    private $api;
    private $storeHash;
    
    // Konstanta za veličinu batch-a. MySQL obično odlično rukuje sa 1000-5000.
    private const DB_BATCH_SIZE = 100; 
    
    public function __construct(Database $db = null) {
        $this->db = $db ?? Database::getInstance();
        
        // Store hash se sada dobija iz deljene DB instance
        $this->storeHash = $this->db->getStoreContext();
        
        if (!$this->storeHash) {
            throw new \Exception("Store context required for ProductCacheService");
        }
        $this->ensureProductsCacheSchema();
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
        $sql = "SELECT * FROM products_cache WHERE store_hash = ?";
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
        $sql = "SELECT COUNT(*) as total FROM products_cache WHERE store_hash = ?";
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
        $sql = "";

        foreach ($filters as $key => $value) {
            // Preskačemo prazne vrijednosti, ali dozvoljavamo nulu ('0' ili 0)
            if (empty($value) && $value !== '0' && $value !== 0) {
                continue;
            }

            // ---------------------------------------------------------
            // 1. OBRADA DINAMIČKIH CUSTOM FIELDS FILTERA
            // ---------------------------------------------------------
            if (strpos($key, 'custom_field:') === 0) {
                // Izdvajamo pravi naziv polja (npr. "Materijal" iz "custom_field:Materijal")
                $fieldName = substr($key, 13); 
                
                // Provjera da li je vrijednost niz (multiselect -> OR logika)
                if (is_array($value)) {
                    // SCENARIO: Korisnik je izabrao npr. Materijal = Pamuk ILI Svila
                    $orConditions = [];
                    foreach ($value as $subValue) {
                        // Tražimo JSON objekat unutar niza custom_fields
                        $orConditions[] = "JSON_CONTAINS(custom_fields, JSON_OBJECT('name', ?, 'value', ?))";
                        $params[] = $fieldName;
                        $params[] = (string)$subValue; // Osiguravamo string tip
                    }
                    
                    // Spajamo uslove sa OR i stavljamo u zagradu
                    if (!empty($orConditions)) {
                        $sql .= " AND (" . implode(' OR ', $orConditions) . ")";
                    }
                } else {
                    // SCENARIO: Običan filter, jedna vrijednost
                    $sql .= " AND JSON_CONTAINS(custom_fields, JSON_OBJECT('name', ?, 'value', ?))";
                    $params[] = $fieldName;
                    $params[] = (string)$value;
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
                    $sql .= " AND brand_id = ?";
                    $params[] = $value;
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

        return $sql;
    }

}
