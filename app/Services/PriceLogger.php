<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Database;

class PriceLogger {
    private $db;
    private $productsCacheHasVariantId;
    private $productsCacheHasType;
    private $priceHistoryHasVariantId;

    public function __construct(Database $db = null) {
        $this->db = $db ?? Database::getInstance();
        $this->ensurePriceHistorySchema();
    }

    /**
     * Beleži promenu cene samo ako se nova cena razlikuje od poslednje zabeležene.
     * @deprecated Use logPricesBatch for better performance.
     */
    public function logPriceChange(string $storeHash, int $productId, float $newPrice, string $currency, ?int $variantId = null): bool {
        if ($this->priceHistoryHasVariantId()) {
            $lastPriceRecord = $this->db->fetchOne(
                "SELECT price FROM product_price_history 
                 WHERE store_hash = ? AND product_id = ? AND variant_id <=> ?
                 ORDER BY recorded_at DESC 
                 LIMIT 1",
                [$storeHash, $productId, $variantId]
            );
        } else {
            $lastPriceRecord = $this->db->fetchOne(
                "SELECT price FROM product_price_history 
                 WHERE store_hash = ? AND product_id = ?
                 ORDER BY recorded_at DESC 
                 LIMIT 1",
                [$storeHash, $productId]
            );
            $variantId = null;
        }

        // Ako ne postoji zapis, ili ako je cena različita, zabeleži je.
        if (!$lastPriceRecord || (float)$lastPriceRecord['price'] !== $newPrice) {
            if ($this->priceHistoryHasVariantId()) {
                $this->db->query(
                    "INSERT INTO product_price_history (store_hash, product_id, variant_id, price, currency, recorded_at) 
                     VALUES (?, ?, ?, ?, ?, NOW())",
                    [$storeHash, $productId, $variantId, $newPrice, $currency]
                );
            } else {
                $this->db->query(
                    "INSERT INTO product_price_history (store_hash, product_id, price, currency, recorded_at) 
                     VALUES (?, ?, ?, ?, NOW())",
                    [$storeHash, $productId, $newPrice, $currency]
                );
            }
            return true;
        }

        return false;
    }

    /**
     * Efikasno beleži cene za više proizvoda odjednom.
     * Proverava poslednje zabeležene cene i upisuje samo nove ili promenjene.
     */
    public function logPricesBatch(string $storeHash, array $pricesToLog): int {
        if (empty($pricesToLog)) {
            return 0;
        }

        if (!$this->priceHistoryHasVariantId()) {
            return $this->logPricesBatchWithoutVariants($storeHash, $pricesToLog);
        }

        // Kreiramo jedinstvene ključeve za svaki proizvod/varijantu da bismo dohvatili poslednje cene
        $productVariantPairs = [];
        foreach ($pricesToLog as $item) {
            $productVariantPairs[] = ['product_id' => $item['product_id'], 'variant_id' => $item['variant_id'] ?? null];
        }
        $productVariantPairs = array_unique($productVariantPairs, SORT_REGULAR);
        if(empty($productVariantPairs)) return 0;

        // 1. Dohvati poslednje zabeležene cene za sve parove (proizvod, varijanta) u batch-u
        $whereClauses = array_map(fn() => '(product_id = ? AND variant_id <=> ?)', $productVariantPairs);
        $pairBindings = [];
        foreach ($productVariantPairs as $pair) {
            $pairBindings[] = $pair['product_id'];
            $pairBindings[] = $pair['variant_id'] ?? null;
        }
        $bindings = array_merge([$storeHash], $pairBindings, [$storeHash]);
        $sql = "
            SELECT p.product_id, p.variant_id, p.price
            FROM product_price_history p
            INNER JOIN (
                SELECT product_id, variant_id, MAX(recorded_at) as max_recorded_at
                FROM product_price_history
                WHERE store_hash = ? AND (" . implode(' OR ', $whereClauses) . ")
                GROUP BY product_id, variant_id
            ) as last_records ON p.product_id = last_records.product_id AND p.variant_id <=> last_records.variant_id AND p.recorded_at = last_records.max_recorded_at AND p.store_hash = ?";
        $lastPricesResult = $this->db->fetchAll($sql, $bindings);
        $lastPricesMap = [];
        foreach ($lastPricesResult as $row) {
            $key = $row['product_id'] . '_' . ($row['variant_id'] ?? 'base');
            $lastPricesMap[$key] = $row['price'];
        }

        // 2. Pripremi batch INSERT samo za nove ili promenjene cene
        $insertValues = [];
        $insertParams = [];
        $uniquePricesInBatch = []; // Sprečava dupli upis iste cene za isti proizvod (npr. od varijanti)

        foreach ($pricesToLog as $item) {
            $productId = $item['product_id'];
            $newPrice = $item['price'];
            $variantId = $item['variant_id'] ?? null;
            
            $uniqueKey = $productId . '_' . ($variantId ?? 'base') . '_' . $newPrice;
            if (isset($uniquePricesInBatch[$uniqueKey])) {
                continue;
            }
            $uniquePricesInBatch[$uniqueKey] = true;

            $mapKey = $productId . '_' . ($variantId ?? 'base');
            $lastPrice = $lastPricesMap[$mapKey] ?? null;

            if ($lastPrice === null || (float)$lastPrice !== $newPrice) {
                $insertValues[] = '(?, ?, ?, ?, ?, NOW())';
                $insertParams = array_merge($insertParams, [$storeHash, $productId, $variantId, $newPrice, $item['currency']]);
            }
        }

        // 3. Izvrši batch INSERT
        if (!empty($insertValues)) {
            $sql = "INSERT INTO product_price_history (store_hash, product_id, variant_id, price, currency, recorded_at) VALUES " . implode(', ', $insertValues);
            $this->db->query($sql, $insertParams);
            return count($insertValues);
        }

        return 0;
    }

    /**
     * Primenjuje ključni algoritam za pronalaženje najniže cene u poslednjih 30 dana za jedan proizvod.
     */
    public function getLowestPriceIn30Days(string $storeHash, int $productId, ?int $variantId = null, ?string $currency = null): ?float {
        $storeConfig = $this->db->fetchOne(
            "SELECT currency FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );
        $currency = $currency ?? ($storeConfig['currency'] ?? 'USD');

        $pricingService = new OmnibusPricingService($this->db);
        $dto = $pricingService->getDisplayData(
            $storeHash,
            $productId,
            $this->priceHistoryHasVariantId() ? $variantId : null,
            $currency
        );

        return $dto['lowest_price_last_30_days'] !== null
            ? (float)$dto['lowest_price_last_30_days']
            : null;
    }

    /**
     * Optimizovana batch metoda. Za svaki prosleđeni ID proizvoda, pronalazi najnižu cenu
     * u poslednjih 30 dana uzimajući u obzir i osnovni proizvod i sve njegove varijante.
     */
    public function getLowestPricesIn30DaysBatch(string $storeHash, array $productIds): array {
        if (empty($productIds)) {
            return [];
        }

        if (!$this->priceHistoryHasVariantId()) {
            return $this->getLowestPricesIn30DaysBatchWithoutVariants($storeHash, $productIds);
        }

        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        $results = $this->productsCacheHasType()
            ? $this->getLowestPricesIn30DaysBatchWithType($storeHash, $productIds, $placeholders, $thirtyDaysAgo)
            : $this->getLowestPricesIn30DaysBatchWithoutType($storeHash, $productIds, $placeholders, $thirtyDaysAgo);

        // Konvertujemo rezultat u mapu [product_id => lowest_price] i osiguravamo float tip
        $lowestPrices = [];
        foreach ($results as $row) {
            $lowestPrices[$row['product_id']] = (float)$row['lowest_price'];
        }

        return $lowestPrices;
    }

    private function logPricesBatchWithoutVariants(string $storeHash, array $pricesToLog): int {
        $productIds = [];
        $latestPriceByProduct = [];

        foreach ($pricesToLog as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $productIds[] = $productId;
            $latestPriceByProduct[$productId] = [
                'price' => (float)$item['price'],
                'currency' => $item['currency'],
            ];
        }

        $productIds = array_values(array_unique($productIds));
        if (empty($productIds)) {
            return 0;
        }

        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        $bindings = array_merge([$storeHash], $productIds, [$storeHash]);
        $sql = "
            SELECT p.product_id, p.price
            FROM product_price_history p
            INNER JOIN (
                SELECT product_id, MAX(recorded_at) as max_recorded_at
                FROM product_price_history
                WHERE store_hash = ? AND product_id IN ($placeholders)
                GROUP BY product_id
            ) as last_records
                ON p.product_id = last_records.product_id
               AND p.recorded_at = last_records.max_recorded_at
               AND p.store_hash = ?
        ";
        $lastPricesResult = $this->db->fetchAll($sql, $bindings);
        $lastPricesMap = [];
        foreach ($lastPricesResult as $row) {
            $lastPricesMap[(int)$row['product_id']] = (float)$row['price'];
        }

        $insertValues = [];
        $insertParams = [];
        foreach ($latestPriceByProduct as $productId => $item) {
            $lastPrice = $lastPricesMap[$productId] ?? null;
            if ($lastPrice === null || $lastPrice !== (float)$item['price']) {
                $insertValues[] = '(?, ?, ?, ?, NOW())';
                $insertParams = array_merge($insertParams, [$storeHash, $productId, $item['price'], $item['currency']]);
            }
        }

        if (empty($insertValues)) {
            return 0;
        }

        $sql = "INSERT INTO product_price_history (store_hash, product_id, price, currency, recorded_at) VALUES " . implode(', ', $insertValues);
        $this->db->query($sql, $insertParams);

        return count($insertValues);
    }

    private function getLowestPricesIn30DaysBatchWithoutVariants(string $storeHash, array $productIds): array {
        $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        $bindings = array_merge([$storeHash], $productIds, [$thirtyDaysAgo], [$storeHash], $productIds, [$thirtyDaysAgo]);

        $sql = "
            SELECT history.product_id, MIN(history.price) as lowest_price
            FROM (
                SELECT product_id, price
                FROM product_price_history
                WHERE store_hash = ? AND product_id IN ($placeholders) AND recorded_at >= ?

                UNION ALL

                SELECT ph.product_id, ph.price
                FROM product_price_history ph
                INNER JOIN (
                    SELECT product_id, MAX(recorded_at) as max_recorded_at
                    FROM product_price_history
                    WHERE store_hash = ? AND product_id IN ($placeholders) AND recorded_at < ?
                    GROUP BY product_id
                ) as last_prices
                    ON ph.product_id = last_prices.product_id
                   AND ph.recorded_at = last_prices.max_recorded_at
            ) as history
            GROUP BY history.product_id
        ";

        $results = $this->db->fetchAll($sql, $bindings);
        $lowestPrices = [];
        foreach ($results as $row) {
            $lowestPrices[$row['product_id']] = (float)$row['lowest_price'];
        }

        return $lowestPrices;
    }

    private function getLowestPricesIn30DaysBatchWithType(string $storeHash, array $productIds, string $placeholders, string $thirtyDaysAgo): array {
        $familyBindings = array_merge([$storeHash], $productIds);
        $historyBindings = array_merge([$storeHash], $productIds, [$thirtyDaysAgo], [$storeHash], $productIds, [$thirtyDaysAgo]);
        $allBindings = array_merge($familyBindings, $historyBindings);

        $sql = "
            SELECT families.product_id, MIN(history.price) as lowest_price
            FROM (
                SELECT product_id,
                       MAX(CASE WHEN type = 'variant' THEN 1 ELSE 0 END) as has_variants
                FROM products_cache
                WHERE store_hash = ? AND product_id IN ($placeholders)
                GROUP BY product_id
            ) as families
            LEFT JOIN (
                SELECT product_id, variant_id, price
                FROM product_price_history
                WHERE store_hash = ? AND product_id IN ($placeholders) AND recorded_at >= ?

                UNION ALL

                SELECT ph.product_id, ph.variant_id, ph.price
                FROM product_price_history ph
                INNER JOIN (
                    SELECT product_id, variant_id, MAX(recorded_at) as max_recorded_at
                    FROM product_price_history
                    WHERE store_hash = ? AND product_id IN ($placeholders) AND recorded_at < ?
                    GROUP BY product_id, variant_id
                ) as last_prices
                    ON ph.product_id = last_prices.product_id
                   AND ph.variant_id <=> last_prices.variant_id
                   AND ph.recorded_at = last_prices.max_recorded_at
            ) as history
                ON history.product_id = families.product_id
               AND (
                    (families.has_variants = 1 AND history.variant_id IS NOT NULL)
                    OR (families.has_variants = 0 AND history.variant_id IS NULL)
               )
            GROUP BY families.product_id
        ";

        return $this->db->fetchAll($sql, $allBindings);
    }

    private function getLowestPricesIn30DaysBatchWithoutType(string $storeHash, array $productIds, string $placeholders, string $thirtyDaysAgo): array {
        $bindings = array_merge([$storeHash], $productIds, [$thirtyDaysAgo], [$storeHash], $productIds, [$thirtyDaysAgo]);
        $sql = "
            SELECT history.product_id, MIN(history.price) as lowest_price
            FROM (
                SELECT product_id, price
                FROM product_price_history
                WHERE store_hash = ? AND product_id IN ($placeholders) AND variant_id IS NULL AND recorded_at >= ?

                UNION ALL

                SELECT ph.product_id, ph.price
                FROM product_price_history ph
                INNER JOIN (
                    SELECT product_id, MAX(recorded_at) as max_recorded_at
                    FROM product_price_history
                    WHERE store_hash = ? AND product_id IN ($placeholders) AND variant_id IS NULL AND recorded_at < ?
                    GROUP BY product_id
                ) as last_prices
                    ON ph.product_id = last_prices.product_id
                   AND ph.recorded_at = last_prices.max_recorded_at
                   AND ph.variant_id IS NULL
            ) as history
            GROUP BY history.product_id
        ";

        return $this->db->fetchAll($sql, $bindings);
    }

    private function productsCacheHasVariantId(): bool {
        if ($this->productsCacheHasVariantId !== null) {
            return $this->productsCacheHasVariantId;
        }

        try {
            $column = $this->db->fetchOne("SHOW COLUMNS FROM products_cache LIKE 'variant_id'");
            $this->productsCacheHasVariantId = $column !== false && $column !== null;
        } catch (\Throwable $e) {
            $this->productsCacheHasVariantId = false;
        }

        return $this->productsCacheHasVariantId;
    }

    private function productsCacheHasType(): bool {
        if ($this->productsCacheHasType !== null) {
            return $this->productsCacheHasType;
        }

        try {
            $column = $this->db->fetchOne("SHOW COLUMNS FROM products_cache LIKE 'type'");
            $this->productsCacheHasType = $column !== false && $column !== null;
        } catch (\Throwable $e) {
            $this->productsCacheHasType = false;
        }

        return $this->productsCacheHasType;
    }

    private function priceHistoryHasVariantId(): bool {
        if ($this->priceHistoryHasVariantId !== null) {
            return $this->priceHistoryHasVariantId;
        }

        try {
            $column = $this->db->fetchOne("SHOW COLUMNS FROM product_price_history LIKE 'variant_id'");
            $this->priceHistoryHasVariantId = $column !== false && $column !== null;
        } catch (\Throwable $e) {
            $this->priceHistoryHasVariantId = false;
        }

        return $this->priceHistoryHasVariantId;
    }

    private function ensurePriceHistorySchema(): void {
        try {
            $column = $this->db->fetchOne("SHOW COLUMNS FROM product_price_history LIKE 'variant_id'");
            if (!$column) {
                return;
            }

            $this->priceHistoryHasVariantId = true;

            if (($column['Null'] ?? 'NO') !== 'YES') {
                $this->db->query(
                    "ALTER TABLE product_price_history MODIFY COLUMN variant_id INT UNSIGNED NULL"
                );
            }
        } catch (\Throwable $e) {
            // Ne blokiramo izvršavanje ako schema check ne uspe; postoje fallback grane za stare tabele.
        }
    }
}
