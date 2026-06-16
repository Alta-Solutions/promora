<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Database;

class OmnibusLowestPriceRepairService {
    private $db;
    private $pricingService;
    private $priceLogger;
    private $priceHistoryHasVariantId;
    private $promotionProductsHasOmnibusReferenceAt;
    private $promotionsHasOmnibusTermsUpdatedAt;

    private const SALE_BEFORE_REFERENCE_TOLERANCE_SECONDS = 300;

    public function __construct($db = null, $pricingService = null, $priceLogger = null) {
        $this->db = $db ?? Database::getInstance();
        PriceHistorySchemaService::ensureIgnoredColumns($this->db);
        $this->pricingService = $pricingService ?? new OmnibusPricingService($this->db);
        $this->priceLogger = $priceLogger ?? new PriceLogger($this->db);
    }

    public function analyze(string $storeHash, ?int $promotionId = null, ?int $productId = null): array {
        $currency = $this->fetchStoreCurrency($storeHash);
        return $this->findRepairCandidates($storeHash, $currency, $promotionId, $productId);
    }

    public function run(
        string $storeHash,
        ?int $promotionId = null,
        ?int $productId = null,
        bool $apply = false,
        bool $syncRemote = false
    ): array {
        if ($syncRemote && !$apply) {
            throw new \InvalidArgumentException('--sync-remote requires --apply.');
        }

        if (trim($storeHash) === '') {
            throw new \InvalidArgumentException('--store-hash is required.');
        }

        $this->db->setStoreContext($storeHash);
        $candidates = $this->analyze($storeHash, $promotionId, $productId);
        $results = [];

        if ($apply) {
            foreach ($candidates as $candidate) {
                $results[] = $this->applyCandidate($storeHash, $candidate);
            }
        } else {
            $results = array_map(static function (array $candidate): array {
                $candidate['status'] = 'dry_run';
                return $candidate;
            }, $candidates);
        }

        $remoteSyncResult = null;
        if ($apply && $syncRemote) {
            $productIds = $this->extractAppliedProductIds($results);
            if (!empty($productIds)) {
                $remoteSyncResult = (new OmnibusSyncService($storeHash))->processBatch($productIds);
            }
        }

        return [
            'store_hash' => $storeHash,
            'promotion_id' => $promotionId,
            'product_id' => $productId,
            'apply' => $apply,
            'sync_remote' => $syncRemote,
            'total_candidates' => count($candidates),
            'reason_counts' => $this->countByReason($candidates),
            'applied_count' => count(array_filter($results, static function (array $result): bool {
                return ($result['status'] ?? null) === 'applied';
            })),
            'skipped_count' => count(array_filter($results, static function (array $result): bool {
                return ($result['status'] ?? null) === 'skipped';
            })),
            'results' => $results,
            'remote_sync' => $remoteSyncResult,
        ];
    }

    private function findRepairCandidates(
        string $storeHash,
        string $currency,
        ?int $promotionId,
        ?int $productId
    ): array {
        $rows = $this->fetchActiveDiscountedPromotionRows($storeHash, $promotionId, $productId);
        $candidates = [];

        foreach ($rows as $row) {
            $referenceAt = $this->resolveActivePromotionReferenceAt($row);
            $currentPrice = $this->normalizePrice($row['sale_price'] ?? null);
            $regularPrice = $this->normalizePrice($row['price'] ?? null);

            if ($currentPrice === null || $currentPrice <= 0 || $regularPrice === null || $regularPrice <= 0) {
                continue;
            }

            $dto = $this->pricingService->getDisplayData(
                $storeHash,
                (int)$row['product_id'],
                $this->normalizeVariantId($row['variant_id'] ?? null),
                $currency,
                $currentPrice,
                $referenceAt,
                [
                    'current_price_observed_at' => $row['cached_at'] ?? null,
                    'require_full_30_days_history' => true,
                ]
            );

            $invalidReason = (string)($dto['invalid_reduction_reason'] ?? '');
            if ($invalidReason === 'missing_reference_price') {
                $candidates[] = $this->buildMissingReferenceCandidate(
                    $row,
                    $currency,
                    $referenceAt,
                    $regularPrice,
                    $currentPrice,
                    $dto
                );
                continue;
            }

            if ($invalidReason !== 'not_below_30_day_lowest') {
                continue;
            }

            if ($this->normalizeOptionalDateTime($row['omnibus_reference_at'] ?? null) === null) {
                continue;
            }

            $saleBeforeReferenceAt = $this->findSaleBeforeReferenceAt(
                $storeHash,
                $row,
                $currency,
                $currentPrice,
                $referenceAt
            );

            if ($saleBeforeReferenceAt === null) {
                continue;
            }

            $repairDto = $this->pricingService->getDisplayData(
                $storeHash,
                (int)$row['product_id'],
                $this->normalizeVariantId($row['variant_id'] ?? null),
                $currency,
                $currentPrice,
                $saleBeforeReferenceAt,
                [
                    'current_price_observed_at' => $row['cached_at'] ?? null,
                    'require_full_30_days_history' => true,
                ]
            );

            if (empty($repairDto['is_valid_omnibus_reduction']) || $repairDto['omnibus_reference_price'] === null) {
                continue;
            }

            $candidates[] = $this->buildSaleBeforeReferenceCandidate(
                $row,
                $currency,
                $referenceAt,
                $saleBeforeReferenceAt,
                $regularPrice,
                $currentPrice,
                $dto,
                $repairDto
            );
        }

        return $candidates;
    }

    private function fetchActiveDiscountedPromotionRows(
        string $storeHash,
        ?int $promotionId,
        ?int $productId
    ): array {
        $termsUpdatedAtSelect = $this->promotionsHasOmnibusTermsUpdatedAt()
            ? 'p.omnibus_terms_updated_at'
            : 'NULL';
        $itemReferenceAtSelect = $this->promotionProductsHasOmnibusReferenceAt()
            ? 'pp.omnibus_reference_at'
            : 'NULL';
        $conditions = [
            'pp.store_hash = ?',
            "p.status = 'active'",
            'p.start_date <= NOW()',
            'p.end_date >= NOW()',
            'pc.price IS NOT NULL',
            'pc.price > 0',
            'pc.sale_price IS NOT NULL',
            'pc.sale_price > 0',
            'pc.sale_price < pc.price',
        ];
        $params = [$storeHash];

        if ($promotionId !== null) {
            $conditions[] = 'pp.promotion_id = ?';
            $params[] = $promotionId;
        }

        if ($productId !== null) {
            $conditions[] = 'pp.product_id = ?';
            $params[] = $productId;
        }

        return $this->db->fetchAll(
            "SELECT pp.id AS promotion_product_id,
                    pp.promotion_id,
                    pp.product_id,
                    pp.variant_id,
                    pp.first_applied_at,
                    {$itemReferenceAtSelect} AS omnibus_reference_at,
                    pc.price,
                    pc.sale_price,
                    pc.cached_at,
                    p.start_date,
                    p.created_at,
                    {$termsUpdatedAtSelect} AS omnibus_terms_updated_at
             FROM promotion_products pp
             INNER JOIN promotions p
                ON p.store_hash COLLATE utf8mb4_unicode_ci = pp.store_hash COLLATE utf8mb4_unicode_ci
               AND p.id = pp.promotion_id
             INNER JOIN products_cache pc
                ON pc.store_hash COLLATE utf8mb4_unicode_ci = pp.store_hash COLLATE utf8mb4_unicode_ci
               AND pc.product_id = pp.product_id
               AND pc.variant_id <=> pp.variant_id
             WHERE " . implode(' AND ', $conditions) . "
             ORDER BY pp.promotion_id ASC, pp.product_id ASC, pp.variant_id ASC",
            $params
        );
    }

    private function buildMissingReferenceCandidate(
        array $row,
        string $currency,
        \DateTimeImmutable $referenceAt,
        float $regularPrice,
        float $currentPrice,
        array $dto
    ): array {
        return $this->baseCandidate($row, $currency, $referenceAt, $regularPrice, $currentPrice, $dto) + [
            'reason' => 'missing_reference_price',
            'seed_price' => $regularPrice,
            'seed_recorded_at' => $referenceAt->sub(new \DateInterval('P30D'))->format('Y-m-d H:i:s'),
            'new_reference_at' => null,
        ];
    }

    private function buildSaleBeforeReferenceCandidate(
        array $row,
        string $currency,
        \DateTimeImmutable $oldReferenceAt,
        \DateTimeImmutable $newReferenceAt,
        float $regularPrice,
        float $currentPrice,
        array $dto,
        array $repairDto
    ): array {
        return $this->baseCandidate($row, $currency, $oldReferenceAt, $regularPrice, $currentPrice, $dto) + [
            'reason' => 'sale_before_reference',
            'seed_price' => null,
            'seed_recorded_at' => null,
            'new_reference_at' => $newReferenceAt->format('Y-m-d H:i:s'),
            'repaired_omnibus_reference_price' => $repairDto['omnibus_reference_price'],
        ];
    }

    private function baseCandidate(
        array $row,
        string $currency,
        \DateTimeImmutable $referenceAt,
        float $regularPrice,
        float $currentPrice,
        array $dto
    ): array {
        return [
            'status' => 'candidate',
            'promotion_product_id' => (int)$row['promotion_product_id'],
            'promotion_id' => (int)$row['promotion_id'],
            'product_id' => (int)$row['product_id'],
            'variant_id' => $this->normalizeVariantId($row['variant_id'] ?? null),
            'currency' => $currency,
            'regular_price' => $regularPrice,
            'current_price' => $currentPrice,
            'old_reference_at' => $referenceAt->format('Y-m-d H:i:s'),
            'first_applied_at' => $row['first_applied_at'] ?? null,
            'cached_at' => $row['cached_at'] ?? null,
            'invalid_reduction_reason' => $dto['invalid_reduction_reason'] ?? null,
            'candidate_omnibus_reference_price' => $dto['candidate_omnibus_reference_price'] ?? null,
        ];
    }

    private function applyCandidate(string $storeHash, array $candidate): array {
        if (($candidate['reason'] ?? null) === 'missing_reference_price') {
            $inserted = $this->priceLogger->seedInitialPriceHistoryBatch($storeHash, [[
                'product_id' => (int)$candidate['product_id'],
                'variant_id' => $candidate['variant_id'],
                'price' => (float)$candidate['seed_price'],
                'currency' => (string)$candidate['currency'],
                'recorded_at' => (string)$candidate['seed_recorded_at'],
            ]]);

            $candidate['status'] = $inserted > 0 ? 'applied' : 'skipped';
            $candidate['applied_rows'] = $inserted;
            return $candidate;
        }

        if (($candidate['reason'] ?? null) === 'sale_before_reference') {
            $statement = $this->db->query(
                "UPDATE promotion_products
                 SET omnibus_reference_at = ?,
                     first_applied_at = CASE
                         WHEN first_applied_at IS NULL OR first_applied_at >= ? THEN ?
                         ELSE first_applied_at
                     END
                 WHERE store_hash = ?
                   AND id = ?
                   AND omnibus_reference_at <=> ?",
                [
                    $candidate['new_reference_at'],
                    $candidate['old_reference_at'],
                    $candidate['new_reference_at'],
                    $storeHash,
                    (int)$candidate['promotion_product_id'],
                    $candidate['old_reference_at'],
                ]
            );

            $affectedRows = is_object($statement) && method_exists($statement, 'rowCount')
                ? (int)$statement->rowCount()
                : 1;
            $candidate['status'] = $affectedRows > 0 ? 'applied' : 'skipped';
            $candidate['applied_rows'] = $affectedRows;
            return $candidate;
        }

        $candidate['status'] = 'skipped';
        $candidate['applied_rows'] = 0;
        return $candidate;
    }

    private function findSaleBeforeReferenceAt(
        string $storeHash,
        array $row,
        string $currency,
        float $currentPrice,
        \DateTimeImmutable $referenceAt
    ): ?\DateTimeImmutable {
        $thresholdAt = $referenceAt
            ->sub(new \DateInterval('PT' . self::SALE_BEFORE_REFERENCE_TOLERANCE_SECONDS . 'S'))
            ->format('Y-m-d H:i:s');
        $referenceSql = $referenceAt->format('Y-m-d H:i:s');
        $variantId = $this->normalizeVariantId($row['variant_id'] ?? null);

        $params = [
            $storeHash,
            (int)$row['product_id'],
        ];
        $variantSql = '';
        if ($this->priceHistoryHasVariantId()) {
            $variantSql = 'AND variant_id <=> ?';
            $params[] = $variantId;
        }

        $params = array_merge($params, [
            $currency,
            number_format($currentPrice, 4, '.', ''),
            $thresholdAt,
            $referenceSql,
        ]);

        $historyRow = $this->db->fetchOne(
            "SELECT MIN(recorded_at) AS first_recorded_at
             FROM product_price_history
             WHERE store_hash = ?
               AND product_id = ?
               {$variantSql}
               AND currency = ?
               AND ignored_at IS NULL
               AND ROUND(price, 4) = ROUND(?, 4)
               AND recorded_at >= ?
               AND recorded_at < ?",
            $params
        );

        if (empty($historyRow['first_recorded_at'])) {
            return null;
        }

        try {
            return new \DateTimeImmutable((string)$historyRow['first_recorded_at']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveActivePromotionReferenceAt(array $row): \DateTimeImmutable {
        $promotionReferenceAt = $this->resolvePromotionReferenceAt($row);
        $itemReferenceAt = $this->normalizeOptionalDateTime($row['omnibus_reference_at'] ?? null);

        if ($itemReferenceAt !== null && $itemReferenceAt > $promotionReferenceAt) {
            return $itemReferenceAt;
        }

        return $promotionReferenceAt;
    }

    private function resolvePromotionReferenceAt(array $row): \DateTimeImmutable {
        $dates = [];
        foreach (['start_date', 'created_at', 'omnibus_terms_updated_at'] as $field) {
            $date = $this->normalizeOptionalDateTime($row[$field] ?? null);
            if ($date !== null) {
                $dates[] = $date;
            }
        }

        $latest = null;
        foreach ($dates as $date) {
            if ($latest === null || $date > $latest) {
                $latest = $date;
            }
        }

        return $latest ?? new \DateTimeImmutable('now');
    }

    private function fetchStoreCurrency(string $storeHash): string {
        $row = $this->db->fetchOne(
            "SELECT currency FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );

        return (string)($row['currency'] ?? 'USD');
    }

    private function extractAppliedProductIds(array $results): array {
        $productIds = [];
        foreach ($results as $result) {
            if (($result['status'] ?? null) !== 'applied') {
                continue;
            }

            $productId = (int)($result['product_id'] ?? 0);
            if ($productId > 0) {
                $productIds[$productId] = $productId;
            }
        }

        $productIds = array_values($productIds);
        sort($productIds, SORT_NUMERIC);
        return $productIds;
    }

    private function countByReason(array $candidates): array {
        $counts = [];
        foreach ($candidates as $candidate) {
            $reason = (string)($candidate['reason'] ?? 'unknown');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        ksort($counts);
        return $counts;
    }

    private function normalizePrice($price): ?float {
        if ($price === null || $price === '' || !is_numeric($price)) {
            return null;
        }

        return (float)$price;
    }

    private function normalizeVariantId($variantId): ?int {
        if ($variantId === null || $variantId === '') {
            return null;
        }

        return (int)$variantId;
    }

    private function normalizeOptionalDateTime($dateTime): ?\DateTimeImmutable {
        if ($dateTime === null || $dateTime === '') {
            return null;
        }

        if ($dateTime instanceof \DateTimeImmutable) {
            return $dateTime;
        }

        if ($dateTime instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($dateTime);
        }

        try {
            return new \DateTimeImmutable((string)$dateTime);
        } catch (\Throwable $e) {
            return null;
        }
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

    private function promotionProductsHasOmnibusReferenceAt(): bool {
        if ($this->promotionProductsHasOmnibusReferenceAt !== null) {
            return $this->promotionProductsHasOmnibusReferenceAt;
        }

        try {
            $column = $this->db->fetchOne("SHOW COLUMNS FROM promotion_products LIKE 'omnibus_reference_at'");
            $this->promotionProductsHasOmnibusReferenceAt = $column !== false && $column !== null;
        } catch (\Throwable $e) {
            $this->promotionProductsHasOmnibusReferenceAt = false;
        }

        return $this->promotionProductsHasOmnibusReferenceAt;
    }

    private function promotionsHasOmnibusTermsUpdatedAt(): bool {
        if ($this->promotionsHasOmnibusTermsUpdatedAt !== null) {
            return $this->promotionsHasOmnibusTermsUpdatedAt;
        }

        try {
            $column = $this->db->fetchOne("SHOW COLUMNS FROM promotions LIKE 'omnibus_terms_updated_at'");
            $this->promotionsHasOmnibusTermsUpdatedAt = $column !== false && $column !== null;
        } catch (\Throwable $e) {
            $this->promotionsHasOmnibusTermsUpdatedAt = false;
        }

        return $this->promotionsHasOmnibusTermsUpdatedAt;
    }
}
