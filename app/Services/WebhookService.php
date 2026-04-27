<?php
namespace App\Services;

use App\Models\Database;

class WebhookService {
    private $db;
    private $api;
    private $lastStatusCode = 200;
    private $lastError = null;

    public function __construct($db = null, $api = null) {
        $this->db = $db ?? Database::getInstance();
        $this->api = $api;
    }

    public function registerWebhooks($storeHash) {
        $store = $this->db->fetchOne(
            "SELECT access_token FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );

        if (!$store) {
            throw new \Exception("Cannot register webhooks: Store not found for hash: {$storeHash}");
        }

        $this->api = new BigCommerceAPI($storeHash, $store['access_token']);
        $webhookUrl = \Config::$APP_URL . '/webhook/receiver.php';
        $webhooks = [
            'store/product/updated',
            'store/product/created',
            'store/product/deleted',
            'store/product/inventory/updated'
        ];

        $registered = [];

        foreach ($webhooks as $scope) {
            try {
                $existing = $this->db->fetchOne(
                    "SELECT * FROM webhooks WHERE store_hash = ? AND scope = ?",
                    [$storeHash, $scope]
                );

                if ($existing) {
                    continue;
                }

                $response = $this->api->createWebhook([
                    'scope' => $scope,
                    'destination' => $webhookUrl,
                    'is_active' => true,
                    'headers' => [
                        'X-Custom-Auth' => \Config::$SECRET_CRON_KEY
                    ]
                ]);

                $this->db->query(
                    "INSERT INTO webhooks (store_hash, bc_webhook_id, scope, destination, is_active)
                     VALUES (?, ?, ?, ?, ?)",
                    [$storeHash, $response['id'], $scope, $webhookUrl, true]
                );

                $registered[] = $scope;
            } catch (\Exception $e) {
                error_log("Error registering webhook {$scope}: " . $e->getMessage());
            }
        }

        return $registered;
    }

    public function unregisterWebhooks($storeHash) {
        $deletedCount = 0;
        $store = $this->db->fetchOne(
            "SELECT access_token FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );

        if (!$store) {
            error_log("Cannot unregister webhooks: Store not found for hash: {$storeHash}");
            return 0;
        }

        $this->api = new BigCommerceAPI($storeHash, $store['access_token']);
        $existingWebhooks = $this->db->fetchAll(
            "SELECT bc_webhook_id FROM webhooks WHERE store_hash = ?",
            [$storeHash]
        );

        foreach ($existingWebhooks as $hook) {
            $bcWebhookId = (int)$hook['bc_webhook_id'];
            try {
                if ($this->api->deleteWebhook($bcWebhookId)) {
                    $this->db->query(
                        "DELETE FROM webhooks WHERE bc_webhook_id = ?",
                        [$bcWebhookId]
                    );
                    $deletedCount++;
                }
            } catch (\Exception $e) {
                error_log("Error deleting webhook ID {$bcWebhookId}: " . $e->getMessage());
            }
        }

        return $deletedCount;
    }

    public function unregisterWebhookById(string $storeHash, int $webhookRowId): array {
        $store = $this->db->fetchOne(
            "SELECT access_token FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );

        if (!$store) {
            throw new \Exception("Store not found for hash: {$storeHash}");
        }

        $webhook = $this->db->fetchOne(
            "SELECT id, bc_webhook_id, scope
             FROM webhooks
             WHERE id = ? AND store_hash = ?",
            [$webhookRowId, $storeHash]
        );

        if (!$webhook) {
            return [
                'deleted' => false,
                'reason' => 'not_found',
                'scope' => null,
            ];
        }

        $this->api = new BigCommerceAPI($storeHash, $store['access_token']);
        $bcWebhookId = (int)$webhook['bc_webhook_id'];
        $deletedOnBigCommerce = false;
        $alreadyMissingOnBigCommerce = false;

        try {
            $deletedOnBigCommerce = $this->api->deleteWebhook($bcWebhookId);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Status: 404') !== false) {
                $alreadyMissingOnBigCommerce = true;
            } else {
                throw $e;
            }
        }

        if ($deletedOnBigCommerce || $alreadyMissingOnBigCommerce) {
            $this->db->query(
                "DELETE FROM webhooks WHERE id = ? AND store_hash = ?",
                [$webhookRowId, $storeHash]
            );
        }

        return [
            'deleted' => $deletedOnBigCommerce || $alreadyMissingOnBigCommerce,
            'reason' => $alreadyMissingOnBigCommerce ? 'missing_on_bc' : ($deletedOnBigCommerce ? 'deleted' : 'not_deleted'),
            'scope' => $webhook['scope'] ?? null,
        ];
    }

    public function getBigCommerceWebhooks($storeHash) {
        $store = $this->db->fetchOne(
            "SELECT access_token FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );

        if (!$store) {
            throw new \Exception("Store not found");
        }

        $this->api = new BigCommerceAPI($storeHash, $store['access_token']);
        $response = $this->api->call('GET', 'hooks');

        return $response['body']['data'] ?? [];
    }

    public function processWebhook($payloadData = null, $requestHeaders = null) {
        $this->lastStatusCode = 200;
        $this->lastError = null;

        $headers = $requestHeaders ?? $_SERVER;
        $authHeader = $headers['X-Custom-Auth'] ?? $headers['HTTP_X_CUSTOM_AUTH'] ?? '';

        if ($authHeader !== \Config::$SECRET_CRON_KEY) {
            $this->fail(403, "Webhook validation failed: invalid X-Custom-Auth header.");
            return false;
        }

        $payload = $payloadData ?? json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->fail(400, "Webhook payload is invalid JSON.");
            return false;
        }

        $scope = $payload['scope'] ?? null;
        $storeHash = $this->extractStoreHash($payload);
        $resource = $payload['data'] ?? [];
        $productId = isset($resource['id']) ? (int)$resource['id'] : null;
        $variantId = isset($resource['variant_id']) ? (int)$resource['variant_id'] : null;
        $inventoryValue = $resource['inventory']['value'] ?? ($resource['inventory_level'] ?? null);

        if (!$scope || !$storeHash || !$productId) {
            $this->fail(400, "Webhook payload missing required scope, store hash, or product id.");
            return false;
        }

        $store = $this->db->fetchOne(
            "SELECT access_token FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );

        if (!$store) {
            $this->fail(404, "Webhook received for unregistered store: {$storeHash}");
            return false;
        }

        $this->db->setStoreContext($storeHash);
        $this->api = $this->createBigCommerceAPI($storeHash, $store['access_token']);

        $eventId = $this->createWebhookEvent($storeHash, $scope, $productId, $payload);

        try {
            switch ($scope) {
                case 'store/product/updated':
                case 'store/product/created':
                    $this->updateProductCache($productId);
                    break;

                case 'store/product/deleted':
                    $this->deleteProductFromCache($productId);
                    break;

                case 'store/product/inventory/updated':
                    $this->updateProductInventory($productId, $inventoryValue, $variantId);
                    break;

                default:
                    $this->markWebhookEventProcessed($eventId);
                    $this->lastStatusCode = 202;
                    return true;
            }

            $this->markWebhookEventProcessed($eventId);
            return true;
        } catch (\Throwable $e) {
            $this->fail(500, "Error processing webhook: " . $e->getMessage());
            $this->markWebhookEventFailed($eventId, $e->getMessage());
            return false;
        }
    }

    public function getLastStatusCode(): int {
        return $this->lastStatusCode;
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    protected function updateProductCache($productId) {
        $response = $this->api->call('GET', "catalog/products/{$productId}?include=variants,images,custom_fields");
        $product = $response['body']['data'] ?? null;

        if (!$product) {
            throw new \RuntimeException("Product {$productId} could not be fetched from BigCommerce.");
        }

        $cacheService = $this->createProductCacheService();
        $cacheService->batchCacheProducts([$product]);
        $this->reEvaluatePromotionsForProduct($productId);
    }

    protected function updateProductInventory($productId, $newInventory = null, $variantId = null) {
        $storeHash = $this->db->getStoreContext();
        if (!$storeHash) {
            throw new \RuntimeException("Store context not set for product inventory update.");
        }

        if ($newInventory === null) {
            $endpoint = $variantId
                ? "catalog/products/{$productId}/variants/{$variantId}"
                : "catalog/products/{$productId}";
            $response = $this->api->call('GET', $endpoint);
            $resource = $response['body']['data'] ?? null;

            if (!$resource) {
                throw new \RuntimeException("Could not fetch product inventory for ID {$productId}.");
            }

            $newInventory = $resource['inventory_level'] ?? 0;
        }

        if ($variantId !== null) {
            $this->db->query(
                "UPDATE products_cache
                 SET inventory_level = ?, cached_at = NOW()
                 WHERE product_id = ? AND variant_id = ? AND store_hash = ?",
                [(int)$newInventory, $productId, $variantId, $storeHash]
            );
        } else {
            $this->db->query(
                "UPDATE products_cache
                 SET inventory_level = ?, cached_at = NOW()
                 WHERE product_id = ? AND store_hash = ?",
                [(int)$newInventory, $productId, $storeHash]
            );
        }

        $this->reEvaluatePromotionsForProduct($productId);
    }

    protected function deleteProductFromCache($productId) {
        $storeHash = $this->db->getStoreContext();
        if (!$storeHash) {
            throw new \RuntimeException("Store context not set for deleting product cache.");
        }

        $this->db->query(
            "DELETE FROM products_cache WHERE product_id = ? AND store_hash = ?",
            [$productId, $storeHash]
        );
        $this->db->query(
            "DELETE FROM product_custom_field_index WHERE product_id = ? AND store_hash = ?",
            [$productId, $storeHash]
        );
        $this->db->query(
            "DELETE FROM promotion_products WHERE product_id = ? AND store_hash = ?",
            [$productId, $storeHash]
        );
    }

    protected function reEvaluatePromotionsForProduct($productId) {
        $promotionService = $this->createPromotionService();
        $promotionService->syncProduct($productId);
    }

    protected function createBigCommerceAPI($storeHash, $accessToken) {
        return new BigCommerceAPI($storeHash, $accessToken);
    }

    protected function createProductCacheService() {
        return new ProductCacheService($this->db);
    }

    protected function createPromotionService() {
        return new PromotionService($this->db);
    }

    private function extractStoreHash(array $payload): ?string {
        $producer = $payload['producer'] ?? $payload['context'] ?? null;
        if (is_string($producer) && strpos($producer, 'stores/') === 0) {
            $hash = substr($producer, strlen('stores/'));
            if ($hash !== '') {
                return $hash;
            }
        }

        foreach (['store_hash', 'store_id'] as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
            if (!empty($payload['data'][$key]) && is_string($payload['data'][$key])) {
                return $payload['data'][$key];
            }
        }

        return null;
    }

    private function createWebhookEvent(string $storeHash, string $scope, int $productId, array $payload): ?int {
        try {
            $this->db->query(
                "INSERT INTO webhook_events (store_hash, scope, resource_id, resource_type, payload)
                 VALUES (?, ?, ?, 'product', ?)",
                [$storeHash, $scope, $productId, json_encode($payload)]
            );

            return (int)$this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log("Webhook event logging failed: " . $e->getMessage());
            return null;
        }
    }

    private function markWebhookEventProcessed(?int $eventId): void {
        if (!$eventId) {
            return;
        }

        $this->db->query(
            "UPDATE webhook_events SET processed = TRUE, processed_at = NOW() WHERE id = ?",
            [$eventId]
        );
    }

    private function markWebhookEventFailed(?int $eventId, string $errorMessage): void {
        if ($eventId) {
            error_log("Webhook event {$eventId} failed: {$errorMessage}");
        }
    }

    private function fail(int $statusCode, string $message): void {
        $this->lastStatusCode = $statusCode;
        $this->lastError = $message;
        error_log($message);
    }
}
