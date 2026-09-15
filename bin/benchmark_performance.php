<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

use App\Models\Database;
use App\Models\Promotion;
use App\Services\ProductCacheService;

$_ENV['BIGCOMMERCE_READ_ONLY'] = 'true';
putenv('BIGCOMMERCE_READ_ONLY=true');

$options = getopt('', ['store-hash::', 'runs::']);
$runs = max(1, (int)($options['runs'] ?? 20));

$db = Database::getInstance();
$storeHash = trim((string)($options['store-hash'] ?? ''));
if ($storeHash === '') {
    $row = $db->fetchOne("SELECT store_hash FROM bigcommerce_stores ORDER BY last_accessed DESC, id ASC LIMIT 1");
    $storeHash = (string)($row['store_hash'] ?? '');
}

if ($storeHash === '') {
    fwrite(STDERR, "Usage: php bin/benchmark_performance.php --store-hash=STORE [--runs=20]\n");
    exit(2);
}

$db->setStoreContext($storeHash);
$promotionModel = new Promotion();
$cacheService = new ProductCacheService($db);

$benchmarks = [
    'promotion_list_page_25' => static function () use ($promotionModel): void {
        $promotionModel->findAll(true, ['limit' => 25, 'offset' => 0]);
    },
    'filter_stats_all_cache' => static function () use ($cacheService): void {
        $cacheService->getFilterStats([]);
    },
    'omnibus_keyset_page_50' => static function () use ($db, $storeHash): void {
        $row = $db->fetchOne(
            "SELECT product_id
             FROM products_cache
             WHERE store_hash = ?
             ORDER BY product_id DESC
             LIMIT 1",
            [$storeHash]
        );
        $lastProductId = max(0, ((int)($row['product_id'] ?? 0)) - 1000);
        $db->fetchAll(
            "SELECT DISTINCT product_id
             FROM products_cache
             WHERE store_hash = ?
               AND type = 'product'
               AND product_id > ?
             ORDER BY product_id ASC
             LIMIT 50",
            [$storeHash, $lastProductId]
        );
    },
];

echo "Store: {$storeHash}\n";
echo "Runs: {$runs}\n";
echo "Read-only: " . ($_ENV['BIGCOMMERCE_READ_ONLY'] ?? '') . "\n";

foreach ($benchmarks as $name => $callback) {
    $samples = [];
    for ($i = 0; $i < $runs; $i++) {
        $beforeMemory = memory_get_usage(true);
        $startedAt = microtime(true);
        $callback();
        $samples[] = [
            'ms' => (microtime(true) - $startedAt) * 1000,
            'memory_delta' => memory_get_usage(true) - $beforeMemory,
        ];
    }

    $times = array_column($samples, 'ms');
    sort($times, SORT_NUMERIC);
    $memoryDeltas = array_column($samples, 'memory_delta');
    echo sprintf(
        "%s p50=%.2fms p95=%.2fms max_memory_delta=%d bytes\n",
        $name,
        percentile($times, 50),
        percentile($times, 95),
        max($memoryDeltas)
    );
}

function percentile(array $values, int $percentile): float {
    if (empty($values)) {
        return 0.0;
    }

    $index = (int)ceil(($percentile / 100) * count($values)) - 1;
    $index = max(0, min(count($values) - 1, $index));
    return (float)$values[$index];
}
