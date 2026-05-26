<?php
declare(strict_types=1);

namespace App\Services;

class OmnibusMetafieldService {
    private BigCommerceAPI $api;

    private const NAMESPACE = 'promora';
    private const KEY = 'lowest_price_30d';
    private const PERMISSION_SET = 'read_and_sf_access';
    private const DESCRIPTION = 'Omnibus lowest prior price';

    public function __construct(BigCommerceAPI $api) {
        $this->api = $api;
    }

    public function syncLowestPriceMetafields(array $updates): array {
        $aggregates = [];
        $requests = [];
        $requestMeta = [];

        foreach ($updates as $update) {
            $productId = (int)($update['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $aggregates[$productId] = $this->newAggregate($productId);

            if (!empty($update['has_variants'])) {
                $variantIds = $this->normalizeIds($update['variant_ids'] ?? []);
                $variantPrices = $this->normalizeVariantPrices($update['variant_reference_prices'] ?? []);

                foreach ($variantIds as $variantId) {
                    $value = array_key_exists((string)$variantId, $variantPrices)
                        ? $this->formatPriceValue($variantPrices[(string)$variantId])
                        : null;

                    $this->queueVariantSync(
                        $productId,
                        $variantId,
                        $value,
                        $aggregates,
                        $requests,
                        $requestMeta
                    );
                }
                continue;
            }

            $referencePrice = $update['product_reference_price'] ?? $update['omnibus_reference_price'] ?? null;
            $value = $referencePrice !== null ? $this->formatPriceValue($referencePrice) : null;

            $this->queueProductSync(
                $productId,
                $value,
                $aggregates,
                $requests,
                $requestMeta
            );
        }

        if (!empty($requests)) {
            $apiResults = $this->api->multiRequest($requests);
            foreach ($apiResults as $key => $result) {
                $meta = $requestMeta[$key] ?? [];
                $this->recordWriteResult($aggregates, $meta, $result);
            }
        }

        return array_map([$this, 'finalizeAggregate'], array_values($aggregates));
    }

    private function queueProductSync(
        int $productId,
        ?string $value,
        array &$aggregates,
        array &$requests,
        array &$requestMeta
    ): void {
        $existing = $this->findProductMetafield($productId);
        $key = 'product:' . $productId;

        if ($value === null) {
            if ($existing !== null) {
                $requests[$key] = [
                    'method' => 'DELETE',
                    'endpoint' => "catalog/products/{$productId}/metafields/{$existing['id']}",
                ];
                $requestMeta[$key] = $this->newMeta($productId, null, 'delete', $requests[$key]['endpoint'], null);
            } else {
                $this->recordSkippedResult($aggregates, $productId, null, 'No active Omnibus reduction for product');
            }
            return;
        }

        if ($existing !== null && $this->isMetafieldUpToDate($existing, $value)) {
            $this->recordSkippedResult($aggregates, $productId, null, 'Product metafield already up to date');
            return;
        }

        if ($existing !== null) {
            $requests[$key] = [
                'method' => 'PUT',
                'endpoint' => "catalog/products/{$productId}/metafields/{$existing['id']}",
                'data' => $this->buildPayload($value),
            ];
            $requestMeta[$key] = $this->newMeta($productId, null, 'update', $requests[$key]['endpoint'], $value);
            return;
        }

        $requests[$key] = [
            'method' => 'POST',
            'endpoint' => "catalog/products/{$productId}/metafields",
            'data' => $this->buildPayload($value),
        ];
        $requestMeta[$key] = $this->newMeta($productId, null, 'create', $requests[$key]['endpoint'], $value);
    }

    private function queueVariantSync(
        int $productId,
        int $variantId,
        ?string $value,
        array &$aggregates,
        array &$requests,
        array &$requestMeta
    ): void {
        $existing = $this->findVariantMetafield($productId, $variantId);
        $key = 'variant:' . $productId . ':' . $variantId;

        if ($value === null) {
            if ($existing !== null) {
                $requests[$key] = [
                    'method' => 'DELETE',
                    'endpoint' => "catalog/products/{$productId}/variants/{$variantId}/metafields/{$existing['id']}",
                ];
                $requestMeta[$key] = $this->newMeta($productId, $variantId, 'delete', $requests[$key]['endpoint'], null);
            } else {
                $this->recordSkippedResult($aggregates, $productId, $variantId, 'No active Omnibus reduction for variant');
            }
            return;
        }

        if ($existing !== null && $this->isMetafieldUpToDate($existing, $value)) {
            $this->recordSkippedResult($aggregates, $productId, $variantId, 'Variant metafield already up to date');
            return;
        }

        if ($existing !== null) {
            $requests[$key] = [
                'method' => 'PUT',
                'endpoint' => "catalog/products/{$productId}/variants/{$variantId}/metafields/{$existing['id']}",
                'data' => $this->buildPayload($value),
            ];
            $requestMeta[$key] = $this->newMeta($productId, $variantId, 'update', $requests[$key]['endpoint'], $value);
            return;
        }

        $requests[$key] = [
            'method' => 'POST',
            'endpoint' => "catalog/products/{$productId}/variants/{$variantId}/metafields",
            'data' => $this->buildPayload($value),
        ];
        $requestMeta[$key] = $this->newMeta($productId, $variantId, 'create', $requests[$key]['endpoint'], $value);
    }

    private function findProductMetafield(int $productId): ?array {
        return $this->findOmnibusMetafield(
            $this->api->getProductMetafields($productId, self::NAMESPACE, self::KEY)
        );
    }

    private function findVariantMetafield(int $productId, int $variantId): ?array {
        return $this->findOmnibusMetafield(
            $this->api->getVariantMetafields($productId, $variantId, self::NAMESPACE, self::KEY)
        );
    }

    private function findOmnibusMetafield(array $metafields): ?array {
        foreach ($metafields as $metafield) {
            if (($metafield['namespace'] ?? null) !== self::NAMESPACE) {
                continue;
            }
            if (($metafield['key'] ?? null) !== self::KEY) {
                continue;
            }
            if (empty($metafield['id'])) {
                continue;
            }

            return $metafield;
        }

        return null;
    }

    private function buildPayload(string $value): array {
        return [
            'namespace' => self::NAMESPACE,
            'key' => self::KEY,
            'value' => $value,
            'permission_set' => self::PERMISSION_SET,
            'description' => self::DESCRIPTION,
        ];
    }

    private function isMetafieldUpToDate(array $metafield, string $value): bool {
        return (string)($metafield['value'] ?? '') === $value
            && (string)($metafield['permission_set'] ?? '') === self::PERMISSION_SET;
    }

    private function normalizeIds(array $ids): array {
        $normalized = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        return array_values($normalized);
    }

    private function normalizeVariantPrices(array $prices): array {
        $normalized = [];
        foreach ($prices as $variantId => $price) {
            $variantId = (int)$variantId;
            if ($variantId <= 0 || !is_numeric($price)) {
                continue;
            }
            $normalized[(string)$variantId] = (float)$price;
        }

        return $normalized;
    }

    private function formatPriceValue($price): string {
        $normalized = number_format((float)$price, 4, '.', '');
        return rtrim(rtrim($normalized, '0'), '.');
    }

    private function newAggregate(int $productId): array {
        return [
            'product_id' => $productId,
            'status' => 200,
            'target_count' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'messages' => [],
        ];
    }

    private function newMeta(int $productId, ?int $variantId, string $action, string $endpoint, ?string $value): array {
        return [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'action' => $action,
            'endpoint' => $endpoint,
            'value' => $value,
        ];
    }

    private function recordSkippedResult(
        array &$aggregates,
        int $productId,
        ?int $variantId,
        string $message
    ): void {
        if (!isset($aggregates[$productId])) {
            $aggregates[$productId] = $this->newAggregate($productId);
        }

        $aggregates[$productId]['target_count']++;
        $aggregates[$productId]['success_count']++;
        $aggregates[$productId]['messages'][] = [
            'variant_id' => $variantId,
            'message' => $message,
            'skipped' => true,
        ];
    }

    private function recordWriteResult(array &$aggregates, array $meta, array $result): void {
        $productId = (int)($meta['product_id'] ?? 0);
        if ($productId <= 0) {
            return;
        }
        if (!isset($aggregates[$productId])) {
            $aggregates[$productId] = $this->newAggregate($productId);
        }

        $status = (int)($result['status'] ?? 0);
        $success = $status >= 200 && $status < 300 && empty($result['error']);

        $aggregates[$productId]['target_count']++;
        if ($success) {
            $aggregates[$productId]['success_count']++;
        } else {
            $aggregates[$productId]['error_count']++;
            $this->logWriteFailure($meta, $result);
        }

        $aggregates[$productId]['messages'][] = [
            'variant_id' => $meta['variant_id'] ?? null,
            'action' => $meta['action'] ?? null,
            'status' => $status,
            'success' => $success,
        ];
    }

    private function finalizeAggregate(array $aggregate): array {
        if ($aggregate['error_count'] > 0) {
            $aggregate['status'] = 500;
        } elseif ($aggregate['target_count'] === 0) {
            $aggregate['status'] = 204;
        } else {
            $aggregate['status'] = 200;
        }

        return $aggregate;
    }

    protected function logWriteFailure(array $meta, array $result): void {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'product_id' => $meta['product_id'] ?? null,
            'variant_id' => $meta['variant_id'] ?? null,
            'action' => $meta['action'] ?? null,
            'endpoint' => $meta['endpoint'] ?? null,
            'status' => $result['status'] ?? null,
            'error' => $result['error'] ?? null,
            'value' => $meta['value'] ?? null,
            'value_length' => isset($meta['value']) ? strlen((string)$meta['value']) : 0,
            'response' => $result['body'] ?? null,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents(__DIR__ . '/../logs/omnibus_metafields.log', $line, FILE_APPEND);
        error_log('Omnibus metafield write failed: ' . $line);
    }
}
