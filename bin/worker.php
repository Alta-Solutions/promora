<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use App\Models\Database;
use App\Services\PromotionService;
use App\Services\QueueService;

$db = Database::getInstance();
$queue = new QueueService();

function logMsg($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

function hasProductsCacheTypeColumn(Database $db): bool {
    static $hasType = null;

    if ($hasType !== null) {
        return $hasType;
    }

    try {
        $column = $db->fetchOne("SHOW COLUMNS FROM products_cache LIKE 'type'");
        $hasType = $column !== false && $column !== null;
    } catch (\Throwable $e) {
        $hasType = false;
    }

    return $hasType;
}

function normalizeWorkerProductIds(array $productIds): array {
    $normalized = [];

    foreach ($productIds as $productId) {
        if (!is_numeric($productId)) {
            continue;
        }

        $productId = (int)$productId;
        if ($productId > 0) {
            $normalized[$productId] = true;
        }
    }

    $productIds = array_keys($normalized);
    sort($productIds, SORT_NUMERIC);
    return $productIds;
}

function mergeWorkerProductIds(array ...$lists): array {
    $merged = [];

    foreach ($lists as $list) {
        foreach (normalizeWorkerProductIds($list) as $productId) {
            $merged[$productId] = true;
        }
    }

    $productIds = array_keys($merged);
    sort($productIds, SORT_NUMERIC);
    return $productIds;
}

function mergeWorkerDiagnostics(array ...$lists): array {
    $merged = [];
    $seen = [];

    foreach ($lists as $list) {
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = implode(':', [
                $item['type'] ?? 'diagnostic',
                $item['promotion_id'] ?? '',
                $item['product_id'] ?? '',
                $item['variant_id'] ?? '',
                $item['error'] ?? '',
            ]);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $item;
        }
    }

    return $merged;
}

function formatWorkerDiagnostics(array $diagnostics, int $limit = 25): string {
    if (empty($diagnostics)) {
        return '';
    }

    $lines = [];
    foreach (array_slice($diagnostics, 0, $limit) as $item) {
        $label = !empty($item['sku'])
            ? (string)$item['sku']
            : 'product_id=' . (string)($item['product_id'] ?? 'n/a');
        $variantId = $item['variant_id'] ?? null;
        if ($variantId !== null && $variantId !== '') {
            $label .= ' variant_id=' . (string)$variantId;
        }

        $promoPrice = array_key_exists('promo_price', $item) && $item['promo_price'] !== null
            ? ' promo_price=' . (string)$item['promo_price']
            : '';
        $error = trim((string)($item['error'] ?? 'Unknown error'));

        $lines[] = "- {$label}{$promoPrice}: {$error}";
    }

    $remaining = count($diagnostics) - count($lines);
    if ($remaining > 0) {
        $lines[] = "- ... {$remaining} more diagnostic item(s)";
    }

    return "Diagnostics:\n" . implode("\n", $lines);
}

function isOmnibusEnabledForStore(Database $db, string $storeHash): bool {
    $storeConfig = $db->fetchOne(
        "SELECT enable_omnibus FROM bigcommerce_stores WHERE store_hash = ?",
        [$storeHash]
    );

    return !empty($storeConfig['enable_omnibus']);
}

function queueTargetedOmnibusSyncIfNeeded(Database $db, array $job, array $productIds): void {
    $productIds = normalizeWorkerProductIds($productIds);
    if (empty($productIds) || empty($job['store_hash']) || !isOmnibusEnabledForStore($db, $job['store_hash'])) {
        return;
    }

    $targetedQueue = new QueueService($job['store_hash']);
    try {
        $result = $targetedQueue->createTargetedOmnibusSyncJob($productIds, [
            'source' => $job['job_type'],
            'promotion_id' => $job['promotion_id'] ?? null,
            'source_job_id' => $job['id'] ?? null,
        ]);

        logMsg(
            "Targeted Omnibus sync {$result['reason']} for " . count($productIds) .
            " product(s). Job ID: " . ($result['job_id'] ?? 'n/a')
        );
    } catch (\Throwable $e) {
        logMsg("Unable to queue targeted Omnibus sync: " . $e->getMessage());
    }
}

logMsg("--- Worker started ---");

$maxExecutionTime = 55;
$scriptStartTime = time();

do {
    if ((time() - $scriptStartTime) >= $maxExecutionTime) {
        logMsg("Time limit reached ({$maxExecutionTime}s). Exiting to release resources.");
        break;
    }

    $job = $queue->getNextPendingJob();

    if (!$job) {
        logMsg("No pending jobs found. Exiting.");
        break;
    }

    $jobStartTime = microtime(true);
    $processedCount = 0;
    $successCount = 0;
    $errorCount = 0;
    $omnibusProductIds = [];
    $diagnostics = [];

    try {
        logMsg("Processing Job #{$job['id']} (Type: {$job['job_type']}) for Store: {$job['store_hash']}");

        $db->setStoreContext($job['store_hash']);
        $queue->updateJobStatus($job['id'], 'processing');

        $promotionService = new PromotionService();
        $batchSize = 50;

        if ($job['job_type'] === 'webhook_event') {
            $eventId = $queue->extractWebhookEventIdFromPayload($job['payload'] ?? null);
            if (!$eventId) {
                throw new \RuntimeException("Webhook event job #{$job['id']} has no valid webhook_event_id payload.");
            }

            logMsg("Processing Webhook Event #{$eventId}...");
            $webhookService = new \App\Services\WebhookService($db);
            $webhookResult = $webhookService->processQueuedWebhookEvent($eventId);

            $processedCount = 1;
            $successCount = empty($webhookResult['skipped']) ? 1 : 0;
            logMsg("Webhook Event #{$eventId} processed. Scope: " . ($webhookResult['scope'] ?? 'n/a'));
        } elseif ($job['job_type'] === 'cleanup') {
            logMsg("Processing Cleanup Job (Removing all promotions)...");
            $cleanupResult = $promotionService->cleanupAllProductsBatch();
            $cleanedCount = (int)($cleanupResult['processed'] ?? 0);
            $omnibusProductIds = mergeWorkerProductIds(
                $omnibusProductIds,
                $cleanupResult['omnibus_product_ids'] ?? []
            );
            $processedCount = $job['total_items'];
            $successCount = $cleanedCount;
            logMsg("Cleanup finished. Removed {$cleanedCount} items.");
        } elseif ($job['job_type'] === 'cleanup_single') {
            logMsg("Processing Single Cleanup Job (Removing products for Promotion #{$job['promotion_id']})...");

            while ($processedCount < $job['total_items']) {
                $results = $promotionService->cleanupSinglePromotionBatch($job['promotion_id'], $batchSize);

                $successCount += $results['processed'];
                $errorCount += $results['errors'];
                $omnibusProductIds = mergeWorkerProductIds(
                    $omnibusProductIds,
                    $results['omnibus_product_ids'] ?? []
                );
                $diagnostics = mergeWorkerDiagnostics(
                    $diagnostics,
                    $results['diagnostics'] ?? []
                );

                if ($results['processed'] === 0) {
                    break;
                }

                $processedCount += $results['processed'];
                $queue->updateProgress($job['id'], $processedCount);
            }

            $db->query(
                "UPDATE promotions SET status = 'expired' WHERE store_hash = ? AND id = ? AND status = 'active' AND end_date < NOW()",
                [$job['store_hash'], $job['promotion_id']]
            );
            $promotionService->markPromotionArchiveCleanupCompleted((int)$job['promotion_id']);
        } elseif ($job['job_type'] === 'omnibus_sync_products') {
            $productIds = $queue->extractProductIdsFromPayload($job['payload'] ?? null);
            logMsg("Processing Targeted Omnibus Sync Job for store {$job['store_hash']}... Products: " . count($productIds));

            if (empty($productIds)) {
                logMsg("Targeted Omnibus job payload has no valid product IDs.");
            }

            $omnibusService = new \App\Services\OmnibusSyncService($job['store_hash']);

            while ($processedCount < count($productIds)) {
                $batchIds = array_slice($productIds, $processedCount, $batchSize);
                if (empty($batchIds)) {
                    break;
                }

                $results = $omnibusService->processBatch($batchIds);

                $successCount += $results['success'] ?? 0;
                $errorCount += $results['errors'] ?? 0;

                $batchProcessed = $results['processed'] ?? count($batchIds);
                if ($batchProcessed <= 0) {
                    $batchProcessed = count($batchIds);
                }

                $processedCount += $batchProcessed;
                $queue->updateProgress($job['id'], min($processedCount, count($productIds)));

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        } elseif ($job['job_type'] === 'omnibus_sync') {
            logMsg("Processing Omnibus Sync Job for store {$job['store_hash']}... Total items: {$job['total_items']}");
            $omnibusService = new \App\Services\OmnibusSyncService($job['store_hash']);
            $baseProductClause = hasProductsCacheTypeColumn($db) ? " AND type = 'product'" : '';

            while ($processedCount < $job['total_items']) {
                logMsg("Processing Omnibus batch (Offset: {$processedCount})...");

                $parentProducts = $db->fetchAll(
                    "SELECT DISTINCT product_id
                     FROM products_cache
                     WHERE store_hash = ?" . $baseProductClause . "
                     LIMIT ? OFFSET ?",
                    [$job['store_hash'], $batchSize, $processedCount]
                );

                if (empty($parentProducts)) {
                    logMsg("No more parent products found for Omnibus. Stopping loop.");
                    break;
                }

                $results = $omnibusService->processBatch($parentProducts);

                $successCount += $results['success'];
                $errorCount += $results['errors'];

                $batchProcessed = $results['processed'] ?? count($parentProducts);
                if ($batchProcessed === 0) {
                    logMsg("Omnibus batch returned 0 processed items. Stopping loop.");
                    break;
                }

                $processedCount += $batchProcessed;
                $queue->updateProgress($job['id'], $processedCount);

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        } else {
            while ($processedCount < $job['total_items']) {
                logMsg("Processing batch (Offset: {$processedCount})...");

                $results = $promotionService->syncSinglePromotionBatch(
                    $job['promotion_id'],
                    $batchSize,
                    $processedCount
                );

                $cleanedCount = $results['cleaned'] ?? 0;
                $successCount += $results['processed'] + $cleanedCount;
                $errorCount += $results['errors'];
                $omnibusProductIds = mergeWorkerProductIds(
                    $omnibusProductIds,
                    $results['omnibus_product_ids'] ?? []
                );
                $diagnostics = mergeWorkerDiagnostics(
                    $diagnostics,
                    $results['diagnostics'] ?? []
                );

                $batchProcessed = $results['processed'] + $results['errors'];
                if ($batchProcessed === 0) {
                    if ($cleanedCount > 0) {
                        logMsg("Reconciled {$cleanedCount} existing promotion items. No filter batch items returned.");
                        $processedCount = $job['total_items'];
                        $queue->updateProgress($job['id'], $processedCount);
                    } else {
                        logMsg("Warning: Batch returned 0 items. Stopping loop.");
                    }
                    break;
                }

                $processedCount += $batchProcessed;
                $queue->updateProgress($job['id'], $processedCount);

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        $finalProcessedCount = (int)($job['total_items'] ?? 0);
        if ($finalProcessedCount > 0) {
            $queue->updateProgress($job['id'], $finalProcessedCount);
        }

        $queue->updateJobStatus($job['id'], 'completed');

        if (in_array($job['job_type'], ['sync_promotion', 'single_sync', 'cleanup', 'cleanup_single'], true)) {
            queueTargetedOmnibusSyncIfNeeded($db, $job, $omnibusProductIds);
        }

        $duration = microtime(true) - $jobStartTime;
        $message = "Worker Job #{$job['id']} Completed (Type: {$job['job_type']})";
        $diagnosticSummary = formatWorkerDiagnostics($diagnostics);
        if ($diagnosticSummary !== '') {
            $message .= "\n" . $diagnosticSummary;
        }

        $promotionService->logSync(
            $job['promotion_id'],
            $successCount,
            $errorCount,
            $duration,
            $message,
            'worker'
        );

        logMsg("Job #{$job['id']} finished successfully! Processed: {$processedCount}");
    } catch (\Exception $e) {
        logMsg("ERROR in Job #{$job['id']}: " . $e->getMessage());

        if (isset($promotionService)) {
            $duration = microtime(true) - $jobStartTime;
            $promotionService->logSync(
                $job['promotion_id'],
                $successCount,
                $errorCount + 1,
                $duration,
                "Worker Job #{$job['id']} Failed: " . $e->getMessage(),
                'worker_error'
            );
        }

        $queue->handleJobFailure($job['id'], $e->getMessage());
    }

    usleep(500000);
} while (true);

logMsg("--- Worker finished ---");
