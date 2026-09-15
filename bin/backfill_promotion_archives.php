<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

use App\Models\Database;
use App\Services\PromotionArchiveService;

$options = getopt('', ['store-hash:', 'limit::', 'apply']);
$storeHash = trim((string)($options['store-hash'] ?? ''));
$limit = max(1, min(1000, (int)($options['limit'] ?? 500)));
$apply = array_key_exists('apply', $options);

if ($storeHash === '') {
    fwrite(STDERR, "Usage: php bin/backfill_promotion_archives.php --store-hash=STORE [--limit=500] [--apply]\n");
    fwrite(STDERR, "Default mode is dry-run. Add --apply to write local archive rows.\n");
    exit(2);
}

$db = Database::getInstance();
$db->setStoreContext($storeHash);

$rows = $db->fetchAll(
    "SELECT p.id, p.name, p.end_date
     FROM promotions p
     LEFT JOIN promotion_archives pa
        ON pa.store_hash = ?
       AND pa.promotion_id = p.id
     WHERE p.store_hash = ?
       AND pa.id IS NULL
       AND (p.status = 'expired' OR p.end_date < NOW())
     ORDER BY p.end_date DESC, p.id DESC
     LIMIT " . (int)$limit,
    [$storeHash, $storeHash]
);

echo "Store: {$storeHash}\n";
echo "Mode: " . ($apply ? 'apply' : 'dry-run') . "\n";
echo "Candidates: " . count($rows) . "\n";

if (!$apply || empty($rows)) {
    foreach ($rows as $row) {
        echo "Would backfill promotion #{$row['id']} {$row['name']} ({$row['end_date']})\n";
    }
    exit(0);
}

$service = new PromotionArchiveService($db, $storeHash);
$processed = 0;
foreach ($rows as $row) {
    if ($service->finalizeArchive((int)$row['id'], 'backfill') !== null) {
        $processed++;
    }
}

echo "Backfilled: {$processed}\n";
