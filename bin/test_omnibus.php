<?php
/**
 * Test skripta za Omnibus Lowest Price Tracker modul.
 *
 * KAKO KORISTITI:
 * 1. Podesite $testStoreHash i $testProductId ispod.
 * 2. Pokrenite iz komandne linije: php bin/test_omnibus.php
 * 3. Proverite izlaz u terminalu i "custom field" na proizvodu u BigCommerce adminu.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use App\Models\Database;
use App\Services\PriceLogger;
use App\Services\OmnibusSyncService;
use App\Services\ProductCacheService;

$testStoreHash = 'fa8il6vy5q';
$testProductId = 5136;
$runRemoteSync = getenv('OMNIBUS_TEST_REMOTE_SYNC') === '1';

function logMsg($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

if ($testStoreHash === 'your_store_hash_here' || $testProductId === 123) {
    die("Molimo podesite \$testStoreHash i \$testProductId u skripti pre pokretanja.\n");
}

logMsg("--- Pocetak Omnibus Testa za prodavnicu '{$testStoreHash}' i proizvod '{$testProductId}' ---");

try {
    $db = Database::getInstance();
    $db->setStoreContext($testStoreHash);

    $db->query("UPDATE bigcommerce_stores SET enable_omnibus = 1, currency = 'EUR' WHERE store_hash = ?", [$testStoreHash]);
    logMsg("Osigurano da je 'enable_omnibus' ukljucen za prodavnicu.");

    $priceLogger = new PriceLogger($db);

    logMsg("\n--- Deo 1: Simulacija Istorije Cena ---");

    $db->query("DELETE FROM product_price_history WHERE store_hash = ? AND product_id = ?", [$testStoreHash, $testProductId]);
    logMsg("Ociscena stara istorija cena za proizvod {$testProductId}.");

    $prices = [
        ['-45 days', 100.00],
        ['-28 days', 120.00],
        ['-20 days', 95.50],
        ['-10 days', 110.00],
        ['-2 days', 98.00],
    ];

    foreach ($prices as [$time, $price]) {
        $date = date('Y-m-d H:i:s', strtotime($time));
        $db->query(
            "INSERT INTO product_price_history (store_hash, product_id, price, currency, recorded_at) VALUES (?, ?, ?, 'EUR', ?)",
            [$testStoreHash, $testProductId, $price, $date]
        );
        logMsg("Zabelezena cena: {$price} EUR na datum {$date}");
    }
    logMsg("Simulacija istorije cena je zavrsena.");

    logMsg("\n--- Deo 2: Testiranje Logike Racunanja ---");
    $expectedLowestPrice = 95.50;
    $calculatedPrice = $priceLogger->getLowestPriceIn30Days($testStoreHash, $testProductId);

    if ((float)$calculatedPrice === $expectedLowestPrice) {
        logMsg("USPEH: getLowestPriceIn30Days() je vratio ispravnu cenu: {$calculatedPrice} EUR.");
    } else {
        throw new RuntimeException("getLowestPriceIn30Days() je vratio {$calculatedPrice}, a ocekivano je {$expectedLowestPrice}.");
    }

    logMsg("\n--- Deo 3: Testiranje Sinhronizacije (API Update) ---");
    if (!$runRemoteSync) {
        logMsg("Preskacem BigCommerce API korak. Za puni integration test pokrenite sa OMNIBUS_TEST_REMOTE_SYNC=1.");
        logMsg("Lokalna Omnibus logika je prosla i skripta se zavrsava bez greske.");
        logMsg("\n--- Test je zavrsen ---");
        exit(0);
    }

    $cacheService = new ProductCacheService($db);
    $productInCache = $cacheService->getProductsByFilters(['product_id' => $testProductId], 1);

    if (empty($productInCache)) {
        logMsg("Proizvod nije u kesu. Preuzimanje sa BigCommerce-a...");
        $api = new \App\Services\BigCommerceAPI($testStoreHash);
        $productData = $api->call('GET', "catalog/products/{$testProductId}?include=custom_fields");
        if (!empty($productData['body']['data'])) {
            $cacheService->batchCacheProducts([$productData['body']['data']]);
            logMsg("Proizvod {$testProductId} je uspesno kesiran.");
        } else {
            throw new RuntimeException("Nije moguce preuzeti proizvod {$testProductId} sa BigCommerce-a.");
        }
    }

    $syncService = new OmnibusSyncService($testStoreHash);
    $result = $syncService->processBatch([['product_id' => $testProductId]]);

    if (isset($result['success']) && $result['success'] > 0) {
        logMsg("USPEH: OmnibusSyncService::processBatch je prijavio uspeh.");
        logMsg("Molimo proverite BigCommerce admin za proizvod ID {$testProductId}.");
        logMsg("Trebalo bi da postoji custom field 'lowest_price_30d' sa vrednoscu '{$expectedLowestPrice}'.");
    } else {
        throw new RuntimeException(
            "OmnibusSyncService::processBatch je prijavio gresku: " . json_encode($result, JSON_UNESCAPED_SLASHES)
        );
    }

    logMsg("\n--- Test je zavrsen ---");
} catch (\Throwable $e) {
    logMsg("Desila se greska: " . $e->getMessage());
    logMsg($e->getTraceAsString());
    exit(1);
}
