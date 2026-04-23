<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use App\Services\QueueService;
use App\Models\Database;

// Helper za logovanje sa vremenom
function logMsg($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

logMsg("--- Scheduler started ---");

$db = Database::getInstance();

try {
    // 1. Zakazivanje poslova za Omnibus Sync
    logMsg("Scheduling 'omnibus_sync' jobs...");
    $omnibusStores = $db->fetchAll(
        "SELECT store_hash FROM bigcommerce_stores WHERE enable_omnibus = 1"
    );

    $omnibusJobsCreated = 0;
    foreach ($omnibusStores as $store) {
        $storeHash = $store['store_hash'];
        $db->setStoreContext($storeHash);
        $typeColumn = $db->fetchOne("SHOW COLUMNS FROM products_cache LIKE 'type'");
        $baseProductClause = $typeColumn ? " AND type = 'product'" : '';
        $totalItemsRow = $db->fetchOne(
            "SELECT COUNT(DISTINCT product_id) AS total FROM products_cache WHERE store_hash = ?" . $baseProductClause,
            [$storeHash]
        );
        $totalItems = (int)($totalItemsRow['total'] ?? 0);

        $queueService = new QueueService($storeHash); 
        $result = $queueService->createOmnibusSyncJob($totalItems > 0 ? $totalItems : 1);
        if (!empty($result['created'])) {
            $omnibusJobsCreated++;
        } else {
            logMsg("-> Skipped omnibus_sync for {$storeHash}: " . ($result['message'] ?? 'already exists'));
        }
    }
    logMsg("-> Created {$omnibusJobsCreated} 'omnibus_sync' jobs.");

    // 2. Zakazivanje poslova za sinhronizaciju promocija (postojeća logika)
    logMsg("Scheduling 'sync_promotion' and 'cleanup' jobs...");
    $promotionStores = $db->fetchAll("SELECT store_hash FROM bigcommerce_stores");
    
    $promotionJobsCreated = 0;
    foreach ($promotionStores as $store) {
        $db->setStoreContext($store['store_hash']);
        $promotionService = new \App\Services\PromotionService();
        $result = $promotionService->queueAllPromotions();
        $promotionJobsCreated += $result['jobs'] ?? 0;
    }
    logMsg("-> Created {$promotionJobsCreated} promotion-related jobs.");

} catch (\Exception $e) {
    logMsg("ERROR in Scheduler: " . $e->getMessage());
}

logMsg("--- Scheduler finished ---");
