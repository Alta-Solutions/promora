<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
\App\Support\CronAuth::enforce();

use App\Services\OmnibusSyncSchedulerService;
use App\Services\QueueService;
use App\Models\Database;

// Helper za logovanje sa vremenom
function logMsg($msg) {
    if (in_array('--background', $_SERVER['argv'] ?? [], true)) {
        return;
    }
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

logMsg("--- Scheduler started ---");

$db = Database::getInstance();

$stores = $db->fetchAll("SELECT store_hash, enable_omnibus FROM bigcommerce_stores WHERE is_active=1");
$errors = 0;
foreach ($stores as $store) {
    $hash = (string)$store['store_hash'];
    $db->setStoreContext($hash);
    try {
        (new \App\Services\PromotionService($db))->queueAllPromotions();
        if ($store['enable_omnibus']) {
            (new OmnibusSyncSchedulerService($db))->scheduleStore($hash);
        }
    } catch (\Throwable $e) {
        $errors++;
        \App\Support\OperationalLog::write('error', ['operation' => 'schedule', 'store_hash' => $hash, 'exception' => get_class($e)]);
        logMsg("Scheduling failed for {$hash}: " . $e->getMessage());
    }
}
logMsg("Scheduled " . count($stores) . " stores; errors={$errors}");
exit($errors ? 1 : 0);
