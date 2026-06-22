<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Database;

class PromotionApplicationCorrectionService {
    private const OPERATION_VOID = 'void_promotion_application';
    private const HISTORY_MATCH_TOLERANCE_SECONDS = 300;
    private const MAX_REASON_LENGTH = 1000;

    private $db;
    private $promotionService;

    public function __construct(Database $db = null, PromotionService $promotionService = null) {
        $this->db = $db ?? Database::getInstance();
        $this->ensureSchema();
        $this->promotionService = $promotionService;
    }

    public function previewBySku(
        string $storeHash,
        string $sku,
        ?int $promotionId = null,
        ?int $productId = null,
        ?int $variantId = null
    ): array {
        $storeHash = $this->requireStoreHash($storeHash);
        $sku = $this->normalizeSku($sku);
        if ($sku === '') {
            return ['status' => 'invalid_sku', 'message' => 'Enter a SKU.'];
        }

        $this->db->setStoreContext($storeHash);
        $matches = $this->findSkuMatches($storeHash, $sku, $productId, $variantId);

        if (empty($matches)) {
            return [
                'status' => 'not_found',
                'message' => 'No cached product or variant was found for this SKU.',
                'matches' => [],
            ];
        }

        if (count($matches) > 1 && ($productId === null || $variantId === null && !$this->hasSingleParentMatch($matches))) {
            return [
                'status' => 'ambiguous',
                'message' => 'This SKU matches more than one cached product or variant.',
                'matches' => $matches,
            ];
        }

        $match = $matches[0];
        $activeRow = $this->fetchActivePromotionRow(
            $storeHash,
            (int)$match['product_id'],
            $this->normalizeVariantId($match['variant_id'] ?? null),
            $promotionId
        );

        if (!$activeRow) {
            return [
                'status' => 'no_active_promotion',
                'message' => 'This SKU is not currently tracked as applied to an active promotion.',
                'product' => $match,
                'matches' => $matches,
            ];
        }

        $historyRows = $this->fetchAffectedHistoryRows($storeHash, $activeRow);
        $promotionService = $this->getPromotionService();
        $reconcilePreview = $promotionService->previewVoidPromotionProductAndReconcile(
            (int)$activeRow['product_id'],
            $this->normalizeVariantId($activeRow['variant_id'] ?? null),
            (int)$activeRow['promotion_id']
        );

        return [
            'status' => 'ready',
            'message' => 'Correction preview is ready.',
            'product' => $match,
            'active_promotion' => $activeRow,
            'history_rows' => $historyRows,
            'history_row_ids' => array_map('intval', array_column($historyRows, 'id')),
            'reconcile_preview' => $reconcilePreview,
            'matches' => $matches,
        ];
    }

    public function applyVoidCorrection(
        string $storeHash,
        int $productId,
        ?int $variantId,
        int $promotionId,
        string $reason,
        array $actorContext,
        bool $visibilityConfirmed,
        string $previewToken = ''
    ): array {
        $storeHash = $this->requireStoreHash($storeHash);
        $reason = trim($reason);

        if ($previewToken === '') {
            throw new \InvalidArgumentException('Correction preview token is required.');
        }

        if ($reason === '') {
            throw new \InvalidArgumentException('Correction reason is required.');
        }

        if (strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new \InvalidArgumentException('Correction reason may contain at most ' . self::MAX_REASON_LENGTH . ' characters.');
        }

        if (!$visibilityConfirmed) {
            throw new \InvalidArgumentException('Confirm the Omnibus responsibility statement before applying the correction.');
        }

        $this->db->setStoreContext($storeHash);
        $activeRow = $this->fetchActivePromotionRow($storeHash, $productId, $variantId, $promotionId);
        if (!$activeRow) {
            throw new \InvalidArgumentException('Active promotion product row was not found.');
        }

        if ($this->hasAppliedCorrection($storeHash, $productId, $variantId, $promotionId)) {
            throw new \InvalidArgumentException('This promotion application has already been corrected.');
        }

        $historyRows = $this->fetchAffectedHistoryRows($storeHash, $activeRow);
        $historyRowIds = array_map('intval', array_column($historyRows, 'id'));
        $correctionId = $this->insertPendingCorrection(
            $storeHash,
            $activeRow,
            $historyRowIds,
            $reason,
            $actorContext,
            $visibilityConfirmed
        );

        try {
            $promotionResult = $this->getPromotionService()->voidPromotionProductAndReconcile(
                $productId,
                $variantId,
                $promotionId,
                $correctionId,
                $reason
            );

            $ignoredCount = $this->markHistoryRowsIgnored($storeHash, $historyRowIds, $correctionId, $reason);
            $postCorrectionHistoryLogged = $this->logPostCorrectionPrice($storeHash, $activeRow, $promotionResult);
            $queueResult = $this->queueTargetedOmnibusSync($storeHash, [$productId], $promotionId, $correctionId);
            $afterState = [
                'promotion_result' => $promotionResult,
                'ignored_history_rows' => $ignoredCount,
                'post_correction_history_logged' => $postCorrectionHistoryLogged,
                'omnibus_queue' => $queueResult,
            ];

            $this->markCorrectionApplied(
                $correctionId,
                $storeHash,
                $afterState,
                $promotionResult['replacement_promotion_id'] ?? null
            );

            return [
                'status' => 'applied',
                'correction_id' => $correctionId,
                'promotion_result' => $promotionResult,
                'ignored_history_rows' => $ignoredCount,
                'post_correction_history_logged' => $postCorrectionHistoryLogged,
                'omnibus_queue' => $queueResult,
            ];
        } catch (\Throwable $e) {
            $this->markCorrectionFailed($correctionId, $storeHash, $e->getMessage());
            throw $e;
        }
    }

    public function getRecentCorrections(string $storeHash, int $limit = 100): array {
        $storeHash = $this->requireStoreHash($storeHash);
        $limit = max(1, min(200, $limit));

        return $this->db->fetchAll(
            "SELECT pac.*, p.name AS promotion_name
             FROM promotion_application_corrections pac
             LEFT JOIN promotions p
                ON p.store_hash COLLATE utf8mb4_unicode_ci = pac.store_hash COLLATE utf8mb4_unicode_ci
               AND p.id = pac.promotion_id
             WHERE pac.store_hash = ?
             ORDER BY pac.created_at DESC, pac.id DESC
             LIMIT {$limit}",
            [$storeHash]
        );
    }

    private function findSkuMatches(string $storeHash, string $sku, ?int $productId, ?int $variantId): array {
        $conditions = [
            'store_hash = ?',
            'sku = ?',
        ];
        $params = [$storeHash, $sku];

        if ($productId !== null && $productId > 0) {
            $conditions[] = 'product_id = ?';
            $params[] = $productId;
        }

        if ($variantId !== null) {
            $conditions[] = 'variant_id <=> ?';
            $params[] = $variantId;
        }

        return $this->db->fetchAll(
            "SELECT id, product_id, variant_id, type, name, sku, price, sale_price, cached_at
             FROM products_cache
             WHERE " . implode(' AND ', $conditions) . "
             ORDER BY product_id ASC, variant_id IS NULL DESC, variant_id ASC",
            $params
        );
    }

    private function fetchActivePromotionRow(
        string $storeHash,
        int $productId,
        ?int $variantId,
        ?int $promotionId
    ): ?array {
        $conditions = [
            'pp.store_hash = ?',
            'pp.product_id = ?',
            'pp.variant_id <=> ?',
            "p.status = 'active'",
            'p.start_date <= NOW()',
            'p.end_date >= NOW()',
        ];
        $params = [$storeHash, $productId, $variantId];

        if ($promotionId !== null && $promotionId > 0) {
            $conditions[] = 'pp.promotion_id = ?';
            $params[] = $promotionId;
        }

        return $this->db->fetchOne(
            "SELECT pp.id AS promotion_product_id,
                    pp.promotion_id,
                    pp.product_id,
                    pp.variant_id,
                    pp.custom_field_id,
                    pp.first_applied_at,
                    pp.omnibus_reference_at,
                    pp.synced_at,
                    p.name AS promotion_name,
                    p.discount_percent,
                    pc.name AS product_name,
                    pc.sku,
                    pc.type,
                    pc.price,
                    pc.sale_price AS promo_price,
                    pc.cached_at
             FROM promotion_products pp
             INNER JOIN promotions p
                ON p.store_hash COLLATE utf8mb4_unicode_ci = pp.store_hash COLLATE utf8mb4_unicode_ci
               AND p.id = pp.promotion_id
             LEFT JOIN products_cache pc
                ON pc.store_hash COLLATE utf8mb4_unicode_ci = pp.store_hash COLLATE utf8mb4_unicode_ci
               AND pc.product_id = pp.product_id
               AND pc.variant_id <=> pp.variant_id
             WHERE " . implode(' AND ', $conditions) . "
             ORDER BY pp.synced_at DESC, pp.id DESC
             LIMIT 1",
            $params
        ) ?: null;
    }

    private function fetchAffectedHistoryRows(string $storeHash, array $activeRow): array {
        $promoPrice = $this->normalizePrice($activeRow['promo_price'] ?? null);
        if ($promoPrice === null || $promoPrice <= 0) {
            return [];
        }

        $currency = $this->fetchStoreCurrency($storeHash);
        $referenceAt = $this->resolveHistoryStartAt($activeRow);
        $thresholdAt = $referenceAt
            ->sub(new \DateInterval('PT' . self::HISTORY_MATCH_TOLERANCE_SECONDS . 'S'))
            ->format('Y-m-d H:i:s');

        return $this->db->fetchAll(
            "SELECT id, product_id, variant_id, price, currency, recorded_at
             FROM product_price_history
             WHERE store_hash = ?
               AND product_id = ?
               AND variant_id <=> ?
               AND currency = ?
               AND ignored_at IS NULL
               AND ROUND(price, 4) = ROUND(?, 4)
               AND recorded_at >= ?
               AND recorded_at <= NOW()
             ORDER BY recorded_at ASC, id ASC",
            [
                $storeHash,
                (int)$activeRow['product_id'],
                $this->normalizeVariantId($activeRow['variant_id'] ?? null),
                $currency,
                $promoPrice,
                $thresholdAt,
            ]
        );
    }

    private function insertPendingCorrection(
        string $storeHash,
        array $activeRow,
        array $historyRowIds,
        string $reason,
        array $actorContext,
        bool $visibilityConfirmed
    ): int {
        $this->db->query(
            "INSERT INTO promotion_application_corrections
                (store_hash, promotion_id, product_id, variant_id, sku_snapshot, operation, status, reason,
                 visibility_confirmed, actor_source, actor_user_id, actor_email, actor_is_owner,
                 before_state, ignored_history_row_ids, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $storeHash,
                (int)$activeRow['promotion_id'],
                (int)$activeRow['product_id'],
                $this->normalizeVariantId($activeRow['variant_id'] ?? null),
                $activeRow['sku'] ?? null,
                self::OPERATION_VOID,
                $reason,
                $visibilityConfirmed ? 1 : 0,
                $this->normalizeActorSource($actorContext['actor_source'] ?? null),
                $this->normalizeNullableString($actorContext['actor_user_id'] ?? null),
                $this->normalizeNullableString($actorContext['actor_email'] ?? null),
                !empty($actorContext['actor_is_owner']) ? 1 : 0,
                json_encode($activeRow, JSON_UNESCAPED_SLASHES),
                json_encode(array_values($historyRowIds), JSON_UNESCAPED_SLASHES),
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    private function markHistoryRowsIgnored(string $storeHash, array $historyRowIds, int $correctionId, string $reason): int {
        $historyRowIds = array_values(array_filter(array_map('intval', $historyRowIds), static function (int $id): bool {
            return $id > 0;
        }));

        if (empty($historyRowIds)) {
            return 0;
        }

        $placeholders = str_repeat('?,', count($historyRowIds) - 1) . '?';
        $stmt = $this->db->query(
            "UPDATE product_price_history
             SET ignored_at = NOW(),
                 ignored_reason = ?,
                 ignored_by_correction_id = ?
             WHERE store_hash = ?
               AND ignored_at IS NULL
               AND id IN ($placeholders)",
            array_merge([$reason, $correctionId, $storeHash], $historyRowIds)
        );

        return is_object($stmt) && method_exists($stmt, 'rowCount') ? (int)$stmt->rowCount() : count($historyRowIds);
    }

    private function markCorrectionApplied(
        int $correctionId,
        string $storeHash,
        array $afterState,
        ?int $replacementPromotionId
    ): void {
        $this->db->query(
            "UPDATE promotion_application_corrections
             SET status = 'applied',
                 after_state = ?,
                 replacement_promotion_id = ?,
                 applied_at = NOW()
             WHERE store_hash = ? AND id = ?",
            [
                json_encode($afterState, JSON_UNESCAPED_SLASHES),
                $replacementPromotionId,
                $storeHash,
                $correctionId,
            ]
        );
    }

    private function markCorrectionFailed(int $correctionId, string $storeHash, string $errorMessage): void {
        $this->db->query(
            "UPDATE promotion_application_corrections
             SET status = 'failed',
                 error_message = ?
             WHERE store_hash = ? AND id = ?",
            [$errorMessage, $storeHash, $correctionId]
        );
    }

    private function queueTargetedOmnibusSync(string $storeHash, array $productIds, int $promotionId, int $correctionId): ?array {
        $storeConfig = $this->db->fetchOne(
            "SELECT enable_omnibus FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );

        if (empty($storeConfig['enable_omnibus'])) {
            return null;
        }

        try {
            return (new QueueService($storeHash))->createTargetedOmnibusSyncJob($productIds, [
                'source' => 'promotion_application_correction',
                'promotion_id' => $promotionId,
                'source_job_id' => $correctionId,
            ]);
        } catch (\Throwable $e) {
            return [
                'created' => false,
                'job_id' => null,
                'reason' => 'queue_error',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function logPostCorrectionPrice(string $storeHash, array $activeRow, array $promotionResult): bool {
        if (!$this->db instanceof Database) {
            return false;
        }

        $currency = $this->fetchStoreCurrency($storeHash);
        $variantId = $this->normalizeVariantId($activeRow['variant_id'] ?? null);
        $price = null;

        if (($promotionResult['status'] ?? null) === 'replaced') {
            $price = $this->normalizePrice($promotionResult['replacement']['promo_price'] ?? null);
        } else {
            $price = $this->normalizePrice($activeRow['price'] ?? null);
        }

        if ($price === null || $price <= 0) {
            return false;
        }

        try {
            return (new PriceLogger($this->db))->logPriceChange(
                $storeHash,
                (int)$activeRow['product_id'],
                $price,
                $currency,
                $variantId,
                new \DateTimeImmutable('now')
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasAppliedCorrection(string $storeHash, int $productId, ?int $variantId, int $promotionId): bool {
        $row = $this->db->fetchOne(
            "SELECT id
             FROM promotion_application_corrections
             WHERE store_hash = ?
               AND product_id = ?
               AND variant_id <=> ?
               AND promotion_id = ?
               AND status = 'applied'
             LIMIT 1",
            [$storeHash, $productId, $variantId, $promotionId]
        );

        return !empty($row);
    }

    private function getPromotionService(): PromotionService {
        if ($this->promotionService === null) {
            $this->promotionService = new PromotionService($this->db);
        }

        return $this->promotionService;
    }

    private function ensureSchema(): void {
        PriceHistorySchemaService::ensureIgnoredColumns($this->db);

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS promotion_application_corrections (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_hash VARCHAR(255) NOT NULL,
                promotion_id INT NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                variant_id INT UNSIGNED NULL,
                sku_snapshot VARCHAR(255) NULL,
                operation VARCHAR(50) NOT NULL DEFAULT 'void_promotion_application',
                status VARCHAR(50) NOT NULL DEFAULT 'pending',
                reason TEXT NOT NULL,
                visibility_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                actor_source VARCHAR(50) NOT NULL,
                actor_user_id VARCHAR(255) NULL,
                actor_email VARCHAR(255) NULL,
                actor_is_owner TINYINT(1) NOT NULL DEFAULT 0,
                before_state LONGTEXT NULL,
                after_state LONGTEXT NULL,
                ignored_history_row_ids LONGTEXT NULL,
                replacement_promotion_id INT NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL,
                applied_at DATETIME NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_store_created (store_hash, created_at),
                INDEX idx_store_product_variant (store_hash, product_id, variant_id),
                INDEX idx_store_promotion (store_hash, promotion_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS promotion_product_exclusions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_hash VARCHAR(255) NOT NULL,
                promotion_id INT NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                variant_id INT UNSIGNED NULL,
                correction_id BIGINT UNSIGNED NULL,
                reason TEXT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uniq_store_promotion_product_variant (store_hash, promotion_id, product_id, variant_id),
                INDEX idx_store_product_variant (store_hash, product_id, variant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function resolveHistoryStartAt(array $activeRow): \DateTimeImmutable {
        foreach (['first_applied_at', 'omnibus_reference_at', 'synced_at', 'cached_at'] as $field) {
            if (empty($activeRow[$field])) {
                continue;
            }

            try {
                return new \DateTimeImmutable((string)$activeRow[$field]);
            } catch (\Throwable $e) {
            }
        }

        return new \DateTimeImmutable('now');
    }

    private function fetchStoreCurrency(string $storeHash): string {
        $row = $this->db->fetchOne(
            "SELECT currency FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );

        return (string)($row['currency'] ?? 'USD');
    }

    private function requireStoreHash(string $storeHash): string {
        $storeHash = trim($storeHash);
        if ($storeHash === '') {
            throw new \InvalidArgumentException('Store context is required.');
        }

        return $storeHash;
    }

    private function normalizeSku(string $sku): string {
        return trim($sku);
    }

    private function normalizeVariantId($variantId): ?int {
        if ($variantId === null || $variantId === '') {
            return null;
        }

        return (int)$variantId;
    }

    private function normalizePrice($price): ?float {
        if ($price === null || $price === '' || !is_numeric($price)) {
            return null;
        }

        return (float)$price;
    }

    private function normalizeActorSource($actorSource): string {
        $actorSource = trim((string)$actorSource);

        return $actorSource !== '' ? substr($actorSource, 0, 50) : 'unknown';
    }

    private function normalizeNullableString($value): ?string {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    private function hasSingleParentMatch(array $matches): bool {
        if (count($matches) !== 1) {
            return false;
        }

        return $this->normalizeVariantId($matches[0]['variant_id'] ?? null) === null;
    }
}
