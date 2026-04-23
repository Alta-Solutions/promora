<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Database;

class OmnibusFieldService {
    private $api;
    private $db;

    private const FIELD_NAME = 'lowest_price_30d';

    public function __construct(BigCommerceAPI $api, Database $db = null) {
        $this->api = $api;
        $this->db = $db ?? Database::getInstance();
    }

    public function batchUpdateLowestPriceFields(array $updates, array $existingProductsCache): array {
        return $this->batchSyncLowestPriceFields($updates, $existingProductsCache);
    }

    public function batchSyncLowestPriceFields(array $updates, array $existingProductsCache): array {
        if (empty($updates)) {
            return [];
        }

        $requests = [];
        $requestMeta = [];
        $results = [];

        foreach ($updates as $update) {
            $productId = (int)$update['product_id'];
            $referencePrice = $update['omnibus_reference_price'] ?? null;
            $fieldValue = $referencePrice !== null ? $this->formatFieldValue($referencePrice) : null;

            [$existingFieldId, $existingFieldValue] = $this->extractExistingFieldState(
                $existingProductsCache[$productId]['custom_fields'] ?? null
            );

            if ($referencePrice === null) {
                if ($existingFieldId) {
                    $requests[$productId] = [
                        'method' => 'DELETE',
                        'endpoint' => "catalog/products/{$productId}/custom-fields/{$existingFieldId}",
                    ];
                    $requestMeta[$productId] = [
                        'action' => 'delete',
                        'field_id' => $existingFieldId,
                        'field_value' => null,
                    ];
                } else {
                    $results[$productId] = [
                        'status' => 204,
                        'body' => ['message' => 'No active reduction and no omnibus field present'],
                        'skipped' => true,
                    ];
                }
                continue;
            }

            if ($existingFieldValue !== null && $existingFieldValue === $fieldValue) {
                $results[$productId] = [
                    'status' => 200,
                    'body' => ['message' => 'Omnibus field already up to date'],
                    'skipped' => true,
                ];
                continue;
            }

            if ($existingFieldId) {
                $requests[$productId] = [
                    'method' => 'PUT',
                    'endpoint' => "catalog/products/{$productId}/custom-fields/{$existingFieldId}",
                    'data' => ['value' => $fieldValue],
                ];
                $requestMeta[$productId] = [
                    'action' => 'update',
                    'field_id' => $existingFieldId,
                    'field_value' => $fieldValue,
                ];
            } else {
                $requests[$productId] = [
                    'method' => 'POST',
                    'endpoint' => "catalog/products/{$productId}/custom-fields",
                    'data' => ['name' => self::FIELD_NAME, 'value' => $fieldValue],
                ];
                $requestMeta[$productId] = [
                    'action' => 'create',
                    'field_id' => null,
                    'field_value' => $fieldValue,
                ];
            }
        }

        if (empty($requests)) {
            return array_values($results);
        }

        $apiResults = $this->api->multiRequest($requests);
        foreach ($apiResults as $productId => $result) {
            $results[$productId] = $this->resolveBatchResult(
                (int)$productId,
                $result,
                $requestMeta[$productId] ?? []
            );
        }

        return array_values($results);
    }

    private function extractExistingFieldState($rawFields): array {
        $fields = is_string($rawFields) ? json_decode($rawFields, true) : $rawFields;
        if (!is_array($fields)) {
            return [null, null];
        }

        foreach ($fields as $field) {
            if (($field['name'] ?? null) !== self::FIELD_NAME) {
                continue;
            }

            return [
                isset($field['id']) ? (int)$field['id'] : null,
                isset($field['value']) ? (string)$field['value'] : null,
            ];
        }

        return [null, null];
    }

    private function resolveBatchResult(int $productId, array $result, array $meta): array {
        $status = (int)($result['status'] ?? 0);
        $action = $meta['action'] ?? null;

        if ($status >= 200 && $status < 300) {
            $this->syncCacheAfterSuccess($productId, $result, $meta);
            return $result;
        }

        if ($status === 404 && $action === 'delete') {
            return $this->recoverMissingDeleteTarget($productId);
        }

        if ($status === 404 && $action === 'update') {
            return $this->recoverMissingUpdateTarget($productId, (string)($meta['field_value'] ?? ''));
        }

        return $result;
    }

    private function recoverMissingDeleteTarget(int $productId): array {
        try {
            $liveField = $this->getLiveOmnibusField($productId);
            if ($liveField === null) {
                $this->syncCachedFieldState($productId, null);
                return [
                    'status' => 204,
                    'body' => ['message' => 'Omnibus field already absent on BigCommerce'],
                    'skipped' => true,
                ];
            }

            $response = $this->api->deleteCustomField($productId, (int)$liveField['id']);
            $this->syncCachedFieldState($productId, null);
            return $response;
        } catch (\Throwable $e) {
            return [
                'status' => 500,
                'body' => ['message' => $e->getMessage()],
                'error' => $e->getMessage(),
            ];
        }
    }

    private function recoverMissingUpdateTarget(int $productId, string $fieldValue): array {
        try {
            $liveField = $this->getLiveOmnibusField($productId);

            if ($liveField !== null) {
                $liveValue = isset($liveField['value']) ? (string)$liveField['value'] : null;
                if ($liveValue === $fieldValue) {
                    $this->syncCachedFieldState($productId, [
                        'id' => (int)$liveField['id'],
                        'name' => self::FIELD_NAME,
                        'value' => $fieldValue,
                    ]);

                    return [
                        'status' => 200,
                        'body' => ['message' => 'Omnibus field already up to date on BigCommerce'],
                        'skipped' => true,
                    ];
                }

                $response = $this->api->updateCustomField($productId, (int)$liveField['id'], ['value' => $fieldValue]);
                $this->syncCachedFieldState($productId, [
                    'id' => (int)$liveField['id'],
                    'name' => self::FIELD_NAME,
                    'value' => $fieldValue,
                ]);
                return $response;
            }

            $response = $this->api->createCustomField($productId, [
                'name' => self::FIELD_NAME,
                'value' => $fieldValue,
            ]);
            $fieldState = $this->extractFieldStateFromResponse($response, $fieldValue) ?? $this->getLiveOmnibusField($productId);
            $this->syncCachedFieldState($productId, $fieldState);
            return $response;
        } catch (\Throwable $e) {
            return [
                'status' => 500,
                'body' => ['message' => $e->getMessage()],
                'error' => $e->getMessage(),
            ];
        }
    }

    private function syncCacheAfterSuccess(int $productId, array $result, array $meta): void {
        $action = $meta['action'] ?? null;
        if ($action === 'delete') {
            $this->syncCachedFieldState($productId, null);
            return;
        }

        if (!in_array($action, ['create', 'update'], true)) {
            return;
        }

        $fieldState = $this->extractFieldStateFromResponse($result, $meta['field_value'] ?? null);
        if ($fieldState === null && !empty($meta['field_id'])) {
            $fieldState = [
                'id' => (int)$meta['field_id'],
                'name' => self::FIELD_NAME,
                'value' => (string)($meta['field_value'] ?? ''),
            ];
        }

        if ($fieldState === null) {
            $fieldState = $this->getLiveOmnibusField($productId);
        }

        $this->syncCachedFieldState($productId, $fieldState);
    }

    private function extractFieldStateFromResponse(array $result, ?string $fallbackValue): ?array {
        $body = $result['body'] ?? null;
        if (!is_array($body)) {
            return null;
        }

        $field = $body['data'] ?? $body;
        if (!is_array($field) || empty($field['id'])) {
            return null;
        }

        return [
            'id' => (int)$field['id'],
            'name' => self::FIELD_NAME,
            'value' => isset($field['value']) ? (string)$field['value'] : (string)$fallbackValue,
        ];
    }

    private function getLiveOmnibusField(int $productId): ?array {
        $fields = $this->api->getCustomFields($productId);
        foreach ($fields as $field) {
            if (($field['name'] ?? null) !== self::FIELD_NAME) {
                continue;
            }

            return [
                'id' => (int)$field['id'],
                'name' => self::FIELD_NAME,
                'value' => isset($field['value']) ? (string)$field['value'] : null,
            ];
        }

        return null;
    }

    private function syncCachedFieldState(int $productId, ?array $fieldState): void {
        $storeHash = $this->db->getStoreContext();
        if (empty($storeHash)) {
            return;
        }

        $product = $this->db->fetchOne(
            "SELECT custom_fields
             FROM products_cache
             WHERE store_hash = ? AND product_id = ? AND variant_id IS NULL
             LIMIT 1",
            [$storeHash, $productId]
        );

        if (!$product) {
            return;
        }

        $fields = is_string($product['custom_fields'])
            ? json_decode($product['custom_fields'], true)
            : $product['custom_fields'];

        if (!is_array($fields)) {
            $fields = [];
        }

        $nextFields = [];
        foreach ($fields as $field) {
            if (($field['name'] ?? null) === self::FIELD_NAME) {
                continue;
            }
            $nextFields[] = $field;
        }

        if ($fieldState !== null) {
            $nextFields[] = [
                'id' => (int)$fieldState['id'],
                'name' => self::FIELD_NAME,
                'value' => (string)($fieldState['value'] ?? ''),
            ];
        }

        $this->db->query(
            "UPDATE products_cache
             SET custom_fields = ?
             WHERE store_hash = ? AND product_id = ? AND variant_id IS NULL",
            [json_encode($nextFields), $storeHash, $productId]
        );
    }

    private function formatFieldValue($price): string {
        $normalized = number_format((float)$price, 4, '.', '');
        return rtrim(rtrim($normalized, '0'), '.');
    }
}
