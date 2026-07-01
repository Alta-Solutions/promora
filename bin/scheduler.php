<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use App\Services\OmnibusSyncSchedulerService;
use App\Services\QueueService;
use App\Models\Database;

// Helper za logovanje sa vremenom
function logMsg($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

logMsg("--- Scheduler started ---");

$db = Database::getInstance();

try {
    // 1. Zakazivanje Omnibus sync poslova: dnevni full sync ili incremental dirty sync
    logMsg("Scheduling Omnibus sync jobs...");
    $omnibusScheduler = new OmnibusSyncSchedulerService($db);
    $omnibusResults = $omnibusScheduler->scheduleAllStores();

    $omnibusFullJobsCreated = 0;
    $omnibusTargetedJobsCreated = 0;
    foreach ($omnibusResults as $result) {
        $mode = $result['mode'] ?? 'unknown';
        $storeHash = $result['store_hash'] ?? 'unknown';
        $reason = $result['reason'] ?? 'created';
        $jobId = $result['job_id'] ?? 'n/a';

        if (!empty($result['created']) && $mode === 'full') {
            $omnibusFullJobsCreated++;
        } elseif (!empty($result['created']) && $mode === 'incremental') {
            $omnibusTargetedJobsCreated++;
        }

        logMsg("-> Omnibus {$mode} for {$storeHash}: {$reason}; job={$jobId}");
    }
    logMsg("-> Created {$omnibusFullJobsCreated} full and {$omnibusTargetedJobsCreated} targeted Omnibus job(s).");

    // 2. Zakazivanje poslova za sinhronizaciju promocija (postojeća logika)
    logMsg("Scheduling 'sync_promotion' and 'cleanup' jobs...");
    $promotionStores = $db->fetchAll("SELECT store_hash FROM bigcommerce_stores");
    
    $promotionJobsCreated = 0;
    $promotionJobsSkipped = 0;
    $completedJobsDeleted = 0;
    $failedJobsDeleted = 0;
    foreach ($promotionStores as $store) {
        $storeHash = $store['store_hash'];
        $db->setStoreContext($storeHash);
        $promotionService = new \App\Services\PromotionService();
        $result = $promotionService->queueAllPromotions();
        $promotionJobsCreated += $result['jobs'] ?? 0;
        $promotionJobsSkipped += $result['skipped'] ?? 0;

        $queueService = new QueueService($storeHash);
        $purgedJobs = $queueService->purgeOldJobs();
        $completedJobsDeleted += $purgedJobs['completed_deleted'] ?? 0;
        $failedJobsDeleted += $purgedJobs['failed_deleted'] ?? 0;
    }
    logMsg("-> Created {$promotionJobsCreated} promotion-related jobs. Skipped open duplicates: {$promotionJobsSkipped}.");
    logMsg("-> Purged old sync_jobs rows. Completed: {$completedJobsDeleted}, failed: {$failedJobsDeleted}.");

} catch (\Exception $e) {
    logMsg("ERROR in Scheduler: " . $e->getMessage());
}

logMsg("--- Scheduler finished ---");
