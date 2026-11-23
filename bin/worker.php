<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
\App\Support\CronAuth::enforce();

use App\Models\Database;
use App\Services\PromotionService;
use App\Services\QueueService;

$db = Database::getInstance();
$queue = new QueueService();

function logMsg($msg) {
    if (in_array('--background', $_SERVER['argv'] ?? [], true)) {
        return;
    }
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

function decodeWorkerPayload($payload): array {
    if (is_array($payload)) {
        return $payload;
    }

    if (!is_string($payload) || trim($payload) === '') {
        return [];
    }

    $decoded = json_decode($payload, true);
    return is_array($decoded) ? $decoded : [];
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
            if (count($merged) === 25) {
                return $merged;
            }
        }
    }

    return $merged;
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

        if (empty($result['job_id'])) {
            throw new \RuntimeException('Targeted Omnibus scheduling incomplete');
        }
        logMsg(
            "Targeted Omnibus sync {$result['reason']} for " . count($productIds) .
            " product(s). Job ID: " . ($result['job_id'] ?? 'n/a')
        );
    } catch (\Throwable $e) {
        throw $e;
    }
}


$maxExecutionTime = 55;
$webhookEventBatchLimit = max(1, min(100, (int)(getenv('WEBHOOK_EVENT_BATCH_LIMIT') ?: 25)));
$scriptStartTime = time();

do {
    if ((time() - $scriptStartTime) >= $maxExecutionTime) {
        logMsg("Time limit reached ({$maxExecutionTime}s). Exiting to release resources.");
        break;
    }

    $job = $queue->claim();

    if (!$job) {

        break;
    }

    \App\Support\OperationalLog::begin('job-' . $job['id']);
    $promotionService = null;
    $jobStartTime = microtime(true);
    $processedCount = 0;
    $successCount = 0;
    $errorCount = 0;
    $skippedCount = 0;
    $omnibusProductIds = [];
    $jobCompleted = true;
    $diagnostics = [];

    try {
        logMsg("Processing Job #{$job['id']} (Type: {$job['job_type']}) for Store: {$job['store_hash']}");

        $db->setStoreContext($job['store_hash']);
        $state = decodeWorkerPayload($job['checkpoint'] ?? null);
        $priorDuration = (float)($state['duration'] ?? 0);
        $diagnostics = $state['diagnostics'] ?? [];
        $processedCount = (int)($state['processed'] ?? 0);
        $successCount = (int)($state['success'] ?? 0);
        $errorCount = (int)($state['errors'] ?? 0);
        $skippedCount = (int)($state['skipped'] ?? 0);

        \App\Services\BigCommerceAPI::setLeaseHeartbeat(fn() => $queue->renew($job));
        $promotionService = new PromotionService();
        $batchSize = 50;
        $save = function (bool $more, bool $yield = false) use ($queue, $job, &$state, &$jobCompleted,
            &$processedCount, &$successCount, &$errorCount, &$skippedCount, &$omnibusProductIds,
            $db, $scriptStartTime, $maxExecutionTime, &$diagnostics, $priorDuration, $jobStartTime): bool {
            queueTargetedOmnibusSyncIfNeeded($db, $job, $omnibusProductIds);
            $omnibusProductIds = [];
            $defer = $more && ($yield || time() - $scriptStartTime >= $maxExecutionTime);
            $state['duration'] = $priorDuration + microtime(true) - $jobStartTime;
            $state['api'] = \App\Support\OperationalLog::takeSummary($state['api'] ?? []);
            $state['diagnostics'] = array_slice($diagnostics,0,25);
            $state['finished_pages'] = !$more;
            $state['processed'] = $processedCount;
            $state['success'] = $successCount;
            $state['errors'] = $errorCount;
            $state['skipped'] = $skippedCount;
            $queue->checkpoint($job, $state, $defer ? 'pending' : 'processing');
            $jobCompleted = !$defer;
            return !$defer;
        };

        if (!empty($state['finished_pages'])) {
            // The last page was committed before the previous worker exited.
        } elseif ($job['job_type'] === 'resume_correction') {
            $id = (int)(decodeWorkerPayload($job['payload'])['correction_id'] ?? 0);
            (new \App\Services\PromotionApplicationCorrectionService($db,$promotionService))->resume($job['store_hash'],$id);
            $processedCount = $successCount = 1;
        } elseif ($job['job_type'] === 'retry_promotion_product') {
            $ids = $queue->extractProductIdsFromPayload($job['payload'] ?? null);
            $result = count($ids) === 1 ? $promotionService->syncProduct($ids[0]) : [];
            if ($result['errors'] ?? 1) {
                throw new \RuntimeException('Product reconciliation needs another attempt');
            }
            $omnibusProductIds = $result['omnibus_product_ids'] ?? [];
            $successCount = $processedCount = 1;
        } elseif ($job['job_type'] === 'verify_variants') {
            $ids = $queue->extractProductIdsFromPayload($job['payload'] ?? null);
            if (count($ids) !== 1) {
                throw new \RuntimeException('Variant verification requires one product ID');
            }
            $cache = new \App\Services\ProductCacheService($db);
            $removed = $cache->verifyProductVariants($ids[0]);
            $successCount = $processedCount = 1;
            logMsg("Verified product {$ids[0]}; removed {$removed} stale variant(s).");
        } elseif ($job['job_type'] === 'cleanup_promotion_field') {
            $ids = $queue->extractProductIdsFromPayload($job['payload'] ?? null);
            if (count($ids) !== 1) {
                throw new \RuntimeException('Promotion field cleanup requires one product ID');
            }
            $promotionService->withProductLock($ids[0], fn() => $promotionService->removeUnusedPromotionField($ids[0]));
            $successCount = $processedCount = 1;
        } elseif ($job['job_type'] === 'webhook_event') {
            $eventIds = $queue->extractWebhookEventIdsFromPayload($job['payload'] ?? null);
            if (empty($eventIds)) {
                throw new \RuntimeException("Webhook event job #{$job['id']} has no valid webhook event payload.");
            }

            $eventIdsToProcess = array_slice($eventIds, $processedCount, $webhookEventBatchLimit);


            logMsg(
                "Processing Webhook Event batch... " .
                count($eventIdsToProcess) . "/" . count($eventIds) . " event(s)."
            );
            $webhookService = new \App\Services\WebhookService($db);

            foreach ($eventIdsToProcess as $eventId) {

                try {
                    $webhookResult = $webhookService->processQueuedWebhookEvent($eventId, $job['store_hash']);
                } catch (\Throwable $failure) {
                    $processedCount++;
                    $errorCount++;
                    $state['processed'] = $processedCount;
                    $state['success'] = $successCount;
                    $state['errors'] = $errorCount;
                    \App\Support\OperationalLog::write('error', ['operation'=>'process_webhook','event_id'=>$eventId,
                        'store_hash'=>$job['store_hash'],'attempt'=>1+(int)$job['attempts'],'exception'=>get_class($failure)]);
                    $queue->retryWebhookOrMarkTerminal($job, $eventId, $state);
                    if (!$save($processedCount < count($eventIds))) {
                        break;
                    }
                    continue;
                }
                $skippedCount += !empty($webhookResult['skipped']) ? 1 : 0;

                $processedCount++;
                $successCount += empty($webhookResult['skipped']) ? 1 : 0;
                if (!$save($processedCount < count($eventIds))) {
                    break;
                }

            }

            if ($jobCompleted && $processedCount < count($eventIds)) {
                $save(true, true);
            }
        } elseif (in_array($job['job_type'], ['cleanup','cleanup_single'], true)) {
            $afterId = (int)($state['after_id'] ?? 0);
            do {
                $results = $promotionService->cleanupOwnersPage($job['job_type'] === 'cleanup' ? null : (int)$job['promotion_id'], $batchSize, $afterId);

                $successCount += $results['processed'];
                $errorCount += $results['errors'];
                $omnibusProductIds = mergeWorkerProductIds(
                    $omnibusProductIds,
                    $results['omnibus_product_ids'] ?? []
                );
                $queue->scheduleProductRetries($job,$results['diagnostics'] ?? []);
                $diagnostics = mergeWorkerDiagnostics(
                    $diagnostics,
                    $results['diagnostics'] ?? []
                );

                $afterId = $results['next_id'];
                $processedCount += $results['visited'];
                $state['after_id'] = $afterId;
                if (!$save($results['visited'] === $batchSize)) {
                    break;
                }
            } while ($results['visited'] === $batchSize);

            if (!$jobCompleted) {
                continue;
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

                $processedCount += count($batchIds);
                if (!$save($processedCount < count($productIds))) {
                    break;
                }

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        } elseif ($job['job_type'] === 'omnibus_sync') {
            logMsg("Processing Omnibus Sync Job for store {$job['store_hash']}... Total items: {$job['total_items']}");
            $omnibusService = new \App\Services\OmnibusSyncService($job['store_hash']);
            $baseProductClause = hasProductsCacheTypeColumn($db) ? " AND type = 'product'" : '';
            $payload = decodeWorkerPayload($job['payload'] ?? null);
            $lastProductId = max(0, (int)($state['last_product_id'] ?? $payload['last_product_id'] ?? 0));

            while (true) {
                logMsg("Processing Omnibus batch (after product_id: {$lastProductId})...");

                $parentProducts = $db->fetchAll(
                    "SELECT DISTINCT product_id
                     FROM products_cache
                     WHERE store_hash = ?" . $baseProductClause . "
                       AND product_id > ?
                     ORDER BY product_id ASC
                     LIMIT ?",
                    [$job['store_hash'], $lastProductId, $batchSize]
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

                $processedCount += count($parentProducts);
                $lastProductId = (int)end($parentProducts)['product_id'];
                $state['last_product_id'] = $lastProductId;
                if (!$save(count($parentProducts) === $batchSize) || count($parentProducts) < $batchSize) {
                    break;
                }

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        } elseif (in_array($job['job_type'], ['single_sync','sync_promotion'], true)) {
            $cursor = $state['cursor'] ?? null;
            do {
                $results = $promotionService->syncSinglePromotionBatch($job['promotion_id'], $batchSize, 0, $cursor);
                $cursor = $results['next_cursor'];
                $skippedCount += $results['skipped'];
                $cleanedCount = $results['cleaned'] ?? 0;
                $successCount += $results['processed'] + $cleanedCount;
                $errorCount += $results['errors'];
                $omnibusProductIds = mergeWorkerProductIds(
                    $omnibusProductIds,
                    $results['omnibus_product_ids'] ?? []
                );
                $queue->scheduleProductRetries($job,$results['diagnostics'] ?? []);
                $diagnostics = mergeWorkerDiagnostics(
                    $diagnostics,
                    $results['diagnostics'] ?? []
                );

                $processedCount += $results['visited'];
                $state['cursor'] = $cursor;
                if (!$save($results['visited'] === $batchSize)) {
                    break;
                }
            } while ($results['visited'] === $batchSize);
        } else {
            throw new \RuntimeException('Unknown job type');
        }

        if (!$jobCompleted) {
            continue;
        }
        queueTargetedOmnibusSyncIfNeeded($db, $job, $omnibusProductIds);
        $state['processed'] = $processedCount;
        $state['success'] = $successCount;
        $state['errors'] = $errorCount;
        $state['skipped'] = $skippedCount;
        $duration = $priorDuration + microtime(true) - $jobStartTime;
        $message = "Worker job #{$job['id']} ({$job['job_type']}) completed";
        if (in_array($job['job_type'], ['sync_promotion', 'single_sync', 'cleanup', 'cleanup_single'], true)) {
            $message .= "\nPrice updates: {$successCount} successful, {$skippedCount} skipped by rules, {$errorCount} failed.";
        }
        $apiSummary = \App\Support\OperationalLog::takeSummary($state['api'] ?? []);
        if ($apiSummary) {
            $message .= "\nBC API " . json_encode($apiSummary, JSON_UNESCAPED_SLASHES);
        }
        $diagnosticSummary = PromotionService::formatPriceDiagnostics($diagnostics);
        if ($diagnosticSummary !== '') {
            $message .= "\n" . $diagnosticSummary;
        }

        $db->beginTransaction();
        try {
            // Commit the final outcome and its operational summary together.
            unset($state['api'],$state['diagnostics']);
            $queue->checkpoint($job, $state, 'completed', $errorCount ? 'partial' : 'success');
            $promotionService->logSync($job['promotion_id'],$successCount,$errorCount,$duration,$message,'worker');
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        logMsg("Job #{$job['id']} completed. Processed {$processedCount}, errors {$errorCount}.");
    } catch (\Throwable $e) {
        \App\Support\OperationalLog::write('error', ['operation' => 'worker', 'store_hash' => $job['store_hash'], 'exception' => get_class($e)]);
        logMsg("ERROR in Job #{$job['id']}: " . $e->getMessage());

        $queue->failClaim($job, $e->getMessage());
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

    } finally {
        \App\Services\BigCommerceAPI::setLeaseHeartbeat(null);
    }

} while (true);
