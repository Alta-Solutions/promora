<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use App\Models\Database;
use App\Services\WebhookService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from the CLI.\n";
    exit(1);
}

function logMsg(string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

function parseWebhookMonitorArgs(array $argv): array {
    $options = [
        'dry_run' => false,
        'store_hash' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        if (strpos($arg, '--store-hash=') === 0) {
            $options['store_hash'] = trim(substr($arg, strlen('--store-hash=')));
            continue;
        }

        if ($arg === '--help' || $arg === '-h') {
            echo "Usage: php bin/webhook_monitor.php [--dry-run] [--store-hash=STORE_HASH]\n";
            exit(0);
        }
    }

    return $options;
}

$options = parseWebhookMonitorArgs($argv);
$dryRun = (bool)$options['dry_run'];
$db = Database::getInstance();
$service = new WebhookService($db);
$startedAt = microtime(true);

logMsg('--- Webhook monitor started' . ($dryRun ? ' (dry-run)' : '') . ' ---');

try {
    if ($options['store_hash']) {
        $stores = [['store_hash' => $options['store_hash']]];
    } else {
        $stores = $db->fetchAll(
            "SELECT store_hash
             FROM bigcommerce_stores
             WHERE is_active = 1
             ORDER BY store_hash"
        );
    }
} catch (Throwable $e) {
    logMsg('ERROR loading stores: ' . $e->getMessage());
    exit(1);
}

if (empty($stores)) {
    logMsg('No active BigCommerce stores found.');
    logMsg('--- Webhook monitor finished ---');
    exit(0);
}

$summary = [
    'stores_checked' => 0,
    'created' => 0,
    'reactivated' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => 0,
];

foreach ($stores as $store) {
    $storeHash = trim((string)($store['store_hash'] ?? ''));
    if ($storeHash === '') {
        $summary['errors']++;
        logMsg('Skipping row with empty store_hash.');
        continue;
    }

    logMsg("Checking webhooks for store {$storeHash}...");

    try {
        $result = $service->syncWebhookStatusesForStore($storeHash, $dryRun);
        $summary['stores_checked']++;
        $summary['created'] += (int)($result['created'] ?? 0);
        $summary['reactivated'] += (int)($result['reactivated'] ?? 0);
        $summary['updated'] += (int)($result['updated'] ?? 0);
        $summary['skipped'] += (int)($result['skipped'] ?? 0);
        $summary['errors'] += (int)($result['errors'] ?? 0);

        foreach ($result['details'] ?? [] as $detail) {
            logMsg("  - {$detail}");
        }
    } catch (Throwable $e) {
        $summary['errors']++;
        logMsg("ERROR checking store {$storeHash}: " . $e->getMessage());
    }
}

$duration = round(microtime(true) - $startedAt, 2);
logMsg(
    "Summary: stores_checked={$summary['stores_checked']}, created={$summary['created']}, " .
    "reactivated={$summary['reactivated']}, updated={$summary['updated']}, " .
    "skipped={$summary['skipped']}, errors={$summary['errors']}, duration={$duration}s"
);
logMsg('--- Webhook monitor finished ---');

exit($summary['errors'] > 0 ? 1 : 0);
