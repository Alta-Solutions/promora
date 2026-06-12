<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use App\Models\Database;
use App\Services\ProductCacheService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from the CLI.\n";
    exit(1);
}

if (function_exists('set_time_limit')) {
    set_time_limit(0);
}

function logMsg(string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

$db = Database::getInstance();
$startedAt = microtime(true);

logMsg('--- Full cache sync for active stores started ---');

try {
    $stores = $db->fetchAll(
        'SELECT store_hash FROM bigcommerce_stores WHERE is_active = 1 ORDER BY store_hash'
    );
} catch (Throwable $e) {
    logMsg('ERROR loading active stores: ' . $e->getMessage());
    exit(1);
}

if (empty($stores)) {
    logMsg('No active BigCommerce stores found.');
    logMsg('--- Full cache sync finished ---');
    exit(0);
}

$syncedStores = 0;
$failedStores = 0;
$totalProducts = 0;
$totalErrors = 0;

foreach ($stores as $store) {
    $storeHash = (string)($store['store_hash'] ?? '');

    if ($storeHash === '') {
        $failedStores++;
        logMsg('Skipping store row with empty store_hash.');
        continue;
    }

    logMsg("Starting full cache sync for store {$storeHash}.");

    try {
        $db->setStoreContext($storeHash);
        $cacheService = new ProductCacheService($db);
        $result = $cacheService->fullSync();

        $storeTotal = (int)($result['total'] ?? 0);
        $storeErrors = (int)($result['errors'] ?? 0);

        $totalProducts += $storeTotal;
        $totalErrors += $storeErrors;

        if ($storeErrors > 0) {
            $failedStores++;
            logMsg("Finished store {$storeHash} with {$storeErrors} cache errors.");
            continue;
        }

        $syncedStores++;
        logMsg("Finished store {$storeHash}; cached {$storeTotal} products.");
    } catch (Throwable $e) {
        $failedStores++;
        logMsg("ERROR syncing store {$storeHash}: " . $e->getMessage());
    }
}

$duration = round(microtime(true) - $startedAt, 2);

logMsg(
    "Summary: stores_synced={$syncedStores}, stores_failed={$failedStores}, " .
    "products_seen={$totalProducts}, product_errors={$totalErrors}, duration={$duration}s"
);
logMsg('--- Full cache sync for active stores finished ---');

exit($failedStores > 0 ? 1 : 0);
