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

    try {
        logMsg("Processing Job #{$job['id']} (Type: {$job['job_type']}) for Store: {$job['store_hash']}");

        $db->setStoreContext($job['store_hash']);
        $queue->updateJobStatus($job['id'], 'processing');

        $promotionService = new PromotionService();
        $batchSize = 50;

        if ($job['job_type'] === 'cleanup') {
            logMsg("Processing Cleanup Job (Removing all promotions)...");
            $cleanedCount = $promotionService->cleanupAllProductsBatch();
            $processedCount = $job['total_items'];
            $successCount = $cleanedCount;
            logMsg("Cleanup finished. Removed {$cleanedCount} items.");
        } elseif ($job['job_type'] === 'cleanup_single') {
            logMsg("Processing Single Cleanup Job (Removing products for Promotion #{$job['promotion_id']})...");

            while ($processedCount < $job['total_items']) {
                $results = $promotionService->cleanupSinglePromotionBatch($job['promotion_id'], $batchSize);

                $successCount += $results['processed'];
                $errorCount += $results['errors'];

                if ($results['processed'] === 0) {
                    break;
                }

                $processedCount += $results['processed'];
                $queue->updateProgress($job['id'], $processedCount);
            }

            $db->query(
                "UPDATE promotions SET status = 'expired' WHERE id = ? AND status = 'active' AND end_date < NOW()",
                [$job['promotion_id']]
            );
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

                $successCount += $results['processed'];
                $errorCount += $results['errors'];

                $batchProcessed = $results['processed'] + $results['errors'];
                if ($batchProcessed === 0) {
                    logMsg("Warning: Batch returned 0 items. Stopping loop.");
                    break;
                }

                $processedCount += $batchProcessed;
                $queue->updateProgress($job['id'], $processedCount);

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        $queue->updateJobStatus($job['id'], 'completed');

        $duration = microtime(true) - $jobStartTime;
        $promotionService->logSync(
            $job['promotion_id'],
            $successCount,
            $errorCount,
            $duration,
            "Worker Job #{$job['id']} Completed (Type: {$job['job_type']})",
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