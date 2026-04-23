<?php
namespace App\Services;

use App\Models\Database;

class BigCommerceAPI {
    private $baseUrl;
    private $headers;
    private $requestCount = 0;
    private $storeHash;
    
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
        $retries = 0;

        do {
            // --- NOVO: Provjeri limit PRIJE svakog poziva ---
            $this->checkRateLimit();
            
            $this->requestCount++;

            $ch = curl_init($this->baseUrl . $endpoint);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $this->headers,
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HEADER         => true,
            ]);
            
            if (in_array(strtoupper($method), ['POST', 'PUT'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            } elseif (strtoupper($method) === 'DELETE' && $data !== null) {
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

            $this->logResponse(strtoupper($method), $endpoint, $data, $status, $body, $error);

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

        // OPTIMIZACIJA: Ograničavamo konkurentnost da izbegnemo 429 "Too many simultaneous requests"
        // BigCommerce obično dozvoljava 3-5 paralelnih konekcija.
        $concurrencyLimit = 5; 
        $batches = array_chunk($requests, $concurrencyLimit, true);
        $results = [];

        foreach ($batches as $batch) {
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
        $validFilters = $this->validateFilters($filters);
        $validFilters['limit'] = 250;
        $allProducts = [];
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
                // Uklonjen print_r($products);
                $allProducts = array_merge($allProducts, $products);
                
                $meta = $response['body']['meta'] ?? [];
                $pagination = $meta['pagination'] ?? [];
                $totalPages = $pagination['total_pages'] ?? 1;
                $currentPage = $pagination['current_page'] ?? $page;
                
                $page++;

                if ($currentPage >= $totalPages || $page > $maxPages) {
                    break;
                }
                
            } catch (\Exception $e) {
                error_log("Error fetching products page {$page}: " . $e->getMessage());
                break;
            }
            
        } while (count($products) > 0);
        
        return $allProducts;
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
            try {
                // Prepare batch payload for BigCommerce API
                $payload = array_map(function($update) {
                    $item = [
                        'id' => $update['product_id'],
                        'price' => (float)$update['price']
                    ];
                    
                    if (isset($update['sale_price']) && $update['sale_price'] !== null) {
                        $item['sale_price'] = (float)$update['sale_price'];
                    } else {
                        // To remove sale_price, set it to 0
                        $item['sale_price'] = 0; 
                    }
                    
                    return $item;
                }, $batch);
                
                // Make batch request to BigCommerce
                $response = $this->request('PUT', 'catalog/products', $payload);
                
                // Process response - BigCommerce batch response wraps everything in 'data'
                if (isset($response['body']['data'])) {
                    foreach ($response['body']['data'] as $updatedProduct) {
                        $results[] = [
                            'success' => true,
                            'product_id' => $updatedProduct['id']
                        ];
                    }
                }
                
                // Handle partial errors if present (errors field in the response body)
                if (isset($response['body']['errors'])) {
                     foreach ($response['body']['errors'] as $error) {
                        $results[] = [
                            'success' => false,
                            'product_id' => $error['product_id'] ?? null,
                            'error' => $error['message'] ?? 'Unknown batch error'
                        ];
                    }
                }
                
            } catch (\Exception $e) {
                // If batch fails, mark all products in batch as failed
                foreach ($batch as $update) {
                    $results[] = [
                        'success' => false,
                        'product_id' => $update['product_id'],
                        'error' => $e->getMessage()
                    ];
                }
            }
        }
        
        return $results;
    }
    
    /**
     * 🚀 NOVO: Batch update variants using BigCommerce native batch endpoint
     */
    public function batchUpdateVariants($updates) {
        if (empty($updates)) {
            return [];
        }
        
        // BigCommerce API limit for this endpoint is 50
        $batches = array_chunk($updates, 50);
        $results = [];
        
        foreach ($batches as $batch) {
            try {
                // The payload is an array of variant update objects
                $response = $this->request('PUT', 'catalog/products/variants', $batch);
                
                // Process response
                if (isset($response['body']['data'])) {
                    foreach ($response['body']['data'] as $updatedVariant) {
                        $results[] = [
                            'success' => true,
                            'product_id' => $updatedVariant['product_id'],
                            'variant_id' => $updatedVariant['id']
                        ];
                    }
                }
                
                // Handle partial errors if present
                if (isset($response['body']['errors'])) {
                     foreach ($response['body']['errors'] as $error) {
                        $results[] = [
                            'success' => false,
                            'product_id' => $error['product_id'] ?? null,
                            'variant_id' => $error['id'] ?? null,
                            'error' => $error['message'] ?? 'Unknown batch error'
                        ];
                    }
                }
                
            } catch (\Exception $e) {
                // If the whole batch fails, mark all variants in it as failed
                foreach ($batch as $update) {
                    $results[] = [
                        'success' => false,
                        'product_id' => $update['product_id'],
                        'variant_id' => $update['id'],
                        'error' => $e->getMessage()
                    ];
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Single product update (fallback or for special cases)
     */
    public function updateProductPrice($productId, $price, $salePrice = null) {
        $data = ['price' => (float)$price];
        
        if ($salePrice !== null) {
            $data['sale_price'] = (float)$salePrice;
        } else {
            $data['sale_price'] = 0; // Remove sale price
        }
        
        return $this->request('PUT', "catalog/products/{$productId}", $data);
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
            'categories:in', 'brand_id', 'price:min', 'price:max', 'date_modified:min',
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
                    $validFilters[$key] = implode(',', $value);
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
    public function deleteWebhook(int $webhookId) {
        $response = $this->request('DELETE', "hooks/{$webhookId}");
        $status = (int)($response['status'] ?? 0);

        return $status >= 200 && $status < 300;
    }
    
    /**
     * Get API request statistics
     */
    public function getRequestCount() {
        return $this->requestCount;
    }

    /**
     * Loguje kompletan odgovor sa BC API poziva.
     */
    private function logResponse($method, $endpoint, $data, $status, $responseBody, $error) {
        $logFile = __DIR__ . '/../logs/bc_api_requests.log'; 

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
}
