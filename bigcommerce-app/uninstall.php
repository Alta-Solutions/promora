<?php
require_once(ROOT_PATH . 'config.php');

/**
 * App Uninstall Endpoint
 * Called when merchant uninstalls the app
 */

// Verify signed payload
$signedPayload = $_GET['signed_payload'] ?? file_get_contents('php://input');

if (empty($signedPayload)) {
    http_response_code(400);
    die('Missing signed payload');
}

// Decode payload
list($encodedData, $encodedSignature) = explode('.', $signedPayload, 2);

$signature = base64_decode($encodedSignature);
$data = json_decode(base64_decode($encodedData), true);

// Verify signature
$expectedSignature = hash_hmac('sha256', $encodedData, Config::$BC_CLIENT_SECRET, true);

if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    die('Invalid signature');
}

// Extract store hash
$storeHash = $data['store_hash'];

// Cleanup: Remove all promotions and custom fields
try {
    $db = \App\Models\Database::getInstance();
    
    // Get store credentials
    $store = $db->fetchOne(
        "SELECT access_token FROM bigcommerce_stores WHERE store_hash = ?",
        [$storeHash]
    );
    
    if ($store) {
        // ISPRAVKA: API mora biti inicijalizovan sa store hash-om i tokenom
        $api = new \App\Services\BigCommerceAPI($storeHash, $store['access_token']);
        $customFieldService = new \App\Services\CustomFieldService($api);
        
        // Get all products in promotions
        $products = $db->fetchAll(
            "SELECT DISTINCT product_id, original_price 
             FROM promotion_products"
        );
        
        // UPOZORENJE: Ova petlja je neefikasna jer pravi pojedinačne API pozive za svaki proizvod.
        // Trebalo bi je refaktorisati da koristi batch metode kao što su `batchUpdateProducts`
        // i `multiRequest` za brisanje custom fields, po uzoru na `PromotionService::cleanupAllProductsBatch`.
        // Takođe, ne čisti Omnibus custom fields.
        // Cleanup products
        foreach ($products as $product) {
            try {
                // Reset price
                $api->updateProductPrice($product['product_id'], $product['original_price'], null);
                
                // Remove custom field
                $customFieldService->removePromotionField($product['product_id']);
            } catch (Exception $e) {
                error_log("Cleanup error: " . $e->getMessage());
            }
        }
        
        // Delete all data for this store
        $db->query("DELETE FROM promotion_products WHERE promotion_id IN 
                    (SELECT id FROM promotions WHERE store_hash = ?)", [$storeHash]);
        $db->query("DELETE FROM products_cache WHERE store_hash = ?", [$storeHash]);
        $db->query("DELETE FROM promotions WHERE store_hash = ?", [$storeHash]);
        $db->query("DELETE FROM product_price_history WHERE store_hash = ?", [$storeHash]); // NOVO
        $db->query("DELETE FROM sync_log WHERE store_hash = ?", [$storeHash]);
        $db->query("DELETE FROM bigcommerce_stores WHERE store_hash = ?", [$storeHash]);
        
        error_log("App uninstalled for store: {$storeHash}");
    }
    
    http_response_code(200);
    echo json_encode(['status' => 'success']);
    
} catch (Exception $e) {
    error_log("Uninstall error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}