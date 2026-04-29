<?php
namespace App\Services;

use App\Models\Database;
use App\Support\StoreSettings;

class CustomFieldService {
    private $api;
    private $fieldName;
    
    public function __construct($api, Database $db = null) {
        $this->api = $api;
        $this->fieldName = \Config::$CUSTOM_FIELD_NAME;

        $db = $db ?? Database::getInstance();
        $storeHash = $db->getStoreContext();

        if ($storeHash) {
            $settings = StoreSettings::load($db, $storeHash);
            $configuredFieldName = trim((string)($settings['promotion_custom_field_name'] ?? ''));
            if ($configuredFieldName !== '') {
                $this->fieldName = $configuredFieldName;
            }
        }
    }
    
    /**
     * Batch upsert custom fields for multiple products for promotion.
     * * @param array $updates Array of arrays, e.g. 
     * [['product_id' => 101, 'promotion_name' => 'Name 1'], ...]
     * @param array $existingFieldsMap (Opciono) Mapa postojećih polja iz keša [product_id => [fields...]]
     * @param array $knownFieldIds (Opciono) Mapa poznatih ID-eva iz baze [product_id => custom_field_id]
     * @return array Results including success/failure status and product_id
     */
    public function upsertCustomFields(array $updates, array $existingFieldsMap = [], array $knownFieldIds = []) { 
        if (empty($updates)) {
            return [];
        }

        $results = [];
        $productIds = array_column($updates, 'product_id');
        $productMap = array_combine($productIds, $updates);
        
        // 1. KORAK: Priprema podataka (iz keša ili API-ja)
        $getRequests = [];
        $productsToFetch = [];

        foreach ($productIds as $productId) {
            $results[$productId] = ['success' => false, 'product_id' => $productId, 'custom_field_id' => null, 'message' => 'Processing...'];
            
            // OPTIMIZACIJA: Ako imamo podatke u kešu, preskačemo GET zahtev!
            if (!isset($existingFieldsMap[$productId])) {
                $productsToFetch[] = $productId;
                $getRequests[] = [
                    'method' => 'GET', 
                    'endpoint' => "catalog/products/{$productId}/custom-fields"
                ];
            }
        }

        // Izvrši API pozive samo za one kojih nema u kešu
        $fetchedData = [];
        if (!empty($getRequests)) {
            $getResponses = $this->api->multiRequest($getRequests);
            foreach ($getResponses as $index => $response) {
                $pid = $productsToFetch[$index];
                if ($response['status'] >= 200 && $response['status'] < 300) {
                    $fetchedData[$pid] = $response['body']['data'] ?? [];
                }
            }
        }

        $postPutRequests = [];

        // 2. KORAK: Analiza i priprema POST/PUT zahteva
        foreach ($productIds as $productId) {
            // Očekuje se 'field_value'
            $promotionName = $productMap[$productId]['field_value'] ?? null;

            // Kombinujemo keširane i dohvaćene podatke
            $customFields = $existingFieldsMap[$productId] ?? $fetchedData[$productId] ?? null;

            if ($customFields === null) {
                 // Ako nismo uspeli da nađemo polja ni u kešu ni preko API-ja (greška u fetch-u)
                 if (in_array($productId, $productsToFetch)) {
                     $results[$productId]['message'] = "Failed to retrieve existing fields.";
                     continue;
                 }
                 $customFields = []; // Ako je keš prazan, pretpostavljamo da nema polja
            }
            
            $fieldId = null;

            foreach ($customFields as $field) {
                // Proveri da li je $field array i ima 'name' pre pristupa
                if (is_array($field) && isset($field['name']) && $field['name'] === $this->fieldName) {
                    $fieldId = $field['id'];
                    $results[$productId]['custom_field_id'] = $fieldId;
                    
                    // OPTIMIZACIJA: Ako je vrednost ista, preskačemo API poziv.
                    // Koristimo (string) i html_entity_decode da izbegnemo nepotrebne update-ove 
                    // zbog razlike u tipovima ili HTML entitetima (npr. "&amp;" vs "&")
                    if ((string)html_entity_decode($field['value']) === (string)$promotionName) {
                        $results[$productId]['success'] = true;
                        $results[$productId]['message'] = 'Field value already correct.';
                        continue 2; // Nastavi na sledeći proizvod
                    }
                    break;
                }
            }

            // NOVO: Ako nismo našli ID u kešu (ili je keš zastareo), proveravamo poznate ID-eve iz baze promotion_products
            if ($fieldId === null && !empty($knownFieldIds[$productId])) {
                $fieldId = $knownFieldIds[$productId];
            }

            if ($fieldId !== null) {
                // UPDATE (PUT)
                $postPutRequests[] = [
                    'method' => 'PUT', 
                    'endpoint' => "catalog/products/{$productId}/custom-fields/{$fieldId}",
                    'data' => [ 'value' => $promotionName ],
                    'product_id' => $productId // Dodato za mapiranje rezultata
                ];
            } else {
                // CREATE (POST)
                $postPutRequests[] = [
                    'method' => 'POST', 
                    'endpoint' => "catalog/products/{$productId}/custom-fields",
                    'data' => [
                        'name' => $this->fieldName,
                        'value' => $promotionName
                    ],
                    'product_id' => $productId
                ];
            }
        }
        
        // 3. KORAK: Batch POST/PUT za sve Custom Fields operacije
        if (!empty($postPutRequests)) {
            $postPutResponses = $this->api->multiRequest($postPutRequests);
            
            foreach ($postPutResponses as $i => $response) {
                $status = $response['status'];
                $success = $status >= 200 && $status < 300;
                $productId = $postPutRequests[$i]['product_id'];
                $data = $response['body']['data'] ?? null;
                
                $results[$productId]['success'] = $success;
                $results[$productId]['message'] = $success ? 'Updated/Created successfully' : 'Update/Create failed';
                
                if ($success && $data && isset($data['id'])) {
                     $results[$productId]['custom_field_id'] = $data['id'];
                } else if (!$success) {
                    // Ignorišemo 422 grešku ako polje već postoji (BigCommerce vraća grešku umesto da ignoriše duplikat)
                    $errorTitle = $response['body']['title'] ?? '';
                    if ($status === 422 && strpos($errorTitle, 'already exists') !== false) {
                        $results[$productId]['success'] = true;
                        $results[$productId]['message'] = 'Field already exists (422 handled as success)';
                    } else {
                        $this->logError("Batch upsert failed for {$productId}", ["response" => $response]);
                    }
                }
            }
        }

        return array_values($results);
    }
    
    /**
     * 🚀 NOVO: Batch uklanjanje Custom Fieldsa.
     * Zamenjuje petlju sa sekvencijalnim pozivima u PromotionService cleanup metodama.
     */
    public function batchRemovePromotionFields(array $productIds, array $existingFieldsMap = []) {
        if (empty($productIds)) {
            return [];
        }
        
        $results = [];
        
        // 1. KORAK: Priprema (Keš vs API)
        $getRequests = [];
        $productsToFetch = [];

        foreach ($productIds as $productId) {
            $results[$productId] = ['success' => false, 'product_id' => $productId];
            
            if (!isset($existingFieldsMap[$productId])) {
                $productsToFetch[] = $productId;
                $getRequests[] = [
                    'method' => 'GET', 
                    'endpoint' => "catalog/products/{$productId}/custom-fields"
                ];
            }
        }

        $fetchedData = [];
        if (!empty($getRequests)) {
            $getResponses = $this->api->multiRequest($getRequests);
            foreach ($getResponses as $index => $response) {
                $pid = $productsToFetch[$index];
                if ($response['status'] >= 200 && $response['status'] < 300) {
                    $fetchedData[$pid] = $response['body']['data'] ?? [];
                }
            }
        }

        $deleteRequests = [];

        // 2. KORAK: Analiza
        foreach ($productIds as $productId) {
            $customFields = $existingFieldsMap[$productId] ?? $fetchedData[$productId] ?? null;

            if ($customFields === null && in_array($productId, $productsToFetch)) {
                 // Nismo uspeli da dohvatimo, pretpostavljamo uspeh (nema šta da se briše) ili grešku
                 $results[$productId]['success'] = true; 
                 continue;
            }
            
            if (!is_array($customFields)) $customFields = [];
            
            $fieldIdToDelete = null;

            foreach ($customFields as $field) {
                // ISPRAVKA: Proveri da li je $field array i ima 'name' pre pristupa
                if (is_array($field) && isset($field['name']) && $field['name'] === $this->fieldName) {
                    $fieldIdToDelete = $field['id'];
                    break;
                }
            }
            
            // ⚠️ POPRAVLJENA LOGIKA (NEDOSTAJALA U PRETHODNOM KODU)
            if ($fieldIdToDelete !== null) {
                // DELETE zahtev
                $deleteRequests[] = [
                    'method' => 'DELETE', 
                    'endpoint' => "catalog/products/{$productId}/custom-fields/{$fieldIdToDelete}",
                    'product_id' => $productId // Dodato za mapiranje rezultata
                ];
            } else {
                 // Nije pronađen Custom Field, smatra se uspehom čišćenja
                 $results[$productId]['success'] = true;
                 $results[$productId]['message'] = "Custom Field not found, cleanup successful.";
            }
            // ----------------------------------------------------
        }
        
        // 3. KORAK: Batch DELETE
        if (!empty($deleteRequests)) {
            $deleteResponses = $this->api->multiRequest($deleteRequests);

            foreach ($deleteResponses as $i => $response) {
                $status = $response['status'];
                // BigCommerce DELETE vraća 204 No Content za uspeh
                $success = $status === 204; 
                $productId = $deleteRequests[$i]['product_id'];

                $results[$productId]['success'] = $success;
                $results[$productId]['message'] = $success ? 'Deleted successfully' : 'Delete failed';
                
                if (!$success) {
                    $this->logError("Batch delete failed for {$productId}", ["response" => $response]);
                }
            }
        }

        return array_values($results);
    }
    
    /**
     * Set or update promotion custom field (ostavljeno za legacy/pojedinačne pozive, ali se više ne koristi u glavnom Sync-u)
     */
    public function setPromotionField($productId, $promotionName) {
        $fieldId = null;

        try {
            // Prvo dohvati postojeća polja (proizvod možda već ima polje)
            $customFields = $this->getProductCustomFields($productId);

            foreach ($customFields as $field) {
                if ($field['name'] === $this->fieldName) {
                    $fieldId = $field['id'];
                    break;
                }
            }

            $payload = [ 'value' => (string)$promotionName ];

            if ($fieldId !== null) {
                // Ažuriranje postojećeg custom fielda
                $response = $this->api->updateCustomField($productId, $fieldId, $payload);
                
                if ($this->isSuccess($response)) {
                    return (int)$fieldId; // Vraća postojeći ID
                }

            } else {
                // Kreiranje novog custom fielda
                $payload['name'] = $this->fieldName;
                $response = $this->api->createCustomField($productId, $payload);
                
                if ($this->isSuccess($response) && isset($response['body']['data']['id'])) {
                    return (int)$response['body']['data']['id']; // Vraća NOVI ID
                }
            }
            
            // Vraća null ako je došlo do greške (nije uspešno ažurirano/kreirano)
            return null;

        } catch (\Exception $e) {
            $this->logError("EXCEPTION in setPromotionField", [
                "product_id" => $productId,
                "error"      => $e->getMessage(),
                "trace"      => $e->getTraceAsString()
            ]);
            return null;
        }
    }
    
    /**
     * Remove the promotion custom field (ostavljeno za legacy/pojedinačne pozive)
     */
    public function removePromotionField($productId) {
        try {
            $customFields = $this->getProductCustomFields($productId);

            $fieldId = null;

            foreach ($customFields as $field) {
                if ($field['name'] === $this->fieldName) {
                    $fieldId = $field['id'];
                    break;
                }
            }

            if ($fieldId !== null) {
                $response = $this->api->deleteCustomField($productId, $fieldId);

                if (!$this->isSuccess($response)) {
                    $this->logError("Failed deleting custom field", [
                        "product_id" => $productId,
                        "field_id"   => $fieldId,
                        "response"   => $response
                    ]);
                }
            }

            return true;

        } catch (\Exception $e) {

            $this->logError("EXCEPTION in removePromotionField", [
                "product_id" => $productId,
                "error"      => $e->getMessage(),
                "trace"      => $e->getTraceAsString()
            ]);

            return false;
        }
    }
    
    /**
     * Get all custom fields for a product (Sekvencijalni poziv - koristi se samo u legacy metodama)
     */
    private function getProductCustomFields($productId) {
        try {
            // Koristimo direktan poziv API-ja i parsiramo body['data']
            $response = $this->api->call('GET', "catalog/products/{$productId}/custom-fields"); 
            return $response['body']['data'] ?? [];
        } catch (\Exception $e) {
            $this->logError("EXCEPTION in getProductCustomFields", [
                "product_id" => $productId,
                "error"      => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Batch delete custom fields for multiple products. (Legacy metoda - zamenjena sa batchRemovePromotionFields)
     */
    public function deleteCustomFields(array $productIds) {
        if (empty($productIds)) {
            return [];
        }

        $results = [];

        foreach ($productIds as $productId) {
            try {
                // Koristi postojeću logiku za uklanjanje custom fielda za pojedinačni proizvod
                $success = $this->removePromotionField($productId);

                $results[] = [
                    'success'    => $success,
                    'product_id' => $productId
                ];
            } catch (\Exception $e) {
                $this->logError("EXCEPTION in deleteCustomFields loop", [
                    "product_id" => $productId,
                    "error"      => $e->getMessage()
                ]);

                $results[] = [
                    'success'    => false,
                    'product_id' => $productId,
                    'error'      => $e->getMessage()
                ];
            }
        }
        return $results;
    }

    /**
     * Helper: Success check based on response
     */
    private function isSuccess($response) {
        return is_array($response)
            && isset($response['status'])
            && $response['status'] >= 200
            && $response['status'] < 300;
    }

    /**
     * Unified logger
     */
    private function logError($message, $data = []) {

        $logFile = __DIR__ . '/../logs/custom_fields.log';

        $entry = date('Y-m-d H:i:s') . " - {$message} - " . json_encode($data) . "\n";

        error_log($entry, 3, $logFile);
    }
}
