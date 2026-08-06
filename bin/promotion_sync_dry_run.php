<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use App\Models\Database;
use App\Models\Promotion;
use App\Services\PromotionService;
use App\Services\ProductCacheService;

function dryRunUsage(): void {
    echo "Usage: php bin/promotion_sync_dry_run.php --store-hash=STORE --promotion-id=ID [--limit=N] [--offset=N]\n";
}

function dryRunOption(string $name, array $argv, $default = null) {
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }

    return $default;
}

function dryRunItemKey($productId, $variantId = null): string {
    return $variantId !== null && $variantId !== ''
        ? 'v_' . (int)$variantId
        : 'p_' . (int)$productId;
}

function dryRunDecodeCustomFields($customFields): array {
    if (is_array($customFields)) {
        return $customFields;
    }

    if (!is_string($customFields) || trim($customFields) === '') {
        return [];
    }

    $decoded = json_decode($customFields, true);
    return is_array($decoded) ? $decoded : [];
}

function dryRunCustomFieldValue(array $item, string $name): ?string {
    foreach (dryRunDecodeCustomFields($item['custom_fields'] ?? null) as $field) {
        if (($field['name'] ?? null) === $name) {
            return isset($field['value']) ? (string)$field['value'] : null;
        }
    }

    return null;
}

function dryRunMoney($value): ?float {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }

    return round((float)$value, 2);
}

$storeHash = trim((string)dryRunOption('store-hash', $argv, ''));
$promotionId = (int)dryRunOption('promotion-id', $argv, 0);
$limit = dryRunOption('limit', $argv, null);
$offset = (int)dryRunOption('offset', $argv, 0);

if ($storeHash === '' || $promotionId <= 0) {
    dryRunUsage();
    exit(1);
}

$limit = $limit !== null ? max(1, (int)$limit) : null;
$offset = max(0, $offset);

$db = Database::getInstance();
$db->setStoreContext($storeHash);

$promotionModel = new Promotion();
$promotion = $promotionModel->findById($promotionId);
if (!$promotion) {
    throw new RuntimeException("Promotion {$promotionId} was not found for store {$storeHash}.");
}

$service = new PromotionService($db);
$cacheService = new ProductCacheService($db);
$activePromotions = $promotionModel->findActive();
$filters = json_decode($promotion['filters'] ?? '{}', true) ?: [];
$items = $cacheService->getProductsByFilters($filters, $limit, $offset);

$serviceClass = new ReflectionClass(PromotionService::class);
$calculateBest = $serviceClass->getMethod('calculateBestPromotionCandidate');
$calculateBest->setAccessible(true);
$buildCandidate = $serviceClass->getMethod('buildPromotionCandidate');
$buildCandidate->setAccessible(true);

$rows = [];
$summary = [
    'store_hash' => $storeHash,
    'promotion_id' => $promotionId,
    'promotion_name' => $promotion['name'] ?? null,
    'discount_percent' => (float)($promotion['discount_percent'] ?? 0),
    'status' => $promotion['status'] ?? null,
    'start_date' => $promotion['start_date'] ?? null,
    'end_date' => $promotion['end_date'] ?? null,
    'dry_run_at' => date('Y-m-d H:i:s'),
    'limit' => $limit,
    'offset' => $offset,
    'matched_filter_items' => count($items),
    'would_attempt_price_update' => 0,
    'needs_price_change' => 0,
    'already_at_target_price' => 0,
    'best_other_promotion' => 0,
    'no_applicable_promotion' => 0,
    'invalid_for_target' => 0,
];

foreach ($items as $item) {
    $best = $calculateBest->invoke($service, $item, $activePromotions);
    $candidate = $buildCandidate->invoke($service, $item, $promotion);
    $productId = (int)($item['product_id'] ?? 0);
    $variantId = isset($item['variant_id']) && $item['variant_id'] !== null && $item['variant_id'] !== ''
        ? (int)$item['variant_id']
        : null;
    $currentSalePrice = dryRunMoney($item['sale_price'] ?? null);
    $targetPromoPrice = isset($candidate['promo_price']) ? dryRunMoney($candidate['promo_price']) : null;
    $bestPromotionId = isset($best['promotion_id']) ? (int)$best['promotion_id'] : null;
    $targetWillApply = !empty($candidate['will_apply']);

    $action = 'no_applicable_promotion';
    if ($bestPromotionId === $promotionId) {
        $summary['would_attempt_price_update']++;
        $action = $currentSalePrice !== null && $targetPromoPrice !== null && $currentSalePrice === $targetPromoPrice
            ? 'already_at_target_price'
            : 'update_price';

        if ($action === 'update_price') {
            $summary['needs_price_change']++;
        } else {
            $summary['already_at_target_price']++;
        }
    } elseif ($bestPromotionId !== null) {
        $summary['best_other_promotion']++;
        $action = 'best_other_promotion';
    } else {
        $summary['no_applicable_promotion']++;
    }

    if (!$targetWillApply) {
        $summary['invalid_for_target']++;
    }

    $rows[] = [
        'sku' => $item['sku'] ?? null,
        'product_id' => $productId,
        'variant_id' => $variantId,
        'type' => $item['type'] ?? null,
        'product_name' => $item['name'] ?? null,
        'price' => dryRunMoney($item['price'] ?? null),
        'current_sale_price' => $currentSalePrice,
        'target_promo_price' => $targetPromoPrice,
        'current_promotion_field' => dryRunCustomFieldValue($item, 'Promocija'),
        'target_promotion_field' => $promotion['custom_field_value'] ?? ($promotion['name'] ?? null),
        'current_item_key' => dryRunItemKey($productId, $variantId),
        'best_promotion_id' => $bestPromotionId,
        'best_promotion_name' => $best['promotion_name'] ?? null,
        'target_will_apply' => $targetWillApply,
        'action' => $action,
        'omnibus_status' => $candidate['omnibus_status'] ?? null,
        'omnibus_invalid_reason' => $candidate['omnibus_invalid_reason'] ?? null,
        'omnibus_reference_price' => $candidate['omnibus_reference_price'] ?? null,
        'lowest_price_30d' => $candidate['lowest_price_30d'] ?? null,
        'cost_price' => $candidate['cost_price'] ?? null,
        'cost_price_status' => $candidate['cost_price_status'] ?? null,
        'promotion_invalid_reason' => $candidate['promotion_invalid_reason'] ?? null,
    ];
}

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

$stamp = date('Ymd-His');
$baseName = "promotion_{$promotionId}_dry_run_{$stamp}";
$jsonPath = $logDir . '/' . $baseName . '.json';
$csvPath = $logDir . '/' . $baseName . '.csv';

$payload = [
    'summary' => $summary,
    'rows' => $rows,
];
file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$csv = fopen($csvPath, 'wb');
if ($csv === false) {
    throw new RuntimeException("Unable to write CSV log at {$csvPath}.");
}

if (!empty($rows)) {
    fputcsv($csv, array_keys($rows[0]));
    foreach ($rows as $row) {
        fputcsv($csv, $row);
    }
}
fclose($csv);

echo json_encode([
    'summary' => $summary,
    'json_log' => $jsonPath,
    'csv_log' => $csvPath,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
