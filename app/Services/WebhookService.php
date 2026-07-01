<?php
namespace App\Services;

use App\Models\Database;

class WebhookService {
    private $db;
    private $api;
    private $lastStatusCode = 200;
    private $lastError = null;
    private $suppressionService;
    private $webhookTrackingSchemaEnsured = false;

    private const REQUIRED_SCOPES = [
        'store/product/updated',
        'store/product/created',
        'store/product/deleted',
        'store/product/inventory/updated',
    ];
    private const REACTIVATION_COOLDOWN_SECONDS = 1800;
    private const REACTIVATION_WINDOW_SECONDS = 86400;
    private const MAX_REACTIVATIONS_PER_WINDOW = 3;

    public function __construct($db = null, $api = null, $suppressionService = null) {
        $this->db = $db ?? Database::getInstance();
        $this->api = $api;
        $this->suppressionService = $suppressionService;
    }

    public function acceptWebhook($payloadData = null, $requestHeaders = null): bool {
        $this->resetLastResult();

        $headers = $requestHeaders ?? $_SERVER;
        if (!$this->validateWebhookAuth($headers)) {
            return false;
        }

        $payload = $payloadData ?? json_decode(file_get_contents('php://input'), true);
        $metadata = $this->extractWebhookMetadata($payload);
        if (!$metadata) {
            return false;
        }

        $storeHash = $metadata['store_hash'];
        $store = $this->db->fetchOne(
            "SELECT access_token FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );

        if (!$store) {
            $this->fail(404, "Webhook received for unregistered store: {$storeHash}");
            return false;
        }

        $this->db->setStoreContext($storeHash);
        $eventId = $this->createWebhookEvent($storeHash, $metadata['scope'], $metadata['product_id'], $payload);

        if (!$eventId) {
            $this->fail(500, "Webhook event could not be stored for store: {$storeHash}");
            return false;
        }

        try {
            $queue = $this->createQueueService($storeHash);
            $queue->createWebhookEventJob($eventId);
            $this->lastStatusCode = 202;
            return true;
        } catch (\Throwable $e) {
            $this->fail(500, "Webhook event {$eventId} could not be queued: " . $e->getMessage());
            return false;
        }
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
        $webhookUrl = $this->getWebhookDestination();
        $webhooks = self::REQUIRED_SCOPES;

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

    public function syncWebhookStatusesForStore(string $storeHash, bool $dryRun = false): array {
        $storeHash = trim($storeHash);
        if ($storeHash === '') {
            throw new \InvalidArgumentException('Store hash is required for webhook status sync.');
        }

        $this->ensureWebhookTrackingSchema();

        $store = $this->db->fetchOne(
            "SELECT access_token FROM bigcommerce_stores WHERE store_hash = ? AND is_active = 1",
            [$storeHash]
        );

        if (!$store) {
            throw new \RuntimeException("Active store not found for hash: {$storeHash}");
        }

        $this->db->setStoreContext($storeHash);
        $this->api = $this->createBigCommerceAPI($storeHash, $store['access_token']);

        $destination = $this->getWebhookDestination();
        $remoteHooks = $this->api->getWebhooks();
        $remoteById = [];
        $remoteByScopeDestination = [];

        foreach ($remoteHooks as $hook) {
            if (!isset($hook['id'])) {
                continue;
            }

            $hookId = (int)$hook['id'];
            $scope = (string)($hook['scope'] ?? '');
            $hookDestination = (string)($hook['destination'] ?? '');

            $remoteById[$hookId] = $hook;
            $remoteByScopeDestination[$this->webhookLookupKey($scope, $hookDestination)] = $hook;
        }

        $localRows = $this->db->fetchAll(
            "SELECT *
             FROM webhooks
             WHERE store_hash = ?
             ORDER BY id DESC",
            [$storeHash]
        );
        $localByScope = [];
        foreach ($localRows as $row) {
            $scope = (string)($row['scope'] ?? '');
            if ($scope !== '' && !isset($localByScope[$scope])) {
                $localByScope[$scope] = $row;
            }
        }

        $receiverHealthy = null;
        $result = [
            'store_hash' => $storeHash,
            'checked' => 0,
            'created' => 0,
            'reactivated' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'dry_run' => $dryRun,
            'details' => [],
        ];

        foreach (self::REQUIRED_SCOPES as $scope) {
            $result['checked']++;
            $local = $localByScope[$scope] ?? null;
            $remote = null;

            if ($local && !empty($local['bc_webhook_id'])) {
                $localWebhookId = (int)$local['bc_webhook_id'];
                $remote = $remoteById[$localWebhookId] ?? null;
            }

            if (!$remote) {
                $remote = $remoteByScopeDestination[$this->webhookLookupKey($scope, $destination)] ?? null;
            }

            try {
                if (!$remote) {
                    if ($dryRun) {
                        $result['skipped']++;
                        $result['details'][] = "{$scope}: missing on BigCommerce; dry-run create skipped";
                        continue;
                    }

                    $created = $this->api->createWebhook($this->buildWebhookPayload($scope, $destination, true));
                    $this->upsertLocalWebhookState(
                        $storeHash,
                        $scope,
                        (int)$created['id'],
                        (string)($created['destination'] ?? $destination),
                        !empty($created['is_active']),
                        null,
                        false
                    );
                    $result['created']++;
                    $result['details'][] = "{$scope}: created webhook #" . (int)$created['id'];
                    continue;
                }

                $remoteId = (int)$remote['id'];
                $remoteActive = !empty($remote['is_active']);
                $remoteDestination = (string)($remote['destination'] ?? '');
                $destinationChanged = $remoteDestination !== $destination;

                $this->upsertLocalWebhookState(
                    $storeHash,
                    $scope,
                    $remoteId,
                    $remoteDestination !== '' ? $remoteDestination : $destination,
                    $remoteActive,
                    null,
                    false
                );

                if ($remoteActive && !$destinationChanged) {
                    $result['details'][] = "{$scope}: active";
                    continue;
                }

                $updatePayload = [];
                if ($destinationChanged) {
                    $updatePayload['destination'] = $destination;
                    $updatePayload['headers'] = $this->getWebhookHeaders();
                }

                if (!$remoteActive) {
                    if ($receiverHealthy === null) {
                        $receiverHealthy = $this->isWebhookReceiverHealthy();
                    }

                    if (!$receiverHealthy) {
                        $result['skipped']++;
                        $this->recordWebhookMonitorError($storeHash, $scope, 'Receiver health check failed.');
                        $result['details'][] = "{$scope}: inactive; receiver health check failed";
                        continue;
                    }

                    $skipReason = null;
                    if (!$this->canReactivateWebhook($localByScope[$scope] ?? null, $skipReason)) {
                        $result['skipped']++;
                        $this->recordWebhookMonitorError($storeHash, $scope, $skipReason);
                        $result['details'][] = "{$scope}: inactive; {$skipReason}";
                        continue;
                    }

                    $updatePayload['is_active'] = true;
                }

                if (empty($updatePayload)) {
                    continue;
                }

                if ($dryRun) {
                    $result['skipped']++;
                    $result['details'][] = "{$scope}: update skipped by dry-run";
                    continue;
                }

                $updated = $this->api->updateWebhook($remoteId, $updatePayload);
                $updatedActive = !empty($updated['is_active']);
                $wasReactivated = !$remoteActive && $updatedActive;

                $this->upsertLocalWebhookState(
                    $storeHash,
                    $scope,
                    $remoteId,
                    (string)($updated['destination'] ?? $destination),
                    $updatedActive,
                    null,
                    $wasReactivated
                );

                if ($wasReactivated) {
                    $result['reactivated']++;
                    $result['details'][] = "{$scope}: reactivated webhook #{$remoteId}";
                } else {
                    $result['updated']++;
                    $result['details'][] = "{$scope}: updated webhook #{$remoteId}";
                }
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->recordWebhookMonitorError($storeHash, $scope, $e->getMessage());
                $result['details'][] = "{$scope}: error: " . $e->getMessage();
            }
        }

        return $result;
    }

    public function processWebhook($payloadData = null, $requestHeaders = null) {
        $this->resetLastResult();

        $headers = $requestHeaders ?? $_SERVER;
        if (!$this->validateWebhookAuth($headers)) {
            return false;
        }

        $payload = $payloadData ?? json_decode(file_get_contents('php://input'), true);
        $metadata = $this->extractWebhookMetadata($payload);
        if (!$metadata) {
            return false;
        }

        $storeHash = $metadata['store_hash'];

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

        $eventId = $this->createWebhookEvent($storeHash, $metadata['scope'], $metadata['product_id'], $payload);

        try {
            $this->handleWebhookEvent($eventId, $metadata);
            return true;
        } catch (\Throwable $e) {
            $this->fail(500, "Error processing webhook: " . $e->getMessage());
            $this->markWebhookEventFailed($eventId, $e->getMessage());
            return false;
        }
    }

    public function processQueuedWebhookEvent(int $eventId): array {
        $this->resetLastResult();

        $event = $this->db->fetchOne(
            "SELECT *
             FROM webhook_events
             WHERE id = ?",
            [$eventId]
        );

        if (!$event) {
            throw new \RuntimeException("Webhook event #{$eventId} not found.");
        }

        if (!empty($event['processed'])) {
            return [
                'processed' => 0,
                'skipped' => true,
                'message' => "Webhook event #{$eventId} has already been processed.",
            ];
        }

        $payload = json_decode((string)$event['payload'], true);
        $metadata = $this->extractWebhookMetadata($payload);

        if (!$metadata) {
            throw new \RuntimeException($this->lastError ?? "Webhook event #{$eventId} has invalid payload.");
        }

        if ((string)$event['store_hash'] !== $metadata['store_hash']) {
            throw new \RuntimeException("Webhook event #{$eventId} store hash does not match payload.");
        }

        $store = $this->db->fetchOne(
            "SELECT access_token FROM bigcommerce_stores WHERE store_hash = ?",
            [$metadata['store_hash']]
        );

        if (!$store) {
            throw new \RuntimeException("Webhook event #{$eventId} references an unregistered store.");
        }

        $this->db->setStoreContext($metadata['store_hash']);
        $this->api = $this->createBigCommerceAPI($metadata['store_hash'], $store['access_token']);

        try {
            $this->handleWebhookEvent($eventId, $metadata);
        } catch (\Throwable $e) {
            $this->fail(500, "Error processing queued webhook event #{$eventId}: " . $e->getMessage());
            $this->markWebhookEventFailed($eventId, $e->getMessage());
            throw $e;
        }

        return [
            'processed' => 1,
            'scope' => $metadata['scope'],
            'product_id' => $metadata['product_id'],
        ];
    }

    public function getLastStatusCode(): int {
        return $this->lastStatusCode;
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    protected function updateProductCache(
        $productId,
        bool $reEvaluatePromotions = true,
        bool $reEvaluateOnCatalogFilterChange = false
    ) {
        $previousFingerprint = $reEvaluateOnCatalogFilterChange
            ? $this->getCachedCatalogFilterFingerprint((int)$productId)
            : null;

        $response = $this->api->call('GET', "catalog/products/{$productId}?include=variants,images,custom_fields");
        $product = $response['body']['data'] ?? null;

        if (!$product) {
            throw new \RuntimeException("Product {$productId} could not be fetched from BigCommerce.");
        }

        if (!$reEvaluatePromotions && $reEvaluateOnCatalogFilterChange && $previousFingerprint !== null) {
            $currentFingerprint = $this->buildCatalogFilterFingerprint($product);
            $reEvaluatePromotions = $currentFingerprint !== $previousFingerprint;
        }

        $cacheService = $this->createProductCacheService();
        $cacheService->batchCacheProducts([$product]);

        if ($reEvaluatePromotions) {
            $this->reEvaluatePromotionsForProduct($productId);
        }
    }

    private function getCachedCatalogFilterFingerprint(int $productId): ?string {
        $storeHash = $this->db->getStoreContext();
        if (!$storeHash) {
            return null;
        }

        $row = $this->db->fetchOne(
            "SELECT sku, brand_id, categories, is_visible, is_featured, availability, `condition`
             FROM products_cache
             WHERE product_id = ? AND variant_id IS NULL AND store_hash = ?
             LIMIT 1",
            [$productId, $storeHash]
        );

        return $row ? $this->buildCatalogFilterFingerprint($row) : null;
    }

    private function buildCatalogFilterFingerprint(array $product): string {
        $categories = $product['categories'] ?? [];
        if (is_string($categories)) {
            $decoded = json_decode($categories, true);
            $categories = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($categories)) {
            $categories = [];
        }

        $categories = array_values(array_unique(array_map('intval', $categories)));
        sort($categories, SORT_NUMERIC);

        return json_encode([
            'sku' => (string)($product['sku'] ?? ''),
            'brand_id' => isset($product['brand_id']) && $product['brand_id'] !== null
                ? (int)$product['brand_id']
                : null,
            'categories' => $categories,
            'is_visible' => (bool)($product['is_visible'] ?? false),
            'is_featured' => (bool)($product['is_featured'] ?? false),
            'availability' => (string)($product['availability'] ?? ''),
            'condition' => (string)($product['condition'] ?? ''),
        ]);
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

    protected function createQueueService(string $storeHash): QueueService {
        return new QueueService($storeHash);
    }

    protected function isWebhookReceiverHealthy(): bool {
        $healthUrl = $this->getWebhookDestination() . '?health=1';

        if (!function_exists('curl_init')) {
            error_log('Webhook health check skipped: cURL extension is not available.');
            return false;
        }

        $ch = curl_init($healthUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            error_log("Webhook health check failed: {$error}");
            return false;
        }

        return $statusCode >= 200 && $statusCode < 300 && trim((string)$response) !== '';
    }

    protected function isSuppressedProductUpdate(string $storeHash, string $scope, int $productId): bool {
        if ($scope !== 'store/product/updated') {
            return false;
        }

        try {
            return $this->createWebhookSuppressionService()->consumeProductUpdate($storeHash, $productId, $scope);
        } catch (\Throwable $e) {
            error_log("Webhook suppression check failed: " . $e->getMessage());
            return false;
        }
    }

    protected function createWebhookSuppressionService(): WebhookSuppressionService {
        if (!$this->suppressionService) {
            $this->suppressionService = new WebhookSuppressionService($this->db);
        }

        return $this->suppressionService;
    }

    private function resetLastResult(): void {
        $this->lastStatusCode = 200;
        $this->lastError = null;
    }

    private function validateWebhookAuth($headers): bool {
        if (!is_array($headers)) {
            $this->fail(403, "Webhook validation failed: missing request headers.");
            return false;
        }

        $authHeader = $headers['X-Custom-Auth'] ?? $headers['HTTP_X_CUSTOM_AUTH'] ?? '';
        if ($authHeader === '') {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'X-Custom-Auth') === 0 || strcasecmp((string)$name, 'HTTP_X_CUSTOM_AUTH') === 0) {
                    $authHeader = $value;
                    break;
                }
            }
        }

        if ($authHeader !== \Config::$SECRET_CRON_KEY) {
            $this->fail(403, "Webhook validation failed: invalid X-Custom-Auth header.");
            return false;
        }

        return true;
    }

    private function extractWebhookMetadata($payload): ?array {
        if (!is_array($payload)) {
            $this->fail(400, "Webhook payload is invalid JSON.");
            return null;
        }

        $scope = $payload['scope'] ?? null;
        $storeHash = $this->extractStoreHash($payload);
        $resource = $payload['data'] ?? [];
        $productId = isset($resource['id']) ? (int)$resource['id'] : null;
        $variantId = isset($resource['variant_id']) ? (int)$resource['variant_id'] : null;
        $inventoryValue = $resource['inventory']['value'] ?? ($resource['inventory_level'] ?? null);

        if (!$scope || !$storeHash || !$productId) {
            $this->fail(400, "Webhook payload missing required scope, store hash, or product id.");
            return null;
        }

        return [
            'scope' => (string)$scope,
            'store_hash' => (string)$storeHash,
            'product_id' => (int)$productId,
            'variant_id' => $variantId !== null ? (int)$variantId : null,
            'inventory_value' => $inventoryValue,
        ];
    }

    private function handleWebhookEvent(?int $eventId, array $metadata): void {
        $scope = $metadata['scope'];
        $storeHash = $metadata['store_hash'];
        $productId = (int)$metadata['product_id'];
        $variantId = $metadata['variant_id'];
        $inventoryValue = $metadata['inventory_value'];

        if ($this->isSuppressedProductUpdate($storeHash, $scope, $productId)) {
            $this->updateProductCache($productId, false, true);
            $this->markWebhookEventProcessed($eventId);
            $this->lastStatusCode = 202;
            return;
        }

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
                $this->lastStatusCode = 202;
                break;
        }

        $this->markWebhookEventProcessed($eventId);
    }

    private function getWebhookDestination(): string {
        return rtrim(\Config::$APP_URL, '/') . '/webhook/receiver.php';
    }

    private function getWebhookHeaders(): array {
        return [
            'X-Custom-Auth' => \Config::$SECRET_CRON_KEY,
        ];
    }

    private function buildWebhookPayload(string $scope, string $destination, bool $active): array {
        return [
            'scope' => $scope,
            'destination' => $destination,
            'is_active' => $active,
            'headers' => $this->getWebhookHeaders(),
        ];
    }

    private function webhookLookupKey(string $scope, string $destination): string {
        return $scope . '|' . rtrim($destination, '/');
    }

    private function canReactivateWebhook(?array $localWebhook, ?string &$reason): bool {
        $reason = null;

        if (!$localWebhook) {
            return true;
        }

        $lastReactivatedAt = $localWebhook['last_reactivated_at'] ?? null;
        if (!$lastReactivatedAt) {
            return true;
        }

        $lastTimestamp = strtotime((string)$lastReactivatedAt);
        if (!$lastTimestamp) {
            return true;
        }

        $ageSeconds = time() - $lastTimestamp;
        if ($ageSeconds < self::REACTIVATION_COOLDOWN_SECONDS) {
            $reason = 'reactivation cooldown is still active';
            return false;
        }

        $attempts = (int)($localWebhook['reactivation_attempts'] ?? 0);
        if ($ageSeconds < self::REACTIVATION_WINDOW_SECONDS && $attempts >= self::MAX_REACTIVATIONS_PER_WINDOW) {
            $reason = 'reactivation limit reached for the last 24 hours';
            return false;
        }

        return true;
    }

    private function upsertLocalWebhookState(
        string $storeHash,
        string $scope,
        int $bcWebhookId,
        string $destination,
        bool $active,
        ?string $errorMessage = null,
        bool $reactivated = false
    ): void {
        $existing = $this->db->fetchOne(
            "SELECT id, last_reactivated_at, reactivation_attempts
             FROM webhooks
             WHERE store_hash = ? AND scope = ?
             ORDER BY id DESC
             LIMIT 1",
            [$storeHash, $scope]
        );

        $lastReactivatedAt = null;
        $reactivationAttempts = 0;

        if ($existing) {
            $lastReactivatedAt = $existing['last_reactivated_at'] ?? null;
            $reactivationAttempts = (int)($existing['reactivation_attempts'] ?? 0);
        }

        if ($reactivated) {
            $lastTimestamp = $lastReactivatedAt ? strtotime((string)$lastReactivatedAt) : null;
            $reactivationAttempts = ($lastTimestamp && (time() - $lastTimestamp) < self::REACTIVATION_WINDOW_SECONDS)
                ? $reactivationAttempts + 1
                : 1;
        }

        if ($existing) {
            $sql = "UPDATE webhooks
                    SET bc_webhook_id = ?,
                        destination = ?,
                        is_active = ?,
                        last_checked_at = NOW(),
                        last_error = ?,
                        updated_at = NOW()";
            $params = [$bcWebhookId, $destination, $active ? 1 : 0, $errorMessage];

            if ($reactivated) {
                $sql .= ", last_reactivated_at = NOW(), reactivation_attempts = ?";
                $params[] = $reactivationAttempts;
            }

            $sql .= " WHERE id = ?";
            $params[] = (int)$existing['id'];

            $this->db->query($sql, $params);
            return;
        }

        $this->db->query(
            "INSERT INTO webhooks
             (store_hash, bc_webhook_id, scope, destination, is_active, last_checked_at, last_reactivated_at, reactivation_attempts, last_error, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), " . ($reactivated ? 'NOW()' : 'NULL') . ", ?, ?, NOW())",
            [
                $storeHash,
                $bcWebhookId,
                $scope,
                $destination,
                $active ? 1 : 0,
                $reactivated ? 1 : 0,
                $errorMessage,
            ]
        );
    }

    private function recordWebhookMonitorError(string $storeHash, string $scope, ?string $errorMessage): void {
        $this->db->query(
            "UPDATE webhooks
             SET last_checked_at = NOW(), last_error = ?, updated_at = NOW()
             WHERE store_hash = ? AND scope = ?",
            [$errorMessage, $storeHash, $scope]
        );
    }

    private function ensureWebhookTrackingSchema(): void {
        if ($this->webhookTrackingSchemaEnsured) {
            return;
        }

        $columns = [
            'last_checked_at' => "ALTER TABLE webhooks ADD COLUMN last_checked_at DATETIME NULL AFTER created_at",
            'last_reactivated_at' => "ALTER TABLE webhooks ADD COLUMN last_reactivated_at DATETIME NULL AFTER last_checked_at",
            'reactivation_attempts' => "ALTER TABLE webhooks ADD COLUMN reactivation_attempts INT NOT NULL DEFAULT 0 AFTER last_reactivated_at",
            'last_error' => "ALTER TABLE webhooks ADD COLUMN last_error TEXT NULL AFTER reactivation_attempts",
            'updated_at' => "ALTER TABLE webhooks ADD COLUMN updated_at DATETIME NULL AFTER last_error",
        ];

        foreach ($columns as $column => $alterSql) {
            $existingColumn = $this->db->fetchOne("SHOW COLUMNS FROM webhooks LIKE '{$column}'");
            if (!$existingColumn) {
                $this->db->query($alterSql);
            }
        }

        $this->webhookTrackingSchemaEnsured = true;
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
