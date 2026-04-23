<?php
/**
 * Pomoćna skripta za masovno brisanje 'lowest_price_30d' custom field-a sa svih proizvoda.
 *
 * KAKO KORISTITI:
 * 1. Podesite $storeHash ispod sa ID-jem prodavnice koju želite da očistite.
 * 2. Pokrenite iz komandne linije: php bin/cleanup_lowest_price_field.php
 * 3. Skripta će proći kroz sve proizvode i obrisati polje ako postoji.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use App\Models\Database;
use App\Services\OmnibusFieldService;
use App\Services\BigCommerceAPI;

// --- KONFIGURACIJA ---
// Zamenite sa pravim store_hash iz vaše baze
$storeHash = 'zmgdyj2jxr'; 

// Veličina batch-a za obradu (koliko proizvoda se obrađuje odjednom)
const BATCH_SIZE = 50;
// --- KRAJ KONFIGURACIJE ---

function logMsg($msg) {
    echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

if (empty($storeHash) || $storeHash === 'your_store_hash_here') {
    die("❌ Molimo podesite \$storeHash u skripti pre pokretanja.\n");
}

logMsg("--- Početak brisanja 'lowest_price_30d' custom field-a za prodavnicu '{$storeHash}' ---");

try {
    $db = Database::getInstance();
    $db->setStoreContext($storeHash);

    $api = new BigCommerceAPI($storeHash);
    $omnibusFieldService = new OmnibusFieldService($api);

    // Dohvatanje svih proizvoda iz lokalnog keša
    $totalProducts = $db->fetchOne("SELECT COUNT(*) as cnt FROM products_cache WHERE store_hash = ?", [$storeHash])['cnt'];
    logMsg("Pronađeno {$totalProducts} proizvoda u lokalnom kešu za obradu.");

    if ($totalProducts == 0) {
        logMsg("Nema proizvoda u kešu. Prekidam izvršavanje.");
        exit;
    }

    $processedCount = 0;
    $deletedCount = 0;

    for ($offset = 0; $offset < $totalProducts; $offset += BATCH_SIZE) {
        $products = $db->fetchAll(
            "SELECT product_id FROM products_cache WHERE store_hash = ? LIMIT ? OFFSET ?",
            [$storeHash, BATCH_SIZE, $offset]
        );
        
        $productIds = array_column($products, 'product_id');
        
        logMsg("Obrada batch-a: " . ($offset + 1) . " - " . ($offset + count($productIds)) . " od {$totalProducts}");

        // Pozivamo metodu za masovno brisanje
        $results = $omnibusFieldService->batchRemoveLowestPriceFields($productIds);
        $deletedInBatch = count(array_filter($results, fn($r) => $r['status'] === 204));
        $deletedCount += $deletedInBatch;
        $processedCount += count($productIds);
    }

    logMsg("\n--- Brisanje završeno ---");
    logMsg("Ukupno obrađeno proizvoda: {$processedCount}");
    logMsg("Uspešno obrisano polja: {$deletedCount}");
    logMsg("Napomena: Broj obrisanih polja može biti manji od broja obrađenih proizvoda ako neki proizvodi nisu ni imali to polje.");

} catch (\Exception $e) {
    logMsg("❌ Desila se kritična greška: " . $e->getMessage());
    logMsg($e->getTraceAsString());
}
