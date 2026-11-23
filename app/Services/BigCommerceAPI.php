<?php
namespace App\Services;

use App\Models\Database;

class BigCommerceAPI {
    private $baseUrl;
    private $headers;
    private $requestCount = 0;
    private $storeHash;
    private $db;
    private $webhookSuppressionService;
    
    // Dodajemo varijable za praćenje limita
    private $rateLimitLeft = 100; // Početna pretpostavka
    private $rateLimitResetMs = 0;
    private $minRequestsRemaining = 3; // Sigurnosni buffer (kočimo kad ostane manje od 3)
    
    private const BATCH_SIZE = 10;
    private const MAX_RETRIES = 3;
    
    /**
     * KRITIČNA ISPRAVKA: Automatsko dohvatanje tokena iz baze za Worker/CLI
     */
    public function __construct($storeHash = null, $accessToken = null) {
        // 1. Dohvati kontekst prodavnice
        $db = Database::getInstance();
        $this->db = $db;
        $this->storeHash = $storeHash ?? $db->getStoreContext();
        
        // 2. Pokušaj da nađeš token (Prioritet: Argument > Sesija > Config)
        $accessToken = $accessToken ?? $_SESSION['access_token'] ?? (\Config::$BC_ACCESS_TOKEN ?? null);
        
        // --- NOVO: Fallback na bazu podataka (Za Worker/CLI) ---
        // Ako nemamo token, ali znamo za koju je prodavnicu, vadimo ga iz baze
        if (!$accessToken && $this->storeHash) {
            try {
                $store = $db->fetchOne(
                    "SELECT access_token FROM bigcommerce_stores WHERE store_hash = ?", 
                    [$this->storeHash]
                );
                if ($store && !empty($store['access_token'])) {
                    $accessToken = $store['access_token'];
                }
            } catch (\Exception $e) {
                // Ignorišemo grešku baze ovde, bacićemo exception ispod ako token i dalje fali
            }
        }
        // -------------------------------------------------------

        // Provera da li su parametri postavljeni
        if (!$this->storeHash || !$accessToken) {
            throw new \Exception("BigCommerceAPI Error: Store hash or access token missing. \nContext: " . 
                ($this->storeHash ? "StoreHash: {$this->storeHash}" : "No StoreHash") . 
                ", Token: " . ($accessToken ? "Present" : "Missing"));
        }

        // 3. Formatiraj Base URL
        $this->baseUrl = rtrim(\Config::$BC_API_URL, '/') . '/stores/' . $this->storeHash . '/v3/'; 
        
        // 4. Postavi Headere
        $this->headers = [
            'X-Auth-Token: ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
    }

    private function parseHeaders($headerContent) {
        $headers = [];
        $headerLines = explode("\r\n", $headerContent);
        
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $key = trim($key);
                $value = trim($value);
                $headers[$key] = $value;

                // --- NOVO: Hvatamo Rate Limit hedere ---
                // BigCommerce nekad šalje 'X-Rate-Limit-Requests-Left', nekad bez crtica, pa pokrivamo varijacije
                if (stripos($key, 'X-Rate-Limit-Requests-Left') !== false) {
                    $this->rateLimitLeft = (int)$value;
                }
                if (stripos($key, 'X-Rate-Limit-Time-Reset-Ms') !== false) {
                    $this->rateLimitResetMs = (int)$value;
                }
            }
        }
        return $headers;
    }
    
    /**
     * NOVO: Pametna pauza prije zahtjeva
     */
    private function checkRateLimit() {
        if ($this->rateLimitLeft <= $this->minRequestsRemaining) {
            // Ako je reset vrijeme 0 ili nije stiglo, stavi default 1 sekundu
            $sleepTimeMs = $this->rateLimitResetMs > 0 ? $this->rateLimitResetMs : 1000;
            
            // Dodajemo mali buffer od 100ms da budemo sigurni
            $sleepTimeMs += 100; 

            // Logujemo pauzu (korisno za debug worker-a)
            if (php_sapi_name() === 'cli') {
                echo "⏳ Rate limit hit! Waiting " . round($sleepTimeMs/1000, 2) . "s... \n";
            } else {
                error_log("Rate limit hit. Sleeping {$sleepTimeMs}ms");
            }

            // usleep prima mikrosekunde, pa množimo sa 1000
            usleep($sleepTimeMs * 1000);
            
            // Resetujemo brojčanik nakon spavanja (pretpostavka da je pun)
            $this->rateLimitLeft = 100; 
        }
    }

    protected function request($method, $endpoint, $data = null) {
        $method = strtoupper($method);
        $this->assertReadOnlyAllowsMethod($method, $endpoint);
        $this->suppressWebhookForWrite($method, $endpoint, $data);

        $retries = 0;

        do {
            // --- NOVO: Provjeri limit PRIJE svakog poziva ---
            $this->checkRateLimit();
            
            $this->requestCount++;

            $startedAt = microtime(true);
            $ch = curl_init($this->baseUrl . $endpoint);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $this->headers,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HEADER         => true,
            ]);
            
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            } elseif ($method === 'DELETE' && $data !== null) {
                 curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $info = curl_getinfo($ch);

            $headerSize = $info['header_size'];
            $headerContent = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            $status = $info['http_code'];

            curl_close($ch);
            
            // Parsiranjem hedera se automatski ažuriraju $this->rateLimitLeft i $this->rateLimitResetMs
            $parsedHeaders = $this->parseHeaders($headerContent);

            $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
            $this->logResponse($method, $endpoint, $status, strlen((string)$body), $error, $durationMs, $retries, $parsedHeaders);

            if ($status >= 200 && $status < 300 && !$error) {
                return [
                    'status' => $status,
                    'body' => json_decode($body, true) ?? $body,
                    'headers' => $parsedHeaders
                ];
            }

            // Ako dobijemo 429 (Too Many Requests) uprkos provjeri, pauziramo i probamo opet
            if ($status === 429) {
                $retries++;
                $wait = $parsedHeaders['X-Rate-Limit-Time-Reset-Ms'] ?? 2000; // Default 2s ako fali header
                $wait += 100; // mali buffer
                
                if (php_sapi_name() === 'cli') echo "🛑 429 Hit! Force sleep {$wait}ms...\n";
                usleep($wait * 1000);
                
                // Ažuriraj interni brojač
                $this->rateLimitLeft = 0;
                continue;
            } else if ($status >= 500) {
                $retries++;
                $delay = $retries * 2;
                error_log("Server error. Retrying in {$delay} seconds...");
                sleep($delay);
            } else {
                break;
            }

        } while ($retries < self::MAX_RETRIES);

        throw new \Exception("BigCommerce API Error (Status: {$status}, Endpoint: {$endpoint}): " . $error . " Body: " . $body);
    }

    /**
     * 🚀 NOVO: Izvodi višestruke API pozive konkurentno koristeći cURL Multi.
     * * @param array $requests Niz zahteva. Svaki zahtev: ['method', 'endpoint', 'data']
     * @return array Rezultati, gde je svaki rezultat ['status', 'body', 'headers', 'error']
     */
    public function multiRequest(array $requests): array {
        if (empty($requests)) {
            return [];
        }

        foreach ($requests as $request) {
            $this->assertReadOnlyAllowsMethod(
                (string)($request['method'] ?? 'GET'),
                (string)($request['endpoint'] ?? '')
            );
        }

        // OPTIMIZACIJA: Ograničavamo konkurentnost da izbegnemo 429 "Too many simultaneous requests"
        // BigCommerce obično dozvoljava 3-5 paralelnih konekcija.
        $concurrencyLimit = max(1, min(10, (int)($_ENV['BC_HTTP_CONCURRENCY'] ?? getenv('BC_HTTP_CONCURRENCY') ?: 5)));
        $batches = array_chunk($requests, $concurrencyLimit, true);
        $results = [];

        foreach ($batches as $batch) {
            $this->suppressWebhooksForWriteBatch($batch);

            // Provera rate limita pre slanja batch-a
            $this->checkRateLimit();

            $mh = curl_multi_init();
            $handles = [];
            
            foreach ($batch as $index => $req) {
                $method = strtoupper($req['method']);
                $endpoint = $req['endpoint'];
                $data = $req['data'] ?? null;
                
                $url = $this->baseUrl . $endpoint;
                $ch = curl_init($url);
                
                $this->requestCount++;

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => $this->headers,
                    CURLOPT_CUSTOMREQUEST  => $method,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_HEADER         => true,
                ]);

                if (in_array($method, ['POST', 'PUT'])) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                } elseif ($method === 'DELETE' && $data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                
                curl_multi_add_handle($mh, $ch);
                $handles[$index] = $ch;
            }

            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) {
                    curl_multi_select($mh, 0.1); 
                }
            } while ($active && $status == CURLM_OK);

            foreach ($handles as $index => $ch) {
                $content = curl_multi_getcontent($ch);
                $error = curl_error($ch);
                $info = curl_getinfo($ch);

                $headerSize = $info['header_size'];
                $headerContent = substr($content, 0, $headerSize);
                $body = substr($content, $headerSize);

                $status = $info['http_code'];
                $parsedHeaders = $this->parseHeaders($headerContent);

                $results[$index] = [
                    'status'  => $status,
                    'body'    => json_decode($body, true) ?? $body,
                    'headers' => $parsedHeaders,
                    'error'   => $error,
                ];

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
            
            // Mali predah između batch-eva
            usleep(50000); 
        }
        
        // Sortiramo rezultate da odgovaraju redosledu ulaznih zahteva
        ksort($results);
        
        return $results;
    }

    public function call($method, $endpoint, $data = null) {
        return $this->request($method, $endpoint, $data);
    }

    public function getProducts($filters = []) {
        $allProducts = [];
        $this->eachProductPage($filters, function (array $products) use (&$allProducts): void {
            $allProducts = array_merge($allProducts, $products);
        });

        return $allProducts;
    }

    public function eachProductPage(array $filters, callable $callback): void {
        $validFilters = $this->validateFilters($filters);
        $validFilters['limit'] = 250;
        $page = 1;
        $maxPages = 200;
        
        do {
            try {
                $validFilters['page'] = $page; 
                $queryString = http_build_query($validFilters);
                $endpoint = 'catalog/products?' . $queryString;
                
                $response = $this->request('GET', $endpoint);
                
                // Uklonjen print_r($response);
                $products = $response['body']['data'] ?? [];
                
                $meta = $response['body']['meta'] ?? [];
                $pagination = $meta['pagination'] ?? [];
                $totalPages = $pagination['total_pages'] ?? 1;
                $currentPage = $pagination['current_page'] ?? $page;

                $callback($products, $pagination);
                
                $page++;

                if ($currentPage >= $totalPages) {
                    break;
                }

                if ($page > $maxPages) {
                    throw new \RuntimeException("BigCommerce product page safety limit reached at {$maxPages} pages.");
                }
                
            } catch (\Exception $e) {
                error_log("Error fetching products page {$page}: " . $e->getMessage());
                throw $e;
            }
            
        } while (count($products) > 0);
    }

    public function getTaxSettings(): array {
        $response = $this->request('GET', 'tax/settings');
        return $response['body']['data'] ?? [];
    }

    public function getTaxRates(array $filters = []): array {
        $endpoint = 'tax/rates';
        if (!empty($filters)) {
            $endpoint .= '?' . http_build_query($filters);
        }

        $response = $this->request('GET', $endpoint);
        return $response['body']['data'] ?? [];
    }
    
    /**
     * Batch update products using BigCommerce native batch endpoint
     */
    public function batchUpdateProducts($updates) {
        if (empty($updates)) {
            return [];
        }
        
        $batches = array_chunk($updates, self::BATCH_SIZE);
        $results = [];
        
        foreach ($batches as $batch) {
            $results = array_merge($results, $this->executeProductUpdateBatch(array_values($batch)));
        }

        return $results;
    }

    private function executeProductUpdateBatch(array $batch): array {
        if (empty($batch)) {
            return [];
        }

        try {
            $payload = array_map(function($update) {
                $productId = $update['product_id'] ?? $update['id'] ?? null;

                return [
                    'id' => (int)$productId,
                    'sale_price' => $this->normalizeSalePriceForApi($update['sale_price'] ?? null)
                ];
            }, $batch);
            $requestedProductIds = [];
            foreach ($payload as $item) {
                if (!empty($item['id'])) {
                    $requestedProductIds[(int)$item['id']] = true;
                }
            }
            $resolvedProductIds = [];

            $response = $this->request('PUT', 'catalog/products', $payload);
            $results = [];

            if (isset($response['body']['data'])) {
                foreach ($response['body']['data'] as $updatedProduct) {
                    if (!empty($updatedProduct['id'])) {
                        $resolvedProductIds[(int)$updatedProduct['id']] = true;
                    }
                    $results[] = [
                        'success' => true,
                        'product_id' => $updatedProduct['id']
                    ];
                }
            }

            if (isset($response['body']['errors'])) {
                 foreach ((array)$response['body']['errors'] as $error) {
                    $error = is_array($error) ? $error : ['message' => (string)$error];
                    $results[] = [
                        'success' => false,
                        'product_id' => $error['product_id'] ?? null,
                        'error' => $error['message'] ?? 'Unknown batch error'
                    ];
                    if (!empty($error['product_id'])) {
                        $resolvedProductIds[(int)$error['product_id']] = true;
                    }
                }
            }

            foreach (array_keys($requestedProductIds) as $productId) {
                if (isset($resolvedProductIds[$productId])) {
                    continue;
                }

                $results[] = [
                    'success' => false,
                    'product_id' => $productId,
                    'error' => 'Product was not returned in BigCommerce batch response'
                ];
            }

            return $results;
        } catch (\Exception $e) {
            return $this->retryProductUpdateBatchWithoutIndexedFailures($batch, $e);
        }
    }

    private function retryProductUpdateBatchWithoutIndexedFailures(array $batch, \Exception $exception): array {
        $indexedErrors = $this->extractBulkOperationIndexedErrors($exception->getMessage());
        if (empty($indexedErrors)) {
            return $this->buildFailedProductUpdateResults($batch, $exception->getMessage());
        }

        $failed = [];
        $retryBatch = [];
        $removed = false;

        foreach (array_values($batch) as $index => $update) {
            if (array_key_exists($index, $indexedErrors)) {
                $failed[] = [
                    'success' => false,
                    'product_id' => $update['product_id'] ?? $update['id'] ?? null,
                    'error' => $indexedErrors[$index],
                ];
                $removed = true;
                continue;
            }

            $retryBatch[] = $update;
        }

        if (!$removed) {
            return $this->buildFailedProductUpdateResults($batch, $exception->getMessage());
        }

        return array_merge($failed, $this->executeProductUpdateBatch($retryBatch));
    }

    private function buildFailedProductUpdateResults(array $batch, string $error): array {
        $results = [];

        foreach ($batch as $update) {
            $results[] = [
                'success' => false,
                'product_id' => $update['product_id'] ?? $update['id'] ?? null,
                'error' => $error
            ];
        }

        return $results;
    }
    
    /**
     * 🚀 NOVO: Batch update variants using BigCommerce native batch endpoint
     */
    public function batchUpdateVariants($updates) {
        $results = [];
        foreach (array_chunk($updates, 50) as $batch) {
            while ($batch) {
                $payload = [];
                foreach ($batch as $update) {
                    $item = [
                        'id' => (int)$update['id'],
                        'sale_price' => $this->normalizeSalePriceForApi($update['sale_price'] ?? null),
                    ];
                    if (!empty($update['product_id'])) {
                        $item['product_id'] = (int)$update['product_id'];
                    }
                    $payload[] = $item;
                }

                try {
                    $response = $this->request('PUT', 'catalog/variants', $payload);
                } catch (\Exception $exception) {
                    $missing = $this->missingVariantErrors($exception->getMessage(), $batch);
                    $remaining = [];
                    foreach ($batch as $index => $update) {
                        if ($missing && !isset($missing[$index])) {
                            $remaining[] = $update;
                            continue;
                        }
                        $failure = [
                            'success' => false,
                            'product_id' => $update['product_id'] ?? null,
                            'variant_id' => $update['id'],
                            'error' => $missing[$index] ?? $exception->getMessage(),
                        ];
                        if (isset($missing[$index])) {
                            $failure['reason'] = 'variant_not_found';
                        }
                        $results[] = $failure;
                    }
                    $batch = $remaining;
                    continue;
                }

                $returned = array_column($response['body']['data'] ?? [], null, 'id');
                $errors = [];
                foreach (($response['body']['errors'] ?? []) as $error) {
                    if (isset($error['id'])) {
                        $errors[$error['id']] = $this->stringifyBatchError($error);
                    }
                }
                foreach ($batch as $update) {
                    $id = (int)$update['id'];
                    $success = isset($returned[$id]) && !isset($errors[$id]);
                    $result = [
                        'success' => $success,
                        'product_id' => $update['product_id'] ?? $returned[$id]['product_id'] ?? null,
                        'variant_id' => $id,
                    ];
                    if (!$success) {
                        $result['error'] = $errors[$id] ?? 'Variant was not returned in BigCommerce batch response';
                    }
                    $results[] = $result;
                }
                break;
            }
        }
        return $results;
    }

    private function missingVariantErrors(string $message, array $batch): array {
        if (!preg_match('/^BigCommerce API Error \(Status: 422, Endpoint: catalog\/variants\):.*?\bBody:\s*(\{.*\})\s*$/s', $message, $match)) {
            return [];
        }
        $body = json_decode($match[1], true);
        if (!is_array($body['errors'] ?? null) || (int)($body['status'] ?? 422) !== 422) {
            return [];
        }
        $missing = [];
        foreach ($body['errors'] as $field => $error) {
            if (!preg_match('/^(0|[1-9]\d*)(?:\.id)?$/D', (string)$field, $position)
                || !is_string($error) || !preg_match('/^No variants with id ([1-9]\d*)$/D', $error, $id)) {
                return [];
            }
            $index = (int)$position[1];
            if ((string)($batch[$index]['id'] ?? '') !== $id[1]) {
                return [];
            }
            $missing[$index] = $error;
        }
        return $missing;
    }

    public function getCompleteVariantIds(int $productId): array {
        $ids = [];
        $expectedTotal = null;
        $expectedPages = null;
        for ($page = 1; ; $page++) {
            $response = $this->request('GET', "catalog/products/{$productId}/variants?limit=250&page={$page}");
            $rows = $response['body']['data'] ?? null;
            $pagination = $response['body']['meta']['pagination'] ?? [];
            $total = filter_var($pagination['total'] ?? null, FILTER_VALIDATE_INT);
            $pages = filter_var($pagination['total_pages'] ?? null, FILTER_VALIDATE_INT);
            $current = filter_var($pagination['current_page'] ?? null, FILTER_VALIDATE_INT);
            $validPage = $current === $page || ($page === 1 && $current === 0 && $total === 0 && $pages === 0);
            if (!is_array($rows) || array_values($rows) !== $rows || !$validPage
                || $total === false || $total < 0 || $pages === false || $pages < 0
                || ($expectedTotal !== null && ($total !== $expectedTotal || $pages !== $expectedPages))) {
                throw new \RuntimeException("Incomplete variant list for product {$productId} on page {$page}");
            }
            if (($total === 0 && ($pages > 1 || $rows)) || ($total > 0 && (!$rows || $pages < $page || $pages > $total))) {
                throw new \RuntimeException("Invalid variant pagination for product {$productId}");
            }
            $expectedTotal = $total;
            $expectedPages = $pages;
            foreach ($rows as $row) {
                $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
                $parent = filter_var($row['product_id'] ?? null, FILTER_VALIDATE_INT);
                if ($id === false || $id <= 0 || $parent !== $productId || isset($ids[$id])) {
                    throw new \RuntimeException("Inconsistent variant list for product {$productId}");
                }
                $ids[$id] = true;
            }
            if ($page >= $pages) {
                break;
            }
        }
        if (count($ids) !== $expectedTotal) {
            throw new \RuntimeException("Incomplete variant count for product {$productId}");
        }
        return array_keys($ids);
    }

    private function extractBulkOperationIndexedErrors(string $message): array {
        $body = null;
        if (preg_match('/\bBody:\s*(\{.*\})\s*$/s', $message, $matches)) {
            $body = json_decode($matches[1], true);
        }

        if (!is_array($body) || empty($body['errors']) || !is_array($body['errors'])) {
            return [];
        }

        $errors = [];
        foreach ($body['errors'] as $index => $error) {
            if (!is_numeric($index)) {
                continue;
            }

            $errors[(int)$index] = $this->stringifyBatchError($error);
        }

        return $errors;
    }

    private function stringifyBatchError($error): string {
        if (is_string($error)) {
            return $error;
        }

        if (is_array($error)) {
            if (isset($error['message'])) {
                return (string)$error['message'];
            }

            $encoded = json_encode($error);
            return $encoded !== false ? $encoded : 'Unknown batch error';
        }

        return 'Unknown batch error';
    }
    
    /**
     * Single product sale price update (fallback or for special cases).
     */
    public function updateProductSalePrice($productId, $salePrice = null) {
        $data = [
            'sale_price' => $this->normalizeSalePriceForApi($salePrice)
        ];

        return $this->request('PUT', "catalog/products/{$productId}", $data);
    }

    /**
     * @deprecated Use updateProductSalePrice(). Kept for compatibility; ignores catalog price.
     */
    public function updateProductPrice($productId, $price, $salePrice = null) {
        return $this->updateProductSalePrice($productId, $salePrice);
    }

    private function normalizeSalePriceForApi($salePrice): float {
        if ($salePrice !== null && $salePrice !== '') {
            return (float)$salePrice;
        }

        return 0.0;
    }
    
    // Custom Fields Management
    public function getCustomFields($productId) {
        try {
            $response = $this->request('GET', "catalog/products/{$productId}/custom-fields");
            return $response['body']['data'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }
    
    public function createCustomField($productId, $data) {
        return $this->request('POST', "catalog/products/{$productId}/custom-fields", $data);
    }
    
    public function updateCustomField($productId, $fieldId, $data) {
        return $this->request('PUT', "catalog/products/{$productId}/custom-fields/{$fieldId}", $data);
    }
    
    public function deleteCustomField($productId, $fieldId) {
        return $this->request('DELETE', "catalog/products/{$productId}/custom-fields/{$fieldId}");
    }

    public function getProductMetafields($productId, ?string $namespace = null, ?string $key = null): array {
        try {
            $filters = [];
            if ($namespace !== null && $namespace !== '') {
                $filters['namespace'] = $namespace;
            }
            if ($key !== null && $key !== '') {
                $filters['key'] = $key;
            }

            $endpoint = "catalog/products/{$productId}/metafields";
            if (!empty($filters)) {
                $endpoint .= '?' . http_build_query($filters);
            }

            $response = $this->request('GET', $endpoint);
            return $response['body']['data'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function createProductMetafield($productId, array $data) {
        return $this->request('POST', "catalog/products/{$productId}/metafields", $data);
    }

    public function updateProductMetafield($productId, $metafieldId, array $data) {
        return $this->request('PUT', "catalog/products/{$productId}/metafields/{$metafieldId}", $data);
    }

    public function deleteProductMetafield($productId, $metafieldId) {
        return $this->request('DELETE', "catalog/products/{$productId}/metafields/{$metafieldId}");
    }

    public function getVariantMetafields($productId, $variantId, ?string $namespace = null, ?string $key = null): array {
        try {
            $filters = [];
            if ($namespace !== null && $namespace !== '') {
                $filters['namespace'] = $namespace;
            }
            if ($key !== null && $key !== '') {
                $filters['key'] = $key;
            }

            $endpoint = "catalog/products/{$productId}/variants/{$variantId}/metafields";
            if (!empty($filters)) {
                $endpoint .= '?' . http_build_query($filters);
            }

            $response = $this->request('GET', $endpoint);
            return $response['body']['data'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function createVariantMetafield($productId, $variantId, array $data) {
        return $this->request('POST', "catalog/products/{$productId}/variants/{$variantId}/metafields", $data);
    }

    public function updateVariantMetafield($productId, $variantId, $metafieldId, array $data) {
        return $this->request('PUT', "catalog/products/{$productId}/variants/{$variantId}/metafields/{$metafieldId}", $data);
    }

    public function deleteVariantMetafield($productId, $variantId, $metafieldId) {
        return $this->request('DELETE', "catalog/products/{$productId}/variants/{$variantId}/metafields/{$metafieldId}");
    }
    
    public function getCategories() {
        $response = $this->request('GET', 'catalog/categories?limit=250');
        return $response['body']['data'] ?? [];
    }
    
    public function getBrands() {
        $response = $this->request('GET', 'catalog/brands?limit=250');
        return $response['body']['data'] ?? [];
    }
    
    private function validateFilters($filters) {
        $supportedFilters = [
            'categories:in', 'brand_id', 'brand_id:in', 'price:min', 'price:max', 'date_modified:min',
            'is_visible', 'is_featured', 'inventory_level:min',
            'include', // 🚀 FIX: Omogućava prosleđivanje 'include' parametra (npr. 'variants,images')
            'inventory_level:max', 'sku:in', 'name:like'
        ];
        
        $validFilters = [];
        foreach ($filters as $key => $value) {
            if (in_array($key, $supportedFilters)) {
                if (is_bool($value)) {
                    $validFilters[$key] = $value ? 'true' : 'false';
                } else if (is_array($value)) {
                    $normalizedValues = array_values(array_filter(array_map(function($item) {
                        return trim((string)$item);
                    }, $value), function($item) {
                        return $item !== '';
                    }));

                    if (empty($normalizedValues)) {
                        continue;
                    }

                    if ($key === 'brand_id') {
                        $validFilters['brand_id:in'] = implode(',', $normalizedValues);
                    } else {
                        $validFilters[$key] = implode(',', $normalizedValues);
                    }
                } else {
                    $validFilters[$key] = $value;
                }
            }
        }
        
        return $validFilters;
    }

    /**
     * Registruje novi BigCommerce Webhook.
     */
    public function createWebhook(array $data) {
        $response = $this->request('POST', 'hooks', $data);
        // LOGIČKA ISPRAVKA: Usklađivanje sa strukturom povratne vrednosti iz request()
        return $response['body']['data'] ?? $response; 
    }

    /**
     * Dohvata listu registrovanih BigCommerce Webhookova.
     */
    public function getWebhooks(array $filters = []) {
        $endpoint = 'hooks';
        if (!empty($filters)) {
            $endpoint .= '?' . http_build_query($filters);
        }
        $response = $this->request('GET', $endpoint);
        return $response['body']['data'] ?? [];
    }

    /**
     * Briše BigCommerce Webhook.
     */
    public function updateWebhook(int $webhookId, array $data) {
        $response = $this->request('PUT', "hooks/{$webhookId}", $data);
        return $response['body']['data'] ?? $response;
    }

    /**
     * Update BigCommerce Webhook.
     */
    public function deleteWebhook(int $webhookId) {
        $response = $this->request('DELETE', "hooks/{$webhookId}");
        $status = (int)($response['status'] ?? 0);

        return $status >= 200 && $status < 300;
    }

    private function suppressWebhookForWrite($method, $endpoint, $data = null): void {
        $this->suppressWebhooksForWriteBatch([[
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data,
        ]]);
    }

    private function suppressWebhooksForWriteBatch(array $requests): void {
        $productIds = [];
        $reason = 'app_product_update';

        foreach ($requests as $request) {
            $method = strtoupper((string)($request['method'] ?? ''));
            if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
                continue;
            }

            $endpoint = (string)($request['endpoint'] ?? '');
            $ids = $this->extractProductIdsForWrite($endpoint, $request['data'] ?? null);
            if (empty($ids)) {
                continue;
            }

            $productIds = array_merge($productIds, $ids);
            $reason = $this->getWebhookSuppressionReason($endpoint);
        }

        if (empty($productIds) || empty($this->storeHash)) {
            return;
        }

        try {
            $this->getWebhookSuppressionService()->suppressProductUpdates(
                $this->storeHash,
                $productIds,
                $reason
            );
        } catch (\Throwable $e) {
            error_log("Webhook suppression marker failed: " . $e->getMessage());
        }
    }

    private function extractProductIdsForWrite(string $endpoint, $data = null): array {
        $path = trim(explode('?', $endpoint, 2)[0], '/');
        $productIds = [];

        if ($path === 'catalog/products' && is_array($data)) {
            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $productId = $item['id'] ?? $item['product_id'] ?? null;
                if ($productId) {
                    $productIds[] = (int)$productId;
                }
            }

            return $productIds;
        }

        if (in_array($path, ['catalog/products/variants', 'catalog/variants'], true) && is_array($data)) {
            foreach ($data as $item) {
                if (is_array($item) && !empty($item['product_id'])) {
                    $productIds[] = (int)$item['product_id'];
                }
            }

            return $productIds;
        }

        if (preg_match('#^catalog/products/(\d+)(?:/|$)#', $path, $matches)) {
            return [(int)$matches[1]];
        }

        return [];
    }

    private function getWebhookSuppressionReason(string $endpoint): string {
        if (strpos($endpoint, '/custom-fields') !== false || strpos($endpoint, '/metafields') !== false) {
            return 'app_custom_field_update';
        }

        if (strpos($endpoint, '/variants') !== false || $endpoint === 'catalog/products') {
            return 'app_price_update';
        }

        return 'app_product_update';
    }

    private function getWebhookSuppressionService(): WebhookSuppressionService {
        if (!$this->webhookSuppressionService) {
            $this->webhookSuppressionService = new WebhookSuppressionService($this->db);
        }

        return $this->webhookSuppressionService;
    }
    
    /**
     * Get API request statistics
     */
    public function getRequestCount() {
        return $this->requestCount;
    }

    private function assertReadOnlyAllowsMethod(string $method, string $endpoint): void {
        $method = strtoupper($method);
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $readOnly = strtolower((string)($_ENV['BIGCOMMERCE_READ_ONLY'] ?? getenv('BIGCOMMERCE_READ_ONLY') ?: ''));
        if (in_array($readOnly, ['1', 'true', 'yes', 'on'], true)) {
            throw new \RuntimeException("BIGCOMMERCE_READ_ONLY blocked {$method} {$endpoint}");
        }
    }

    /**
     * Loguje kompletan odgovor sa BC API poziva.
     */
    private function logResponse($method, $endpoint, $status, int $responseBytes, $error, float $durationMs = 0.0, int $retry = 0, array $headers = []) {
        $logFile = __DIR__ . '/../logs/bc_api_requests.log'; 
        $this->rotateLogFile($logFile);

        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => $method,
            'endpoint' => $this->sanitizeEndpoint($endpoint),
            'status' => $status,
            'duration_ms' => $durationMs,
            'response_bytes' => $responseBytes,
            'retry' => $retry,
            'rate_limit_left' => $headers['X-Rate-Limit-Requests-Left'] ?? null,
            'rate_limit_reset_ms' => $headers['X-Rate-Limit-Time-Reset-Ms'] ?? null,
            'error_msg' => $error,
        ];

        $logString = "Request: {$method} {$endpoint} | Status: {$status}\n";
        $logString .= print_r($logEntry, true) . "\n----------------------------------------\n";
        file_put_contents($logFile, $logString, FILE_APPEND);
        return;

        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method'    => $method,
            'endpoint'  => $endpoint,
            'data'      => $data,
            'status'    => $status,
            'error_msg' => $error,
            // Pokušaj dekodiranja tela odgovora ako je JSON, inače sačuvaj sirovi tekst
            'response'  => json_decode($responseBody, true) ?? $responseBody 
        ];
        
        $logString = "Request: {$method} {$endpoint} | Status: {$status}\n";
        $logString .= print_r($logEntry, true) . "\n----------------------------------------\n";
        
        file_put_contents($logFile, $logString, FILE_APPEND);
    }

    private function sanitizeEndpoint(string $endpoint): string {
        $parts = parse_url($endpoint);
        if (empty($parts['query'])) {
            return $endpoint;
        }

        parse_str($parts['query'], $query);
        foreach (['access_token', 'token', 'client_secret'] as $secretKey) {
            if (array_key_exists($secretKey, $query)) {
                $query[$secretKey] = '[redacted]';
            }
        }

        $path = $parts['path'] ?? '';
        $queryString = http_build_query($query);
        return $queryString !== '' ? "{$path}?{$queryString}" : $path;
    }

    private function rotateLogFile(string $logFile): void {
        $maxBytes = 10 * 1024 * 1024;
        $maxFiles = 5;

        if (!is_file($logFile) || filesize($logFile) < $maxBytes) {
            return;
        }

        for ($i = $maxFiles - 1; $i >= 1; $i--) {
            $source = "{$logFile}.{$i}";
            $target = "{$logFile}." . ($i + 1);
            if (is_file($source)) {
                @rename($source, $target);
            }
        }

        @rename($logFile, "{$logFile}.1");
    }
}
