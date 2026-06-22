<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use App\Models\Database;
use App\Services\OmnibusLowestPriceRepairService;

function printUsage(): void {
    echo "Usage:\n";
    echo "  php bin/repair_omnibus_lowest_price.php --store-hash=STORE [--promotion-id=ID] [--product-id=ID] [--apply] [--sync-remote]\n\n";
    echo "Options:\n";
    echo "  --store-hash     Required store_hash scope.\n";
    echo "  --promotion-id   Optional promotion id filter.\n";
    echo "  --product-id     Optional product id filter.\n";
    echo "  --apply          Apply local DB repair. Without this flag the command is dry-run only.\n";
    echo "  --sync-remote    After --apply, run targeted Omnibus sync to write BigCommerce metafields.\n";
    echo "  --help           Show this help.\n";
}

function printResult(array $result): void {
    echo ($result['apply'] ? "APPLY" : "DRY RUN") . " for store {$result['store_hash']}\n";
    echo "Candidates: {$result['total_candidates']}\n";
    echo "Applied: {$result['applied_count']}\n";
    echo "Skipped: {$result['skipped_count']}\n";

    if (!empty($result['reason_counts'])) {
        echo "Reasons:\n";
        foreach ($result['reason_counts'] as $reason => $count) {
            echo "  {$reason}: {$count}\n";
        }
    }

    if (!empty($result['results'])) {
        echo "\nRows:\n";
        foreach ($result['results'] as $row) {
            $variant = $row['variant_id'] === null ? 'parent' : (string)$row['variant_id'];
            $details = [
                "status={$row['status']}",
                "reason={$row['reason']}",
                "promotion_id={$row['promotion_id']}",
                "product_id={$row['product_id']}",
                "variant={$variant}",
                "old_reference_at={$row['old_reference_at']}",
            ];

            if (!empty($row['new_reference_at'])) {
                $details[] = "new_reference_at={$row['new_reference_at']}";
            }

            if (!empty($row['seed_recorded_at'])) {
                $details[] = "seed_recorded_at={$row['seed_recorded_at']}";
                $details[] = "seed_price={$row['seed_price']}";
            }

            echo "  - " . implode(' ', $details) . "\n";
        }
    }

    if ($result['remote_sync'] !== null) {
        echo "\nRemote sync:\n";
        echo json_encode($result['remote_sync'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

$options = getopt('', [
    'store-hash:',
    'promotion-id:',
    'product-id:',
    'apply',
    'sync-remote',
    'help',
]);

if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$storeHash = isset($options['store-hash']) ? trim((string)$options['store-hash']) : '';
$promotionId = isset($options['promotion-id']) ? (int)$options['promotion-id'] : null;
$productId = isset($options['product-id']) ? (int)$options['product-id'] : null;
$apply = isset($options['apply']);
$syncRemote = isset($options['sync-remote']);

if ($storeHash === '') {
    fwrite(STDERR, "Error: --store-hash is required.\n\n");
    printUsage();
    exit(1);
}

if ($syncRemote && !$apply) {
    fwrite(STDERR, "Error: --sync-remote requires --apply.\n");
    exit(1);
}

try {
    $db = Database::getInstance();
    $db->setStoreContext($storeHash);

    $service = new OmnibusLowestPriceRepairService($db);
    $result = $service->run($storeHash, $promotionId, $productId, $apply, $syncRemote);
    printResult($result);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
