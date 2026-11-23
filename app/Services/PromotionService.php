<?php
namespace App\Services;

use App\Models\Promotion;
use App\Models\Database;
use App\Services\QueueService;

class PromotionService {
    private ?PriceOwnershipService $ownership = null;
    private ?array $termsOwners = null;
    private $promotionModel;
    private $api;
    private $customFieldService;
    private $db;
    private $cacheService;
    private $storeHash;
    private $verificationQueue;
    private $omnibusPricingService;
    private $archiveService = null;
    private $storeConfigCache = null;
    private $priceHistoryHasVariantId;
    private $promotionProductExclusionMap = null;
    private const API_PRODUCT_LIMIT = 250;
    private const BLOCK_BELOW_COST_PRICE_FILTER_KEY = '_block_below_cost_price';
    private const MANUAL_UNBLOCK_FILTER_KEY = '_manual_unblocked_items';
    private const COST_PRICE_BLOCK_ENABLED_ITEM_FLAG = '_cost_price_block_enabled';
    private const MANUAL_UNBLOCK_ITEM_FLAG = '_manual_cost_price_unblock';
    private const CHANGE_TYPE_STANDARD = 'standard';
    private const CHANGE_TYPE_ACTIVE_DISCOUNT_CORRECTION = 'active_discount_correction';
    private const MAX_CORRECTION_REASON_LENGTH = 1000;
    private const PREVIEW_PAGE_LIMIT = 100;
    
    public function __construct(Database $db = null, ?BigCommerceAPI $api = null) {
        $this->promotionModel = new Promotion();
        $this->api = $api ?? new BigCommerceAPI();
        $this->db = $db ?? Database::getInstance();
        $this->ensurePromotionProductExclusionSchema();
        $this->customFieldService = new CustomFieldService($this->api, $this->db);
        
        $this->storeHash = $this->db->getStoreContext();
        
        if (!$this->storeHash) {
            throw new \Exception("Store context required");
        }
        
        // ISPRAVKA: Prosleđujemo DB instancu, a ne storeHash, da bi se delila konekcija.
        $this->ownership = new PriceOwnershipService($this->db, $this->api, $this->storeHash);
        $this->cacheService = new ProductCacheService($this->db, $this->api);
        $this->omnibusPricingService = new OmnibusPricingService($this->db);
        $this->archiveService = new PromotionArchiveService($this->db, $this->storeHash);
    }

    public function withProductLock(int $id, callable $work) {
        return $this->ownership ? $this->ownership->locked([['product_id' => $id]], $work) : $work();
    }

    public function decidePriceConflict(int $id, int $revision, string $decision, string $actor): bool {
        if (!in_array($decision, ['apply','keep'], true) || $actor === '') {
            throw new \InvalidArgumentException('A decision and operator identity are required.');
        }
        $row = $this->db->fetchOne('SELECT * FROM price_conflicts WHERE store_hash=? AND id=?', [$this->storeHash,$id]);
        if (!$row) {
            return false;
        }
        return $this->ownership->locked([$row], function () use ($row,$id,$revision,$decision,$actor) {
            $this->db->beginTransaction();
            try {
                $changed = $this->db->query("UPDATE price_conflicts SET decision=?, actor=?, decided_at=NOW(),
                    revision=revision+1 WHERE store_hash=? AND id=? AND revision=? AND decision='open' AND (?='keep' OR allow_apply=1)",
                    [$decision,$actor,$this->storeHash,$id,$revision,$decision])->rowCount();
                if ($changed) {
                    $this->db->query('INSERT INTO price_conflict_decisions(store_hash,conflict_id,revision,expected_price,observed_price,decision,actor,decided_at)
                        VALUES (?,?,?,?,?,?,?,NOW())',[$this->storeHash,$id,$revision,$row['expected_price'],$row['observed_price'],$decision,$actor]);
                }
                if ($changed && $decision === 'keep') {
                    $pid = (int)$row['product_id'];
                    $vid = ((int)$row['variant_key']) ?: null;
                    $promotion = (int)$row['promotion_id'];
                    $owners = $this->fetchPromotionProductsWithCachePrice(
                        'pp.store_hash=? AND pp.product_id=? AND pp.variant_id <=> ? AND pp.promotion_id=?',
                        [$this->storeHash,$pid,$vid,$promotion]);
                    $this->insertPromotionProductExclusion($promotion,$pid,$vid,null,'operator_kept_price');
                    if ($owners) {
                        $this->recordRemovedPromotionItems($promotion,$owners,'operator_kept_price');
                        $this->db->query('DELETE FROM promotion_products WHERE store_hash=? AND product_id=?
                            AND variant_id <=> ? AND promotion_id=?', [$this->storeHash,$pid,$vid,$promotion]);
                        $this->db->query("INSERT INTO sync_jobs(store_hash,job_type,payload,total_items,status,created_at)
                            VALUES (?,'cleanup_promotion_field',?,1,'pending',NOW())",
                            [$this->storeHash,json_encode(['product_ids'=>[$pid]])]);
                    }
                    $this->db->query('DELETE FROM price_write_intents WHERE store_hash=? AND product_id=?
                        AND variant_key=? AND promotion_id=?', [$this->storeHash,$pid,$vid ?: 0,$promotion]);
                }
                if ($changed) {
                    $pending = $this->db->fetchAll("SELECT id,after_state FROM promotion_application_corrections WHERE store_hash=?
                        AND product_id=? AND variant_id <=> ? AND status='pending'",
                        [$this->storeHash,$row['product_id'],((int)$row['variant_key']) ?: null]);
                    foreach ($pending as $correction) {
                        if ($decision === 'keep' && empty($correction['after_state'])) {
                            $this->db->query("UPDATE promotion_application_corrections SET status='failed',error_message='Operator kept current price'
                                WHERE store_hash=? AND id=?",[$this->storeHash,$correction['id']]);
                        } else {
                            $this->db->query("INSERT INTO sync_jobs(store_hash,job_type,payload,status,total_items,created_at)
                                VALUES (?,'resume_correction',?,'pending',1,NOW())",
                                [$this->storeHash,json_encode(['correction_id'=>(int)$correction['id']])]);
                        }
                    }
                }
                $this->db->commit();
                return (bool)$changed;
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
        });
    }

    public function previewVoidPromotionProductAndReconcile(int $productId, ?int $variantId, int $promotionId): array {
        $item = $this->fetchPromotionProductForCorrection($productId, $variantId, $promotionId);
        if (!$item) {
            return [
                'status' => 'not_found',
                'message' => 'Active promotion product row was not found.',
                'replacement_promotion' => null,
            ];
        }

        $replacement = $this->findReplacementPromotionCandidate($item, $promotionId);

        return [
            'status' => 'preview',
            'current_promotion_id' => $promotionId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'replacement_promotion' => $replacement,
            'will_restore_price' => $replacement === null,
        ];
    }

    public function voidPromotionProductAndReconcile(
        int $productId,
        ?int $variantId,
        int $promotionId,
        ?int $correctionId = null,
        string $reason = 'voided_error'
    ): array {
        $item = $this->fetchPromotionProductForCorrection($productId, $variantId, $promotionId);
        if (!$item) {
            throw new \InvalidArgumentException('Active promotion product row was not found.');
        }

        $replacement = $this->findReplacementPromotionCandidate($item, $promotionId);

        return $this->correctOwnedItem($item, $replacement, $correctionId, $reason);
    }
    
    /**
     * Kreira novu promociju i zakazuje sync samo ako promocija pocinje odmah.
     */
    public function createPromotion(array $data) {
        $data['discount_percent'] = $this->validateDiscountPercent($data['discount_percent'] ?? null);
        $status = $this->determinePromotionStatus($data['start_date'] ?? null, $data['end_date'] ?? null);

        // 1. Unos promocije u bazu
        $sql = "INSERT INTO promotions (store_hash, name, custom_field_value, discount_percent, start_date, end_date, priority, filters, status, color, description, created_at, omnibus_terms_updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $filtersJson = is_array($data['filters']) ? json_encode($data['filters']) : $data['filters'];
        
        $this->db->query($sql, [
            $this->storeHash,
            $data['name'],
            $data['custom_field_value'] ?? $data['name'],
            $data['discount_percent'],
            $data['start_date'],
            $data['end_date'],
            $data['priority'] ?? 0,
            $filtersJson,
            $status,
            $data['color'] ?? '#3b82f6',
            $data['description'] ?? ''
        ]);
        
        $promotionId = $this->db->lastInsertId();
        
        if ($status === 'active') {
            $this->queueActivationJobsForPromotion($promotionId, $data['filters'] ?? []);
        }
        
        return $promotionId;
    }

    public function updatePromotion($promotionId, array $data, array $context = []) {
        $existingPromotion = $this->promotionModel->findById($promotionId);
        if (!$existingPromotion) {
            throw new \InvalidArgumentException("Promotion not found.");
        }

        $data['discount_percent'] = $this->validateDiscountPercent($data['discount_percent'] ?? null);
        $status = $this->determinePromotionStatus($data['start_date'] ?? null, $data['end_date'] ?? null);
        $data['status'] = $status;
        $syncRelevantChanges = $this->hasPromotionSyncRelevantChanges($existingPromotion, $data);
        $omnibusTermsChanged = $this->hasPromotionOmnibusTermsChanged($existingPromotion, $data);
        $correctionRevision = $this->validateActiveDiscountCorrectionRequest(
            $existingPromotion,
            $data,
            $context
        );
        if ($omnibusTermsChanged && $correctionRevision === null) {
            $data['omnibus_terms_updated_at'] = date('Y-m-d H:i:s');
        }

        $result = $correctionRevision === null
            ? $this->promotionModel->update($promotionId, $data)
            : $this->savePromotionUpdateWithCorrectionRevision($promotionId, $data, $correctionRevision);

        if ($status === 'active' && $syncRelevantChanges) {
            $this->queueActivationJobsForPromotion($promotionId, $data['filters'] ?? []);
        } else {
            $appliedProducts = $this->countPromotionProducts($promotionId);
            if ($this->shouldQueueCleanupAfterPromotionUpdate($status, $appliedProducts)) {
                $this->queuePromotionCleanup($promotionId, $appliedProducts);
            } elseif ($status === 'expired') {
                $this->finalizePromotionArchive((int)$promotionId, 'promotion_update');
            }
        }

        return $result;
    }

    public function deletePromotion($promotionId) {
        $promotion = $this->promotionModel->findById($promotionId);
        if (!$promotion) {
            return false;
        }

        $totalItems = $this->countPromotionProducts($promotionId);

        if ($totalItems > 0) {
            $this->finalizePromotionArchive((int)$promotionId, 'manual_delete');
            $this->queuePromotionCleanup($promotionId, $totalItems);
            return $this->promotionModel->update($promotionId, ['status' => 'expired']);
        }

        if ($this->shouldArchivePromotionBeforeDelete($promotion, $totalItems)) {
            $this->finalizePromotionArchive((int)$promotionId, 'manual_delete');
        }

        return $this->promotionModel->delete($promotionId);
    }

    public function queuePromotionCleanup($promotionId, ?int $totalItems = null, bool $queueOmnibus = true): array {
        $totalItems = $totalItems ?? $this->countPromotionProducts($promotionId);
        if ($totalItems <= 0) {
            return ['created' => false, 'job_id' => null, 'total' => 0];
        }

        $existingJob = $this->findOpenJob('cleanup_single', $promotionId);
        if ($existingJob) {
            return [
                'created' => false,
                'job_id' => (int)$existingJob['id'],
                'total' => (int)$existingJob['total_items'],
            ];
        }

        $queue = new QueueService($this->storeHash);
        $jobId = $queue->createJob('cleanup_single', $promotionId, $totalItems > 0 ? $totalItems : 1);

        return ['created' => true, 'job_id' => (int)$jobId, 'total' => $totalItems];
    }

    private function queueActivationJobsForPromotion($promotionId, $filters): void {
        $queue = new QueueService($this->storeHash);

        $this->queueJobForPromotion($queue, $promotionId, $filters);
    }

    private function queueJobForPromotion(QueueService $queue, $promotionId, $filters): void {
        $filtersArray = is_array($filters) ? $filters : json_decode($filters, true);
        if (!is_array($filtersArray)) {
            $filtersArray = [];
        }

        $totalItems = $this->cacheService->countProductsByFilters($filtersArray);

        // Kreiramo posao odmah
        $queue->createJobIfNotOpen('sync_promotion', $promotionId, $totalItems > 0 ? $totalItems : 1);
    }

    private function queueOmnibusSyncJobIfEnabled(QueueService $queue): void {
        $storeConfig = $this->db->fetchOne(
            "SELECT enable_omnibus FROM bigcommerce_stores WHERE store_hash = ?",
            [$this->storeHash]
        );

        if (!$storeConfig || empty($storeConfig['enable_omnibus'])) {
            return;
        }

        $queue->createOmnibusSyncJob($this->countOmnibusParentProducts());
    }

    private function countOmnibusParentProducts(): int {
        $typeColumn = $this->db->fetchOne("SHOW COLUMNS FROM products_cache LIKE 'type'");
        $baseProductClause = $typeColumn ? " AND type = 'product'" : '';
        $row = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT product_id) AS total FROM products_cache WHERE store_hash = ?" . $baseProductClause,
            [$this->storeHash]
        );

        return (int)($row['total'] ?? 0);
    }

    private function countPromotionProducts($promotionId): int {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM promotion_products WHERE store_hash = ? AND promotion_id = ?",
            [$this->storeHash, $promotionId]
        );

        return (int)($row['cnt'] ?? 0);
    }

    private function countAllPromotionProducts(): int {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM promotion_products WHERE store_hash = ?",
            [$this->storeHash]
        );

        return (int)($row['cnt'] ?? 0);
    }

    private function findOpenJob(string $jobType, $promotionId = null) {
        return $this->db->fetchOne(
            "SELECT *
             FROM sync_jobs
             WHERE store_hash = ?
               AND job_type = ?
               AND promotion_id <=> ?
               AND status IN ('pending', 'processing')
             ORDER BY created_at ASC, id ASC
             LIMIT 1",
            [$this->storeHash, $jobType, $promotionId]
        );
    }

    private function findOpenJobByType(string $jobType) {
        return $this->db->fetchOne(
            "SELECT *
             FROM sync_jobs
             WHERE store_hash = ?
               AND job_type = ?
               AND status IN ('pending', 'processing')
             ORDER BY created_at ASC, id ASC
             LIMIT 1",
            [$this->storeHash, $jobType]
        );
    }

    private function determinePromotionStatus($startDate, $endDate): string {
        $now = time();
        $startTimestamp = strtotime((string)$startDate);
        $endTimestamp = strtotime((string)$endDate);

        if ($endTimestamp !== false && $endTimestamp < $now) {
            return 'expired';
        }

        if ($startTimestamp !== false && $startTimestamp > $now) {
            return 'scheduled';
        }

        return 'active';
    }

    private function shouldQueueCleanupAfterPromotionUpdate(string $status, int $appliedProducts): bool {
        return $status !== 'active' && $appliedProducts > 0;
    }

    /**
     * Umesto da sinhronizuje sve odjednom, ova metoda kreira Job-ove
     * za svaku aktivnu promociju pojedinačno.
     */
    public function queueAllPromotions() {
        // 1. Održavanje: Ažuriraj statuse i obriši istekle
        $queue = new QueueService($this->storeHash);
        $this->promotionModel->updateStatuses();
        
        $promotions = $this->promotionModel->findActive();
        $expiredCleanupJobs = 0;
        $jobsCreated = 0;
        $jobsSkipped = 0;

        if (empty($promotions)) {
            // Ako nema promocija, kreiraj posao za čišćenje svega
            $totalItems = $this->countAllPromotionProducts();
            if ($totalItems > 0 && !$this->findOpenJobByType('cleanup_single') && !$this->findOpenJob('cleanup')) {
                $queue->createJobIfNotOpen('cleanup', null, $totalItems);
                $jobsCreated++;
            }
            return ['message' => 'Nema aktivnih promocija. Zakazan posao čišćenja.', 'jobs' => $jobsCreated];
        }

        $expiredCleanupJobs = $this->queueExpiredPromotionCleanupJobs($queue);
        $jobsCreated += $expiredCleanupJobs;

        foreach ($promotions as $promo) {
            if ($this->findOpenJob('sync_promotion', $promo['id'])) {
                $jobsSkipped++;
                continue;
            }
            
            // Kreiramo posao čak i ako je 0, da bi worker mogao da evidentira prolaz
            $jobResult = $queue->createJobIfNotOpen('sync_promotion', $promo['id'], 0);
            if (!empty($jobResult['created'])) {
                $jobsCreated++;
            } else {
                $jobsSkipped++;
            }
        }

        return [
            'message' => "Uspešno zakazano {$jobsCreated} poslova sinhronizacije. Preskočeno postojećih: {$jobsSkipped}.",
            'jobs' => $jobsCreated,
            'skipped' => $jobsSkipped
        ];
    }

    /**
     * Zakazuje cleanup jobove za istekle promocije koje jos imaju vezane proizvode.
     */
    private function queueExpiredPromotionCleanupJobs(QueueService $queue): int {
        $expiredPromotions = $this->db->fetchAll(
            "SELECT p.id, COUNT(pp.id) AS total_items
             FROM promotions p
             INNER JOIN promotion_products pp
                ON pp.store_hash = p.store_hash
               AND pp.promotion_id = p.id
             WHERE p.store_hash = ?
               AND p.status = 'expired'
               AND p.end_date < NOW()
             GROUP BY p.id",
            [$this->storeHash]
        );

        $jobsCreated = 0;
        foreach ($expiredPromotions as $promotion) {
            $totalItems = (int)($promotion['total_items'] ?? 0);
            if ($totalItems <= 0 || $this->findOpenJob('cleanup_single', $promotion['id'])) {
                continue;
            }

            $queue->createJobIfNotOpen('cleanup_single', $promotion['id'], $totalItems);
            $jobsCreated++;
        }

        return $jobsCreated;
    }

    /**
     * Sync all promotions using LOCAL cache (fast!)
     */
    public function syncAllPromotions() {
        $startTime = microtime(true);
        $this->promotionModel->updateStatuses();
        $promotions = $this->promotionModel->findActive();
        $totals = ['promotions' => count($promotions), 'products' => 0, 'success' => 0, 'errors' => 0, 'cleaned' => 0];
        foreach ($promotions as $promotion) {
            $cursor = null;
            do {
                $r = $this->syncSinglePromotionBatch($promotion['id'], 50, 0, $cursor);
                $totals['products'] += $r['visited'];
                $totals['success'] += $r['processed'];
                $totals['errors'] += $r['errors'];
                $totals['cleaned'] += $r['cleaned'];
                $cursor = $r['next_cursor'];
            } while ($r['visited'] === 50);
        }
        $clean = $this->cleanupAllProductsBatch();
        $totals['cleaned'] += $clean['processed'];
        $totals['errors'] += $clean['errors'];
        $duration = microtime(true) - $startTime;
        $message = 'Promotion sync completed';
        $this->logSync(null,$totals['success'],$totals['errors'],$duration,$message);
        return $totals + ['duration' => $duration, 'message' => $message, 'debug' => []];
    }

    
    /**
     * Preview products that match promotion filters (WITHOUT applying promotion)
     */
    public function previewPromotionProducts($filters, $discountPercent, $referenceAt = null, array $context = []) {
        $discountPercent = $this->validateDiscountPercent($discountPercent);
        $costPriceBlockEnabled = $this->isCostPriceBlockingEnabledFromFilters($filters);
        $manualUnblockedItems = $this->getManualUnblockedItemKeysFromFilters($filters);
        $previewContext = $context + [
            'filters' => $filters,
            'discount_percent' => $discountPercent,
            'start_date' => $referenceAt,
        ];
        $existingPreviewPromotion = $this->findPromotionFromPreviewContext($previewContext);
        $skipOmnibusRevalidation = is_array($existingPreviewPromotion)
            && (
                !$this->hasPromotionOmnibusTermsChanged($existingPreviewPromotion, $previewContext)
                || $this->isActiveDiscountCorrectionPreview($existingPreviewPromotion, $previewContext)
            );
        $existingPromotionReferenceAt = is_array($existingPreviewPromotion)
            ? $this->resolvePromotionOmnibusReferenceAt($existingPreviewPromotion)
            : null;
        $newPreviewItemReferenceAt = $this->resolveNewPreviewItemOmnibusReferenceAt($referenceAt);
        // getProductsByFilters sada vraća i proizvode i varijante
        $limit = self::PREVIEW_PAGE_LIMIT;
        $search = $this->normalizePreviewSearch($context['search'] ?? '');
        $cursorPayload = $this->decodePreviewCursor($context['cursor'] ?? null, $filters, $search);
        $items = $this->cacheService->getProductsByFilters($filters, $limit + 1, 0, [
            'cursor' => $cursorPayload,
            'search' => $search,
        ]);
        $hasMore = count($items) > $limit;
        if ($hasMore) {
            $items = array_slice($items, 0, $limit);
        }
        
        $preview = [];
        foreach ($items as $item) {
            $item[self::COST_PRICE_BLOCK_ENABLED_ITEM_FLAG] = $costPriceBlockEnabled;
            $item[self::MANUAL_UNBLOCK_ITEM_FLAG] = $this->isItemManuallyUnblocked($item, $manualUnblockedItems);
            $skipItemOmnibusRevalidation = $skipOmnibusRevalidation
                && $this->isExistingPromotionProductCurrentForTerms($item, $existingPreviewPromotion);
            $omnibusReferenceAt = $skipItemOmnibusRevalidation && $existingPromotionReferenceAt !== null
                ? $existingPromotionReferenceAt
                : $newPreviewItemReferenceAt;
            $row = $this->buildPromotionPreviewRow(
                $item,
                $discountPercent,
                $omnibusReferenceAt,
                true,
                $skipItemOmnibusRevalidation
            );
            if ($row !== null) {
                $preview[] = $row;
            }
        }
        
        $pageInvalidProducts = count(array_filter($preview, fn($row) => empty($row['will_apply'])));
        $pageSavings = array_sum(array_column($preview, 'savings'));

        return [
            'total_products' => method_exists($this->cacheService, 'countProductsByFilters')
                ? $this->cacheService->countProductsByFilters($filters, ['search' => $search])
                : count($preview),
            'total_invalid_products' => $pageInvalidProducts,
            'total_savings' => $pageSavings,
            'next_cursor' => $hasMore ? $this->encodePreviewCursor(end($items), $filters, $search) : null,
            'has_more' => $hasMore,
            'page_total_invalid_products' => $pageInvalidProducts,
            'page_total_savings' => $pageSavings,
            'products' => $preview
        ];
    }
    
    /**
     * Get statistics about filters
     */
    public function getFilterStats($filters) {
        return $this->cacheService->getFilterStats($filters);
    }

    private function encodePreviewCursor($item, $filters, string $search): ?string {
        if (!is_array($item) || empty($item['product_id']) || empty($item['id'])) {
            return null;
        }

        $payload = [
            'product_id' => (int)$item['product_id'],
            'variant_sort_id' => isset($item['variant_sort_id'])
                ? (int)$item['variant_sort_id']
                : (int)($item['variant_id'] ?? 0),
            'id' => (string)$item['id'],
            'fingerprint' => $this->buildPreviewCursorFingerprint($filters, $search),
        ];

        return rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    private function decodePreviewCursor($cursor, $filters, string $search): ?array {
        $cursor = trim((string)$cursor);
        if ($cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $payload = $decoded !== false ? json_decode($decoded, true) : null;
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid preview cursor.');
        }

        $expectedFingerprint = $this->buildPreviewCursorFingerprint($filters, $search);
        $actualFingerprint = (string)($payload['fingerprint'] ?? '');
        if (!hash_equals($expectedFingerprint, $actualFingerprint)) {
            throw new \InvalidArgumentException('Preview cursor is no longer valid for these filters.');
        }

        return [
            'product_id' => (int)($payload['product_id'] ?? 0),
            'variant_sort_id' => (int)($payload['variant_sort_id'] ?? 0),
            'id' => (string)($payload['id'] ?? ''),
        ];
    }

    private function buildPreviewCursorFingerprint($filters, string $search): string {
        return hash('sha256', implode('|', [
            (string)$this->storeHash,
            $this->normalizeFiltersForComparison($filters),
            $this->normalizePreviewSearch($search),
        ]));
    }

    private function normalizePreviewSearch($search): string {
        $search = trim((string)$search);

        return function_exists('mb_substr')
            ? mb_substr($search, 0, 120)
            : substr($search, 0, 120);
    }
    
    
    public function cleanupAllProductsBatch(): array {
        $stats = ['processed' => 0, 'errors' => 0, 'diagnostics' => [], 'omnibus_product_ids' => []];
        $after = 0;
        do {
            $page = $this->cleanupOwnersPage(null, 50, $after);
            $after = $page['next_id'] ?? $after;
            $stats['processed'] += $page['processed'];
            $stats['errors'] += $page['errors'];
            $stats['diagnostics'] = array_slice(array_merge($stats['diagnostics'], $page['diagnostics']), 0, 25);
            $stats['omnibus_product_ids'] = $this->mergeProductIdLists($stats['omnibus_product_ids'], $page['omnibus_product_ids']);
        } while ($page['visited'] === 50);
        return $stats;
    }

    public function cleanupSinglePromotionBatch($promotionId, $limit = 50, int $afterId = 0) {
        return $this->cleanupOwnersPage((int)$promotionId, $limit, $afterId);
    }

    public function cleanupOwnersPage(?int $promotionId, int $limit, int $after): array {
        $where = 'pp.store_hash=? AND pp.id>?';
        $params = [$this->storeHash,$after];
        if ($promotionId !== null) {
            $where .= ' AND pp.promotion_id=?';
            $params[] = $promotionId;
        }
        $items = $this->fetchPromotionProductsWithCachePrice($where,$params,'ORDER BY pp.id LIMIT ' . (int)$limit);
        $visited = count($items);
        $next = $items ? (int)end($items)['id'] : null;
        $work = function () use ($items,$visited,$next) {
            $ids = array_column($items,'id');
            if ($ids && $this->ownership) {
                $marks = implode(',',array_fill(0,count($ids),'?'));
                $items = $this->fetchPromotionProductsWithCachePrice("pp.store_hash=? AND pp.id IN ($marks)",[$this->storeHash,...$ids]);
            }
            $active = $this->normalizeActivePromotionsForBatch($this->promotionModel->findActive());
            $activeIds = array_column($active, 'id');
            $eligible = array_filter($items, fn($i) => !in_array($i['promotion_id'], $activeIds));
            $expired = $this->extractPromotionIds($eligible);
            foreach ($expired as $id) {
                $promo = $this->promotionModel->findById($id);
                if ($promo && $promo['status'] === 'expired') {
                    $this->finalizePromotionArchive($id, 'expired_cleanup');
                }
            }
            $changes = $this->getPromotionItemsNoLongerApplicable($eligible, 0, $active);
            $apply = $this->applyPromotionCandidatesBatch($changes['apply'], $eligible);
            $restore = $this->cleanupPromotionProductItems($changes['restore'], 'promotion_cleanup');
            foreach ($expired as $id) {
                $this->markPromotionArchiveCleanupCompleted($id);
            }
            return ['processed' => $apply['processed'] + $restore['processed'], 'errors' => $apply['errors'] + $restore['errors'],
                'diagnostics' => array_merge($apply['diagnostics'] ?? [],$restore['diagnostics'] ?? []),
                'omnibus_product_ids' => $this->mergeProductIdLists($apply['omnibus_product_ids'],$restore['omnibus_product_ids']),
                'visited' => $visited, 'next_id' => $next];
        };
        return $this->ownership ? $this->ownership->locked($items,$work) : $work();
    }

    private function batchSavePromotionProducts($promotions, ?string $lifecycleAt = null) {
        if (empty($promotions)) {
            return;
        }

        $this->batchSavePromotionProductsNullSafe($promotions, $lifecycleAt);
    }

    private function batchSavePromotionProductsNullSafe(array $promotions, ?string $lifecycleAt = null): void {
        $promotions = $this->normalizePromotionProductRows($promotions);
        if (empty($promotions)) {
            return;
        }

        foreach (array_chunk($promotions, 100) as $chunk) {
            $existingRows = $this->fetchExistingPromotionProductRows($chunk);
            $existingRowsByKey = [];

            foreach ($existingRows as $row) {
                $key = $this->getPromotionItemKey(
                    (int)$row['product_id'],
                    $row['variant_id'] !== null ? (int)$row['variant_id'] : null
                );
                $existingRowsByKey[$key][] = $row;
            }

            $rowsToInsert = [];
            $duplicateIdsToDelete = [];

            foreach ($chunk as $promo) {
                $key = $this->getPromotionItemKey($promo['product_id'], $promo['variant_id']);
                $existingForItem = $existingRowsByKey[$key] ?? [];

                if (!empty($existingForItem)) {
                    $primaryRow = array_shift($existingForItem);
                    foreach ($existingForItem as $duplicateRow) {
                        $duplicateIdsToDelete[] = (int)$duplicateRow['id'];
                    }

                    $promotionChanged = (int)($primaryRow['promotion_id'] ?? 0) !== $promo['promotion_id'];
                    $lifecycleResetSql = '';
                    $bindings = [
                        $promo['promotion_id'],
                        $promo['custom_field_id'],
                    ];
                    if ($promotionChanged) {
                        $this->recordReplacedPromotionItems([$primaryRow]);

                        if ($lifecycleAt !== null) {
                            $lifecycleResetSql = ', first_applied_at = ?, omnibus_reference_at = ?';
                            $bindings[] = $lifecycleAt;
                            $bindings[] = $lifecycleAt;
                        } else {
                            $lifecycleResetSql = ', first_applied_at = NOW(), omnibus_reference_at = NOW()';
                        }
                    }

                    if ($lifecycleAt !== null) {
                        $syncedAtSql = '?';
                        $bindings[] = $lifecycleAt;
                    } else {
                        $syncedAtSql = 'NOW()';
                    }

                    if (isset($promo['product_name'], $promo['original_price'], $promo['promo_price'])) {
                        $snapshotSql = ', product_name=?, original_price=?, promo_price=?, previous_sale_price=?, prior_price_known=1';
                        array_push($bindings, $promo['product_name'], $promo['original_price'], $promo['promo_price'], $promo['previous_sale_price'] ?? null);
                    } else {
                        $snapshotSql = '';
                    }
                    $bindings[] = $this->storeHash;
                    $bindings[] = (int)$primaryRow['id'];
                    $this->db->query(
                        "UPDATE promotion_products
                         SET promotion_id = ?, custom_field_id = ?{$lifecycleResetSql}, synced_at = {$syncedAtSql}{$snapshotSql}
                         WHERE store_hash = ? AND id = ?",
                        $bindings
                    );
                    continue;
                }

                $rowsToInsert[] = $promo;
            }

            if (!empty($duplicateIdsToDelete)) {
                $placeholders = str_repeat('?,', count($duplicateIdsToDelete) - 1) . '?';
                $this->db->query(
                    "DELETE FROM promotion_products WHERE store_hash = ? AND id IN ($placeholders)",
                    array_merge([$this->storeHash], $duplicateIdsToDelete)
                );
            }

            $this->insertPromotionProductRows($rowsToInsert, $lifecycleAt);
        }
    }

    private function normalizePromotionProductRows(array $promotions): array {
        $normalized = [];

        foreach ($promotions as $promo) {
            $productId = (int)($promo['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $variantId = isset($promo['variant_id']) && $promo['variant_id'] !== null && $promo['variant_id'] !== ''
                ? (int)$promo['variant_id']
                : null;
            $key = $this->getPromotionItemKey($productId, $variantId);

            $normalized[$key] = [
                'promotion_id' => (int)($promo['promotion_id'] ?? 0),
                'product_id' => $productId,
                'variant_id' => $variantId,
                'custom_field_id' => isset($promo['custom_field_id']) && $promo['custom_field_id'] !== null
                    ? (int)$promo['custom_field_id']
                    : null,
            ] + array_intersect_key($promo, array_flip(['product_name','original_price','promo_price','previous_sale_price']));
        }

        return array_values(array_filter($normalized, static function (array $promo): bool {
            return $promo['promotion_id'] > 0;
        }));
    }

    private function fetchExistingPromotionProductRows(array $promotions): array {
        if (empty($promotions)) {
            return [];
        }

        $conditions = [];
        $params = [$this->storeHash];

        foreach ($promotions as $promo) {
            $conditions[] = '(product_id = ? AND variant_id <=> ?)';
            $params[] = $promo['product_id'];
            $params[] = $promo['variant_id'];
        }

        return $this->db->fetchAll(
            "SELECT id, promotion_id, product_id, variant_id
             FROM promotion_products
             WHERE store_hash = ? AND (" . implode(' OR ', $conditions) . ")
             ORDER BY product_id ASC, variant_id ASC, synced_at DESC, id DESC",
            $params
        );
    }

    private function insertPromotionProductRows(array $promotions, ?string $lifecycleAt = null): void {
        if (empty($promotions)) {
            return;
        }

        $values = [];
        $bindings = [];
        $snapshots = isset($promotions[array_key_first($promotions)]['promo_price']);
        $columns = $snapshots ? ', product_name, original_price, promo_price, previous_sale_price, prior_price_known' : '';

        foreach ($promotions as $promo) {
            if ($snapshots) {
                $values[] = '(?,?,?,?,?,COALESCE(?,NOW()),COALESCE(?,NOW()),COALESCE(?,NOW()),?,?,?,?,1)';
                array_push($bindings, $this->storeHash, $promo['promotion_id'], $promo['product_id'], $promo['variant_id'],
                    $promo['custom_field_id'], $lifecycleAt, $lifecycleAt, $lifecycleAt, $promo['product_name'],
                    $promo['original_price'], $promo['promo_price'], $promo['previous_sale_price'] ?? null);
                continue;
            }
            if ($lifecycleAt !== null) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                $bindings = array_merge($bindings, [
                    $this->storeHash,
                    $promo['promotion_id'],
                    $promo['product_id'],
                    $promo['variant_id'],
                    $promo['custom_field_id'],
                    $lifecycleAt,
                    $lifecycleAt,
                    $lifecycleAt,
                ]);
                continue;
            }

            $values[] = '(?, ?, ?, ?, ?, NOW(), NOW(), NOW())';
            $bindings = array_merge($bindings, [
                $this->storeHash,
                $promo['promotion_id'],
                $promo['product_id'],
                $promo['variant_id'],
                $promo['custom_field_id'],
            ]);
        }

        $this->db->query(
            "INSERT INTO promotion_products
             (store_hash, promotion_id, product_id, variant_id, custom_field_id, first_applied_at, omnibus_reference_at, synced_at{$columns})
             VALUES " . implode(', ', $values),
            $bindings
        );
    }
    
    private function isBetterPromotion($newPromo, $existingPromo) {
        return $newPromo['priority'] > $existingPromo['priority'] ||
               ($newPromo['priority'] == $existingPromo['priority'] && 
                $newPromo['discount_percent'] > $existingPromo['discount_percent']);
    }
    
    public function logSync($promotionId, $synced, $errors, $duration, $message, $type = 'full') {
        $this->db->query(
            "INSERT INTO sync_log (store_hash, promotion_id, sync_type, products_synced, errors, duration_seconds, log_message)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$this->storeHash, $promotionId, $type, $synced, $errors, round($duration, 2), $message]
        );
    }

    private function getDatabaseTimestamp(): string {
        try {
            $row = $this->db->fetchOne("SELECT NOW() AS current_time");
            if (!empty($row['current_time'])) {
                return (new \DateTimeImmutable((string)$row['current_time']))->format('Y-m-d H:i:s');
            }
        } catch (\Throwable $e) {
            // Fallback keeps non-DB tests and degraded environments operational.
        }

        return date('Y-m-d H:i:s');
    }

    public function syncSinglePromotion($promotionId) {
        $start = microtime(true);
        $stats = ['synced' => 0, 'cleaned' => 0, 'errors' => 0, 'diagnostics' => [], 'omnibus_product_ids' => []];
        $cursor = null;
        do {
            $page = $this->syncSinglePromotionBatch($promotionId, 50, 0, $cursor);
            $cursor = $page['next_cursor'];
            $stats['synced'] += $page['processed'];
            $stats['cleaned'] += $page['cleaned'];
            $stats['errors'] += $page['errors'];
            $stats['diagnostics'] = array_slice(array_merge($stats['diagnostics'], $page['diagnostics']), 0, 25);
            $stats['omnibus_product_ids'] = $this->mergeProductIdLists($stats['omnibus_product_ids'], $page['omnibus_product_ids']);
        } while ($cursor !== null && $page['visited'] === 50);
        $this->logSync($promotionId, $stats['synced'] + $stats['cleaned'], $stats['errors'], microtime(true) - $start,
            'Single promotion sync ' . self::formatPriceDiagnostics($stats['diagnostics']));
        return $stats;
    }

    public function syncSinglePromotionBatch($promotionId, $limit = 50, $offset = 0, ?array $cursor = null) {
        $promotion = $this->promotionModel->findById($promotionId);
        if (!$promotion) throw new \Exception("Promocija nije pronađena.");

        $filters = json_decode($promotion['filters'], true);
        $activePromotions = $this->normalizeActivePromotionsForBatch($this->promotionModel->findActive());
        $reconciliationResult = ['changed' => 0, 'omnibus_product_ids' => []];
        if ($offset === 0 && ($cursor === null || isset($cursor['reconcile_after']))) {
            $reconciliationResult = $this->reconcilePromotionProductsForPromotion($promotion, $activePromotions,
                (int)($cursor['reconcile_after'] ?? 0), $limit);
            if (($reconciliationResult['visited'] ?? 0) === $limit) {
                return ['processed' => 0, 'cleaned' => $reconciliationResult['changed'], 'errors' => $reconciliationResult['errors'],
                    'skipped' => 0, 'visited' => $limit, 'next_cursor' => ['reconcile_after' => $reconciliationResult['after']],
                    'omnibus_product_ids' => $reconciliationResult['omnibus_product_ids'], 'diagnostics' => $reconciliationResult['diagnostics']];
            }
            $cursor = null;
        }
        $reconciledCount = $reconciliationResult['changed'] ?? 0;
        
        // 1. Dohvati proizvode i varijante
        $items = $this->cacheService->getProductsByFilters($filters, $limit, $offset, ['cursor' => $cursor]);
        $lastItem = end($items);
        $nextCursor = $lastItem ? [
            'product_id' => (int)$lastItem['product_id'],
            'variant_sort_id' => (int)($lastItem['variant_sort_id'] ?? $lastItem['variant_id'] ?? 0),
            'id' => (string)$lastItem['id'],
        ] : null;

        $this->termsOwners = null;
        if ($items && $this->ownership) {
            $ids = array_values(array_unique(array_column($items,'product_id')));
            $marks = implode(',',array_fill(0,count($ids),'?'));
            $this->termsOwners = [];
            foreach ($this->db->fetchAll("SELECT product_id,variant_id,promotion_id,synced_at FROM promotion_products
                WHERE store_hash=? AND product_id IN ($marks)", [$this->storeHash,...$ids]) as $owner) {
                $key = $this->getPromotionItemKey($owner['product_id'],$owner['variant_id']);
                $this->termsOwners[$owner['promotion_id']][$key] = $owner;
            }
        }
        if ($items) {
            if ($this->omnibusPricingService instanceof OmnibusPricingService && $this->isOmnibusEnabled()) {
                $this->omnibusPricingService->prefetchHistory($this->storeHash,array_column($items,'product_id'));
            }
            $this->prefetchPromotionProductExclusions($activePromotions, $items);
        }
        $productPromotions = [];

        // 2. Priprema podataka (samo logika, bez API poziva)
        foreach ($items as $item) {
            // Provera da li postoji bolja promocija od ove trenutne
            $bestPromo = $this->calculateBestPromotionCandidate($item, $activePromotions);
            
            // Ako je ova promocija ($promotion) ta koja je najbolja (ili jednako dobra), primeni je
            if ($bestPromo && $bestPromo['promotion_id'] == $promotion['id']) {
                $originalPrice = (float)$item['price'];
                if ($originalPrice <= 0) {
                    continue;
                } // Skip items without a price

                $discount = (float)$promotion['discount_percent'];
                $promoPrice = round($originalPrice * (1 - $discount / 100), 2);

                // Koristimo kompozitni ključ da razlikujemo proizvode i varijante
                $key = $item['variant_id'] ? "v_{$item['variant_id']}" : "p_{$item['product_id']}";

                $productPromotions[$key] = [
                    'promotion_id'   => $promotion['id'],
                    'product_id'     => $item['product_id'],
                    'variant_id'     => $item['variant_id'] ?? null,
                    'product_name'   => $item['name'],
                    'original_price' => $originalPrice,
                    'discount_percent' => $discount,
                    'promo_price'    => $promoPrice,
                    'promotion_name' => $promotion['name'],
                    'custom_field_value' => $promotion['custom_field_value'] ?? $promotion['name']
                ];
            }
        }

        $this->termsOwners = null;
        $applyResults = $this->applyPromotionCandidatesBatch($productPromotions, $items);

        return [
            'processed' => $applyResults['processed'],
            'errors' => $applyResults['errors'] + ($reconciliationResult['errors'] ?? 0),
            'skipped' => count($items) - count($productPromotions) + ($applyResults['skipped'] ?? 0),
            'visited' => count($items),
            'next_cursor' => $nextCursor,
            'diagnostics' => array_merge($applyResults['diagnostics'] ?? [], $reconciliationResult['diagnostics'] ?? []),
            'cleaned' => $reconciledCount,
            'omnibus_product_ids' => $this->mergeProductIdLists(
                $applyResults['omnibus_product_ids'] ?? [],
                $reconciliationResult['omnibus_product_ids'] ?? []
            ),
        ];
    }

    private function applyPromotionCandidatesBatch(array $productPromotions, array $sourceItems = []): array {
        $work = fn() => $this->applyLockedCandidates($productPromotions, $sourceItems);
        return $this->ownership ? $this->ownership->locked($productPromotions, $work) : $work();
    }

    private function applyLockedCandidates(array $productPromotions, array $sourceItems): array {
        if (empty($productPromotions)) {
            return ['processed' => 0, 'errors' => 0, 'applied' => [], 'omnibus_product_ids' => []];
        }

        $cached = [];
        foreach ($sourceItems as $source) {
            $cached[$this->getPromotionItemKey($source['product_id'],$source['variant_id'] ?? null)] = $source['sale_price'] ?? null;
        }
        $stableOwners = [];
        $unchanged = [];
        $skipped = [];
        $writes = [];
        foreach ($productPromotions as $key => &$promo) {
            $itemKey = $this->getPromotionItemKey($promo['product_id'],$promo['variant_id'] ?? null);
            $promo['cached_sale_price'] = $cached[$itemKey] ?? null;
            $check = $this->ownership?->prepare($promo, false) ?? ['action' => 'write'];
            if (in_array($check['action'], ['conflict','skip'], true)) {
                $skipped[] = $key;
                continue;
            }
            $promo['previous_sale_price'] = $check['previous_sale_price'] ?? null;
            if (isset($check['owner'])) {
                $stableOwners[$itemKey] = $check['owner'];
            }
            if ($check['action'] === 'unchanged') {
                $unchanged[] = ['success' => true, 'product_id' => $promo['product_id'], 'variant_id' => $promo['variant_id'] ?? null];
            } else {
                $writes[] = $promo;
            }
        }
        unset($promo);
        foreach ($skipped as $key) {
            unset($productPromotions[$key]);
        }
        [$productUpdates, $variantUpdates] = $this->buildPriceUpdateBatches($writes);

        $productResults = !empty($productUpdates) ? $this->api->batchUpdateProducts($productUpdates) : [];
        $variantResults = !empty($variantUpdates) ? $this->updateVariantPrices($variantUpdates) : [];
        $priceResults = array_merge($productResults, $variantResults, $unchanged);
        $appliedPromotions = $this->filterPromotionsWithSuccessfulPriceUpdates($productPromotions, $priceResults);
        $diagnostics = $this->buildPriceUpdateFailureDiagnostics($productPromotions, $priceResults, $sourceItems);

        if (empty($appliedPromotions)) {
            return [
                'processed' => 0,
                'errors' => count($productPromotions), 'skipped' => count($skipped),
                'applied' => [],
                'diagnostics' => $diagnostics,
                'omnibus_product_ids' => []
            ];
        }

        $existingFieldsMap = $this->buildExistingFieldsMap($sourceItems);
        $knownFieldIds = $this->getKnownPromotionFieldIds(
            array_values(array_unique(array_column($appliedPromotions, 'product_id')))
        );
        $cfUpdates = $this->buildUniquePromotionFieldUpdates($appliedPromotions);
        $cfResults = $this->customFieldService->upsertCustomFields($cfUpdates, $existingFieldsMap, $knownFieldIds);

        $fieldIdMap = [];
        foreach ($cfResults as $result) {
            if (!empty($result['success']) && !empty($result['custom_field_id'])) {
                $fieldIdMap[$result['product_id']] = $result['custom_field_id'];
            }
        }

        foreach ($appliedPromotions as &$promo) {
            $promo['custom_field_id'] = $fieldIdMap[$promo['product_id']] ?? null;
        }
        unset($promo);

        $promotionAppliedAt = $this->getDatabaseTimestamp();
        foreach ($appliedPromotions as &$promo) {
            $promo['applied_at'] = $promotionAppliedAt;
            $promo['last_seen_at'] = $promotionAppliedAt;
        }
        unset($promo);

        $completedCount = count($appliedPromotions);
        $appliedPromotions = array_filter($appliedPromotions, function ($item) use ($stableOwners) {
            $owner = $stableOwners[$this->getPromotionItemKey($item['product_id'],$item['variant_id'] ?? null)] ?? null;
            return !$owner || !$item['custom_field_id'] || $owner['custom_field_id'] != $item['custom_field_id']
                || (float)$item['cached_sale_price'] !== (float)$item['promo_price']
                || $item['product_name'] !== $owner['product_name'] || (float)$item['original_price'] !== (float)$owner['original_price'];
        });
        if ($this->ownership && $appliedPromotions) {
            $this->db->beginTransaction();
        }
        try {
            $this->batchSavePromotionProducts($appliedPromotions, $promotionAppliedAt);
            if ($appliedPromotions) {
                $this->recordAppliedPromotionItems($this->buildPromotionArchiveMetaFromAppliedItems($appliedPromotions), $appliedPromotions);
            }

            $cachePriceUpdates = $this->buildPromotionCachePriceUpdates($appliedPromotions, $promotionAppliedAt);
            $this->cacheService->updatePriceCacheDirectly($cachePriceUpdates);

            foreach ($appliedPromotions as $item) {
                $this->ownership?->confirmed($item);
            }
            if ($this->ownership && $appliedPromotions) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->ownership && $appliedPromotions) {
                $this->db->rollback();
            }
            throw $e;
        }
        foreach ($cfResults as $field) {
            if (empty($field['success'])) {
                $diagnostics[] = ['product_id'=>$field['product_id'], 'error'=>'Promotion field update failed'];
            }
        }
        $fieldErrors = count(array_filter($cfResults, fn($field) => empty($field['success'])));
        return [
            'processed' => $completedCount, 'skipped' => count($skipped),
            'errors' => $fieldErrors + count($productPromotions) - $completedCount,
            'applied' => $appliedPromotions,
            'diagnostics' => $diagnostics,
            'omnibus_product_ids' => $this->extractUniqueProductIds($this->filterPromotionsWithSuccessfulPriceUpdates($writes, $priceResults)),
        ];
    }

    private function buildExistingFieldsMap(array $items): array {
        $existingFieldsMap = [];

        foreach ($items as $item) {
            if (!empty($item['variant_id']) || empty($item['product_id']) || !array_key_exists('custom_fields', $item)) {
                continue;
            }

            $existingFieldsMap[$item['product_id']] = is_string($item['custom_fields'])
                ? json_decode($item['custom_fields'], true)
                : $item['custom_fields'];
        }

        return $existingFieldsMap;
    }

    private function getKnownPromotionFieldIds(array $productIds): array {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static function (int $id): bool {
            return $id > 0;
        })));

        if (empty($productIds)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        $rows = $this->db->fetchAll(
            "SELECT product_id, custom_field_id
             FROM promotion_products
             WHERE store_hash = ? AND product_id IN ($placeholders)",
            array_merge([$this->storeHash], $productIds)
        );

        return array_column($rows, 'custom_field_id', 'product_id');
    }

    private function reconcilePromotionProductsForPromotion(array $promotion, array $activePromotions, int $after = 0, int $limit = 50): array {
        $promotionId = (int)($promotion['id'] ?? 0);
        if ($promotionId <= 0) {
            return ['changed' => 0, 'omnibus_product_ids' => []];
        }

        $existingItems = $this->fetchPromotionProductsWithCachePrice(
            "pp.promotion_id = ? AND pp.store_hash = ? AND pp.id > ?",
            [$promotionId, $this->storeHash, $after], "ORDER BY pp.id LIMIT " . (int)$limit
        );

        if (empty($existingItems)) {
            return ['changed' => 0, 'omnibus_product_ids' => []];
        }

        $reconciliation = $this->getPromotionItemsNoLongerApplicable($existingItems, $promotionId, $activePromotions);
        $changedCount = 0;
        $omnibusProductIds = [];

        if (!empty($reconciliation['apply'])) {
            $applyResults = $this->applyPromotionCandidatesBatch($reconciliation['apply'], $existingItems);
            $changedCount += $applyResults['processed'];
            $omnibusProductIds = $this->mergeProductIdLists(
                $omnibusProductIds,
                $applyResults['omnibus_product_ids'] ?? []
            );
        }

        if (!empty($reconciliation['restore'])) {
            $cleanupResults = $this->cleanupPromotionProductItems($reconciliation['restore']);
            $changedCount += $cleanupResults['processed'];
            $omnibusProductIds = $this->mergeProductIdLists(
                $omnibusProductIds,
                $cleanupResults['omnibus_product_ids'] ?? []
            );
        }

        return [
            'after' => (int)end($existingItems)['id'], 'visited' => count($existingItems),
            'changed' => $changedCount,
            'omnibus_product_ids' => $omnibusProductIds,
            'errors' => ($applyResults['errors'] ?? 0) + ($cleanupResults['errors'] ?? 0),
            'diagnostics' => array_merge($applyResults['diagnostics'] ?? [], $cleanupResults['diagnostics'] ?? []),
        ];
    }

    private function getPromotionItemsNoLongerApplicable(array $items, int $promotionId, array $activePromotions): array {
        $toRestore = [];
        $toApply = [];

        foreach ($items as $item) {
            $bestCandidate = $this->calculateBestPromotionCandidate($item, $activePromotions);

            if ($bestCandidate && (int)$bestCandidate['promotion_id'] === $promotionId) {
                continue;
            }

            if ($bestCandidate) {
                $key = $this->getPromotionItemKey($bestCandidate['product_id'], $bestCandidate['variant_id'] ?? null);
                $toApply[$key] = $bestCandidate;
                continue;
            }

            $toRestore[] = $item;
        }

        return [
            'restore' => $toRestore,
            'apply' => array_values($toApply),
        ];
    }

    private function cleanupPromotionProductItems(array $items, string $reason = 'no_longer_applicable'): array {
        $work = fn() => $this->restoreLockedItems($items, $reason);
        return $this->ownership ? $this->ownership->locked($items, $work) : $work();
    }

    private function restoreLockedItems(array $items, string $reason): array {
        $unchanged = [];
        $writes = [];
        foreach ($items as $key => &$item) {
            $check = $this->ownership?->prepare($item, true, $reason === 'promotion_cleanup') ?? ['action' => 'write'];
            if (in_array($check['action'], ['skip','conflict'], true)) {
                unset($items[$key]);
                continue;
            }
            $item['restore_price'] = $check['target'] ?? null;
            if ($check['action'] === 'unchanged') {
                $unchanged[] = ['success' => true, 'product_id' => $item['product_id'], 'variant_id' => $item['variant_id'] ?? null];
            } else {
                $writes[] = $item;
            }
        }
        unset($item);
        $productResults = [];
        [$productUpdates, $variantUpdates] = $this->buildRestoreUpdates($writes);
        [, , $cacheUpdates] = $this->buildRestoreUpdates($items);
        if ($productUpdates) {
            $productResults = $this->api->batchUpdateProducts($productUpdates);
        }
        $variantResults = $variantUpdates ? $this->updateVariantPrices($variantUpdates) : [];
        $priceResults = array_merge($productResults, $variantResults, $unchanged);
        $restored = $this->filterPromotionsWithSuccessfulPriceUpdates($items, $priceResults);
        $diagnostics = $this->buildPriceUpdateFailureDiagnostics($items, $priceResults, $items);

        if ($restored) {
            $ids = array_column($restored, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            // The price logger can run schema guards. Finish its work before the ownership transaction.
            $this->cacheService->updatePriceCacheDirectly(
                $this->filterPromotionsWithSuccessfulPriceUpdates($cacheUpdates, $priceResults)
            );
            $this->db->beginTransaction();
            try {
                // A concurrent verification or replacement can have already closed these ownership rows.
                $owners = $this->db->fetchAll(
                    "SELECT id, promotion_id FROM promotion_products WHERE store_hash = ?
                     AND id IN ($placeholders) ORDER BY id FOR UPDATE", array_merge([$this->storeHash], $ids)
                );
                $ownerById = array_column($owners, 'promotion_id', 'id');
                $stillOwned = array_filter($restored, static function ($item) use ($ownerById) {
                    return isset($ownerById[$item['id']]) && (int)$ownerById[$item['id']] === (int)$item['promotion_id'];
                });
                if ($stillOwned) {
                    $this->recordRemovedPromotionItems(null, $stillOwned, $reason);
                    $ids = array_column($stillOwned, 'id');
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $this->db->query(
                        "DELETE FROM promotion_products WHERE store_hash = ? AND id IN ($placeholders)",
                        array_merge([$this->storeHash], $ids)
                    );
                }
                foreach ($this->getProductsWithoutActivePromotionEntries($stillOwned) as $pid) {
                    $this->db->query("INSERT INTO sync_jobs(store_hash,job_type,payload,status,total_items,created_at)
                        VALUES (?,'cleanup_promotion_field',?,'pending',1,NOW())",
                        [$this->storeHash,json_encode(['product_ids'=>[(int)$pid]])]);
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
            foreach ($stillOwned as $item) {
                $this->ownership?->confirmed($item);
            }
        }
        return [
            'processed' => count($restored),
            'errors' => count($items) - count($restored),
            'diagnostics' => $diagnostics,
            'omnibus_product_ids' => $this->extractUniqueProductIds($restored),
        ];
    }

    private function processProductsBatch($products, $specificPromoId = null) {
        $synced = 0;
        $errors = 0;
        $cleaned = 0;
        $diagnostics = [];
        $omnibusProductIds = [];
        
        // Dohvati sve trenutno aktivne promocije za poređenje prioriteta
        $activePromotions = $this->promotionModel->findActive();

        foreach ($products as $product) {
            try {
                // Pronađi najbolju promociju za ovaj konkretan proizvod
                $bestPromo = $this->calculateBestPromotionCandidate($product, $activePromotions);
                
                if ($bestPromo) {
                    // Primeni cenu i custom field
                    $result = $this->applyPromotionCandidatesBatch([$bestPromo], [$product]);
                    $synced += $result['processed'];
                } else {
                    $owners = $this->fetchPromotionProductsWithCachePrice(
                        'pp.store_hash=? AND pp.product_id=? AND pp.variant_id <=> ?',
                        [$this->storeHash,$product['product_id'],$product['variant_id'] ?? null]);
                    $result = $this->cleanupPromotionProductItems($owners, 'product_resync');
                    $cleaned += $result['processed'];
                }
                $errors += $result['errors'];
                $diagnostics = array_merge($diagnostics, $result['diagnostics'] ?? []);
                $omnibusProductIds = $this->mergeProductIdLists($omnibusProductIds, $result['omnibus_product_ids']);
            } catch (\Exception $e) {
                $errors++;
                $diagnostics[] = [
                    'product_id' => $product['product_id'],
                    'variant_id' => $product['variant_id'] ?? null,
                    'sku' => $product['sku'] ?? null,
                    'error' => $e->getMessage(),
                ];
                error_log("Error syncing product {$product['product_id']}: " . $e->getMessage());
            }
        }

        return ['synced' => $synced, 'cleaned' => $cleaned, 'errors' => $errors, 'diagnostics' => $diagnostics, 'omnibus_product_ids' => $omnibusProductIds];
    }

    /**
     * Sinhronizuje jedan proizvod na osnovu njegovog ID-a.
     * Koristi se primarno za Webhook-ove.
     */
    public function syncProduct($productId) {
        // 1. Dohvati podatke o proizvodu i svim njegovim varijantama iz keša
        $items = $this->db->fetchAll("SELECT * FROM products_cache WHERE product_id = ? AND store_hash = ?", [$productId, $this->storeHash]);
        
        if (empty($items)) {
            return ['synced' => 0, 'errors' => 1, 'message' => 'Product not found in cache'];
        }

        // 2. Pozovi postojeću logiku za obradu (kao niz od 1 elementa)
        // Ovo će automatski naći najbolju promociju ili ukloniti postojeću
        return $this->processProductsBatch($items);
    }

    private function fetchPromotionProductForCorrection(int $productId, ?int $variantId, int $promotionId): ?array {
        $items = $this->fetchPromotionProductsWithCachePrice(
            "pp.store_hash = ?
             AND pp.product_id = ?
             AND pp.variant_id <=> ?
             AND pp.promotion_id = ?",
            [$this->storeHash, $productId, $variantId, $promotionId],
            "LIMIT 1"
        );

        return $items[0] ?? null;
    }

    private function findReplacementPromotionCandidate(array $item, int $excludedPromotionId): ?array {
        $activePromotions = $this->promotionModel->findActive();

        return $this->calculateBestPromotionCandidate(
            $item,
            $activePromotions,
            [$excludedPromotionId => true]
        );
    }

    private function correctOwnedItem(array $item, ?array $replacement, ?int $correctionId, string $reason): array {
        $target = $replacement ?? $item;
        $check = $this->ownership->prepare($target, $replacement === null, false, true);
        if (!in_array($check['action'], ['write','unchanged'], true)) {
            throw new \RuntimeException('Correction requires price conflict or ownership review.');
        }
        $target['previous_sale_price'] = $check['previous_sale_price'];
        $target['restore_price'] = $check['target'];
        if ($check['action'] === 'write') {
            [$parents,$variants] = $replacement ? $this->buildPriceUpdateBatches([$target]) : $this->buildRestoreUpdates([$target]);
            $results = $parents ? $this->api->batchUpdateProducts($parents) : $this->updateVariantPrices($variants);
            if (!$this->filterPromotionsWithSuccessfulPriceUpdates([$target],$results)) {
                throw new \RuntimeException('Correction price update failed.');
            }
        }
        $pid = (int)$item['product_id'];
        if ($replacement) {
            $fields = $this->customFieldService->upsertCustomFields($this->buildUniquePromotionFieldUpdates([$target]),
                [], $this->getKnownPromotionFieldIds([$pid]));
            if (empty($fields[0]['success'])) {
                throw new \RuntimeException('Correction promotion field update failed.');
            }
            $target['custom_field_id'] = $fields[0]['custom_field_id'];
        } elseif ($this->getProductsWithoutActivePromotionEntries([$item], [(int)$item['id']])) {
            $fields = $this->customFieldService->batchRemovePromotionFields([$pid]);
            if (empty($fields[0]['success'])) {
                throw new \RuntimeException('Correction promotion field removal failed.');
            }
        }

        $at = $this->getDatabaseTimestamp();
        $target['applied_at'] = $target['last_seen_at'] = $at;
        $result = ['status' => $replacement ? 'replaced' : 'restored',
            'replacement_promotion_id' => $replacement['promotion_id'] ?? null,
            'replacement' => $replacement ? $target : null, 'restored_sale_price' => $target['restore_price'],
            'omnibus_product_ids' => [$pid]];
        $this->db->beginTransaction();
        try {
            $this->insertPromotionProductExclusion((int)$item['promotion_id'],$pid,$item['variant_id'],$correctionId,$reason);
            $this->recordRemovedPromotionItems((int)$item['promotion_id'],[$item],'voided_error');
            if ($replacement) {
                $this->batchSavePromotionProducts([$target],$at);
                $this->recordAppliedPromotionItems($this->buildPromotionArchiveMetaFromAppliedItems([$target]),[$target]);
            } else {
                $this->db->query('DELETE FROM promotion_products WHERE store_hash=? AND id=? AND promotion_id=?',
                    [$this->storeHash,$item['id'],$item['promotion_id']]);
            }
            $this->cacheService->updatePriceCacheDirectly([['product_id'=>$pid,'variant_id'=>$item['variant_id'],
                'sale_price'=>$check['target'],'recorded_at'=>$at]]);
            if ($correctionId) {
                $this->db->query("UPDATE promotion_application_corrections SET after_state=? WHERE store_hash=? AND id=? AND status='pending'",
                    [json_encode(['promotion_result'=>$result],JSON_THROW_ON_ERROR),$this->storeHash,$correctionId]);
            }
            $this->ownership->confirmed($target);
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function validateDiscountPercent($discountPercent): float {
        $normalized = is_string($discountPercent)
            ? str_replace(',', '.', trim($discountPercent))
            : $discountPercent;

        if (!is_numeric($normalized)) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.discount_numeric',
                [],
                'Discount must be a number.'
            ));
        }

        $value = (float)$normalized;
        if ($value <= 0 || $value >= 100) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.discount_range',
                [],
                'Discount must be greater than 0 and lower than 100.'
            ));
        }

        return round($value, 2);
    }

    private function buildPromotionPreviewRow(
        array $item,
        float $discountPercent,
        $referenceAt = null,
        bool $referenceAtResolved = false,
        bool $skipOmnibusRevalidation = false
    ): ?array {
        if (empty($item['price']) || (float)$item['price'] <= 0) {
            return null;
        }

        $originalPrice = (float)$item['price'];
        $promoPrice = $this->calculatePromoPrice($originalPrice, $discountPercent);
        $savings = $originalPrice - $promoPrice;
        $omnibusReferenceAt = $referenceAtResolved
            ? $this->normalizeReferenceAt($referenceAt)
            : $this->resolvePreviewOmnibusReferenceAt($referenceAt);
        $omnibus = $this->validatePromotionPriceAgainstOmnibus(
            $item,
            $promoPrice,
            $omnibusReferenceAt
        );
        if ($skipOmnibusRevalidation) {
            $omnibus = $this->markOmnibusRevalidationSkipped($omnibus);
        }
        $validation = $this->applyCostPriceGuard($omnibus, $item, $promoPrice);

        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'sku' => $item['sku'],
            'product_id' => (int)$item['product_id'],
            'variant_id' => isset($item['variant_id']) ? (int)$item['variant_id'] : null,
            'original_price' => $originalPrice,
            'promo_price' => $promoPrice,
            'savings' => $savings,
            'savings_percent' => $discountPercent,
            'inventory' => $item['inventory_level'],
            'brand' => $item['brand_name'],
            'is_visible' => $item['is_visible'],
        ] + $validation;
    }

    private function buildPromotionCandidate(array $product, array $promotion): ?array {
        if (empty($product['price']) || (float)$product['price'] <= 0) {
            return null;
        }

        $promotionFilters = $promotion['filters'] ?? [];
        $product[self::COST_PRICE_BLOCK_ENABLED_ITEM_FLAG] = $this->isCostPriceBlockingEnabledFromFilters($promotionFilters);
        $manualUnblockedItems = $this->getManualUnblockedItemKeysFromFilters($promotionFilters);
        $product[self::MANUAL_UNBLOCK_ITEM_FLAG] = $this->isItemManuallyUnblocked($product, $manualUnblockedItems);

        try {
            $discount = $this->validateDiscountPercent($promotion['discount_percent'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        $originalPrice = (float)$product['price'];
        $promoPrice = $this->calculatePromoPrice($originalPrice, $discount);
        $existingPromotionProductCurrentForTerms = $this->isExistingPromotionProductCurrentForTerms($product, $promotion);
        $omnibusReferenceAt = $existingPromotionProductCurrentForTerms
            ? $this->resolvePromotionOmnibusReferenceAt($promotion)
            : $this->resolveNewPromotionProductOmnibusReferenceAt($promotion);
        $omnibus = $this->validatePromotionPriceAgainstOmnibus(
            $product,
            $promoPrice,
            $omnibusReferenceAt
        );
        if (
            empty($omnibus['will_apply'])
            && $existingPromotionProductCurrentForTerms
        ) {
            $omnibus = $this->markOmnibusRevalidationSkipped($omnibus);
        }
        $validation = $this->applyCostPriceGuard($omnibus, $product, $promoPrice);

        return [
            'id' => $promotion['id'],
            'promotion_id' => $promotion['id'],
            'promotion_name' => $promotion['name'],
            'custom_field_value' => $promotion['custom_field_value'] ?? $promotion['name'],
            'product_name' => $product['name'],
            'product_id' => $product['product_id'],
            'variant_id' => $product['variant_id'] ?? null,
            'original_price' => $originalPrice,
            'discount_percent' => $discount,
            'promo_price' => $promoPrice,
            'priority' => $promotion['priority']
        ] + $validation;
    }

    private function calculateBestPromotionCandidate($product, $activePromotions, array $temporaryExcludedPromotionIds = []) {
        $bestCandidate = null;

        foreach ($activePromotions as $promo) {
            $promotionId = (int)($promo['id'] ?? $promo['promotion_id'] ?? 0);
            if ($promotionId > 0 && isset($temporaryExcludedPromotionIds[$promotionId])) {
                continue;
            }

            if ($promotionId > 0 && $this->isPromotionProductExcluded($promotionId, $product)) {
                continue;
            }

            $filters = is_array($promo['filters'] ?? null)
                ? $promo['filters']
                : (json_decode($promo['filters'] ?? '[]', true) ?: []);
            if (!$this->productMatchesFilters($product, $filters)) {
                continue;
            }

            $candidate = $this->buildPromotionCandidate($product, $promo);
            if (!$candidate || empty($candidate['will_apply'])) {
                continue;
            }

            if (!$bestCandidate || $this->isBetterPromotion($candidate, $bestCandidate)) {
                $bestCandidate = $candidate;
            }
        }

        return $bestCandidate;
    }

    private function validatePromotionPriceAgainstOmnibus(array $item, float $promoPrice, $referenceAt = null): array {
        $base = [
            'lowest_price_30d' => null,
            'rolling_lowest_price_30d' => null,
            'omnibus_reference_price' => null,
            'omnibus_reference_at' => null,
            'omnibus_valid' => true,
            'will_apply' => true,
            'omnibus_status' => 'disabled',
            'omnibus_warning' => $this->translateMessage(
                'promotions.preview.omnibus_disabled',
                [],
                'Omnibus tracker is disabled; 30-day lowest price was not checked.'
            ),
            'omnibus_invalid_reason' => null,
        ];

        if (!$this->isOmnibusEnabled()) {
            return $base;
        }

        $effectiveReferenceAt = $this->normalizeReferenceAt($referenceAt);
        $dto = $this->omnibusPricingService->getDisplayData(
            $this->storeHash,
            (int)$item['product_id'],
            isset($item['variant_id']) ? (int)$item['variant_id'] : null,
            $this->getStoreCurrency(),
            $promoPrice,
            $effectiveReferenceAt
        );

        $rollingLowest = isset($dto['rolling_lowest_price_last_30_days']) && $dto['rolling_lowest_price_last_30_days'] !== null
            ? (float)$dto['rolling_lowest_price_last_30_days']
            : null;

        $referencePrice = $dto['candidate_omnibus_reference_price']
            ?? $dto['omnibus_reference_price']
            ?? null;
        $usedFallbackReference = false;
        if ($referencePrice === null) {
            $fallbackReferencePrice = $this->getBasePriceOmnibusReference($item, $promoPrice);
            if ($fallbackReferencePrice !== null) {
                $referencePrice = $fallbackReferencePrice;
                $usedFallbackReference = true;
            }
        }

        $displayLowestPrice = $rollingLowest ?? ($referencePrice !== null ? (float)$referencePrice : null);
        $validationReferencePrice = $referencePrice !== null ? (float)$referencePrice : null;
        if (
            $rollingLowest !== null
            && (
                $validationReferencePrice === null
                || $usedFallbackReference
                || $this->isPromoPriceBelowOmnibusReference($rollingLowest, $validationReferencePrice)
            )
        ) {
            $validationReferencePrice = $rollingLowest;
        }

        $reason = $dto['invalid_reduction_reason'] ?? null;
        if ($validationReferencePrice === null) {
            $reason = 'missing_reference_price';
            $isValid = false;
        } else {
            $isValid = $this->isPromoPriceBelowOmnibusReference($promoPrice, $validationReferencePrice);
            if ($isValid) {
                $reason = null;
            } elseif ($rollingLowest !== null && !$this->isPromoPriceBelowOmnibusReference($promoPrice, $rollingLowest)) {
                $reason = 'not_below_30_day_lowest';
            } elseif (empty($dto['is_price_drop_candidate']) && !$usedFallbackReference) {
                $reason = $reason ?: 'not_price_reduction';
            } else {
                $reason = $reason ?: 'not_below_30_day_lowest';
            }
        }

        if ($displayLowestPrice === null && $usedFallbackReference) {
            $displayLowestPrice = (float)$referencePrice;
        }

        return [
            'lowest_price_30d' => $displayLowestPrice,
            'rolling_lowest_price_30d' => $rollingLowest ?? ($usedFallbackReference ? (float)$referencePrice : null),
            'omnibus_reference_price' => $validationReferencePrice,
            'omnibus_reference_at' => $effectiveReferenceAt->format('Y-m-d H:i:s'),
            'omnibus_valid' => $isValid,
            'will_apply' => $isValid,
            'omnibus_status' => $isValid ? 'valid' : 'invalid',
            'omnibus_warning' => $isValid ? '' : $this->buildOmnibusWarning($reason, $validationReferencePrice),
            'omnibus_invalid_reason' => $isValid ? null : $reason,
        ];
    }

    private function getBasePriceOmnibusReference(array $item, float $promoPrice): ?float {
        if (!isset($item['price']) || $item['price'] === null || $item['price'] === '') {
            return null;
        }

        $basePrice = (float)$item['price'];
        if ($basePrice <= 0 || !$this->isPromoPriceBelowOmnibusReference($promoPrice, $basePrice)) {
            return null;
        }

        return $basePrice;
    }

    private function isPromoPriceBelowOmnibusReference(float $promoPrice, float $referencePrice): bool {
        return round($promoPrice, 4) < round($referencePrice, 4);
    }

    private function calculatePromoPrice(float $originalPrice, float $discountPercent): float {
        return round($originalPrice * (1 - $discountPercent / 100), 2);
    }

    private function buildOmnibusWarning(?string $reason, $referencePrice = null): string {
        if ($reason === 'not_below_30_day_lowest') {
            return $this->translateMessage(
                'promotions.preview.omnibus_not_below_reference',
                [],
                'Promo price must be lower than the lowest price in the previous 30 days.'
            );
        }

        if ($reason === 'not_price_reduction') {
            return $this->translateMessage(
                'promotions.preview.omnibus_not_reduction',
                [],
                'This is not a price reduction.'
            );
        }

        return $this->translateMessage(
            'promotions.preview.omnibus_missing_reference',
            [],
            'Missing complete 30-day price history for this item.'
        );
    }

    private function applyCostPriceGuard(array $validation, array $item, float $promoPrice): array {
        $costPriceBlockEnabled = !empty($item[self::COST_PRICE_BLOCK_ENABLED_ITEM_FLAG]);
        $costPrice = $this->normalizeCostPrice($item['cost_price'] ?? null);
        $taxRate = $this->normalizeTaxRate($item['tax_rate'] ?? null);
        $promoPriceExTax = $this->calculateTaxExclusivePrice($promoPrice, $taxRate);
        $promoMargin = $costPrice !== null && $promoPriceExTax !== null
            ? round($promoPriceExTax - $costPrice, 2)
            : null;
        $isValid = $costPrice === null
            || $promoPriceExTax === null
            || !$this->isPromoPriceBelowCostPrice($promoPriceExTax, $costPrice);
        $isManualOverride = $costPriceBlockEnabled && !$isValid && !empty($item[self::MANUAL_UNBLOCK_ITEM_FLAG]);

        $validation['cost_price'] = $costPrice;
        $validation['cost_price_block_enabled'] = $costPriceBlockEnabled;
        $validation['tax_rate'] = $taxRate;
        $validation['promo_price_ex_tax'] = $promoPriceExTax;
        $validation['promo_margin'] = $promoMargin;
        $validation['margin_after_discount'] = $promoMargin;
        $validation['cost_price_valid'] = $isValid;
        $validation['cost_price_overridden'] = $isManualOverride;
        $validation['cost_price_status'] = $costPrice === null || $promoPriceExTax === null
            ? 'not_checked'
            : (!$costPriceBlockEnabled ? 'disabled' : ($isValid ? 'valid' : ($isManualOverride ? 'overridden' : 'invalid')));
        $validation['cost_price_warning'] = '';

        if ($costPriceBlockEnabled && !$isValid && !$isManualOverride) {
            $validation['will_apply'] = false;
        }

        if ($costPriceBlockEnabled && !$isValid) {
            $validation['cost_price_warning'] = $this->buildCostPriceWarning($costPrice);
        }

        $warnings = [];
        $invalidReasons = [];

        if (empty($validation['omnibus_valid']) && !empty($validation['omnibus_warning'])) {
            $warnings[] = $validation['omnibus_warning'];
        }
        if (!empty($validation['omnibus_invalid_reason'])) {
            $invalidReasons[] = $validation['omnibus_invalid_reason'];
        }

        if ($costPriceBlockEnabled && !$isValid) {
            $warnings[] = $validation['cost_price_warning'];
            if ($isManualOverride) {
                $warnings[] = $this->buildCostPriceOverrideWarning();
            } else {
                $invalidReasons[] = 'below_cost_price';
            }
        }

        $validation['promotion_warning'] = implode(' ', array_values(array_unique(array_filter($warnings))));
        $validation['promotion_invalid_reasons'] = array_values(array_unique(array_filter($invalidReasons)));
        $validation['promotion_invalid_reason'] = $validation['promotion_invalid_reasons'][0] ?? null;

        return $validation;
    }

    private function normalizeCostPrice($costPrice): ?float {
        if ($costPrice === null || $costPrice === '' || !is_numeric($costPrice)) {
            return null;
        }

        $costPrice = (float)$costPrice;
        return $costPrice > 0 ? $costPrice : null;
    }

    private function normalizeTaxRate($taxRate): ?float {
        if ($taxRate === null || $taxRate === '' || !is_numeric($taxRate)) {
            return null;
        }

        $taxRate = (float)$taxRate;
        return $taxRate >= 0 ? $taxRate : null;
    }

    private function calculateTaxExclusivePrice(float $taxInclusivePrice, ?float $taxRate): ?float {
        if ($taxRate === null) {
            return null;
        }

        return round($taxInclusivePrice / (1 + ($taxRate / 100)), 4);
    }

    private function isPromoPriceBelowCostPrice(float $promoPrice, float $costPrice): bool {
        return round($promoPrice, 4) < round($costPrice, 4);
    }

    private function buildCostPriceWarning(float $costPrice): string {
        return $this->translateMessage(
            'promotions.preview.below_cost_price',
            ['cost_price' => number_format($costPrice, 2, '.', '')],
            'Promo price is below cost price.'
        );
    }

    private function buildCostPriceOverrideWarning(): string {
        return $this->translateMessage(
            'promotions.preview.cost_price_override_warning',
            [],
            'Cost price block was manually removed by an administrator.'
        );
    }

    private function isOmnibusEnabled(): bool {
        $config = $this->getStoreConfig();
        return !empty($config['enable_omnibus']);
    }

    private function getStoreCurrency(): string {
        $config = $this->getStoreConfig();
        return $config['currency'] ?? 'USD';
    }

    private function getStoreConfig(): array {
        if ($this->storeConfigCache !== null) {
            return $this->storeConfigCache;
        }

        $config = $this->db->fetchOne(
            "SELECT enable_omnibus, currency FROM bigcommerce_stores WHERE store_hash = ?",
            [$this->storeHash]
        );

        $this->storeConfigCache = is_array($config) ? $config : [
            'enable_omnibus' => 0,
            'currency' => 'USD',
        ];

        return $this->storeConfigCache;
    }

    private function normalizeReferenceAt($referenceAt): \DateTimeImmutable {
        if ($referenceAt instanceof \DateTimeImmutable) {
            return $referenceAt;
        }

        if ($referenceAt instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($referenceAt);
        }

        $value = trim((string)$referenceAt);
        if ($value === '') {
            $value = 'now';
        }

        return new \DateTimeImmutable($value);
    }

    private function resolvePreviewOmnibusReferenceAt($submittedStartDate, array $context = []): \DateTimeImmutable {
        $existingPromotion = $this->findPromotionFromPreviewContext($context);

        if (
            is_array($existingPromotion)
            && (
                !$this->hasPromotionOmnibusTermsChanged($existingPromotion, $context)
                || $this->isActiveDiscountCorrectionPreview($existingPromotion, $context)
            )
        ) {
            return $this->resolvePromotionOmnibusReferenceAt($existingPromotion);
        }

        return $this->latestDateTime([
            $this->normalizeReferenceAt($submittedStartDate),
            $this->normalizeReferenceAt('now'),
        ]);
    }

    private function resolvePromotionOmnibusReferenceAt(array $promotion): \DateTimeImmutable {
        $dates = [];
        foreach (['start_date', 'created_at', 'omnibus_terms_updated_at'] as $field) {
            $dateTime = $this->normalizeOptionalReferenceAt($promotion[$field] ?? null);
            if ($dateTime !== null) {
                $dates[] = $dateTime;
            }
        }

        if (empty($dates)) {
            return $this->normalizeReferenceAt('now');
        }

        return $this->latestDateTime($dates);
    }

    private function resolveNewPreviewItemOmnibusReferenceAt($submittedStartDate): \DateTimeImmutable {
        return $this->latestDateTime([
            $this->normalizeReferenceAt($submittedStartDate),
            $this->normalizeReferenceAt('now'),
        ]);
    }

    private function resolveNewPromotionProductOmnibusReferenceAt(array $promotion): \DateTimeImmutable {
        return $this->latestDateTime([
            $this->resolvePromotionOmnibusReferenceAt($promotion),
            $this->normalizeReferenceAt('now'),
        ]);
    }

    private function normalizeOptionalReferenceAt($referenceAt): ?\DateTimeImmutable {
        if ($referenceAt instanceof \DateTimeImmutable) {
            return $referenceAt;
        }

        if ($referenceAt instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($referenceAt);
        }

        $value = trim((string)$referenceAt);
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function latestDateTime(array $dateTimes): \DateTimeImmutable {
        $latest = null;

        foreach ($dateTimes as $dateTime) {
            if (!$dateTime instanceof \DateTimeImmutable) {
                continue;
            }

            if ($latest === null || $dateTime > $latest) {
                $latest = $dateTime;
            }
        }

        return $latest ?? $this->normalizeReferenceAt('now');
    }

    private function isEditPreviewWithoutOmnibusTermChanges(array $context): bool {
        $existingPromotion = $this->findPromotionFromPreviewContext($context);

        return is_array($existingPromotion)
            && (
                !$this->hasPromotionOmnibusTermsChanged($existingPromotion, $context)
                || $this->isActiveDiscountCorrectionPreview($existingPromotion, $context)
            );
    }

    private function findPromotionFromPreviewContext(array $context): ?array {
        $promotionId = isset($context['promotion_id']) ? (int)$context['promotion_id'] : 0;
        if ($promotionId <= 0 || !$this->promotionModel) {
            return null;
        }

        $promotion = $this->promotionModel->findById($promotionId);
        return is_array($promotion) ? $promotion : null;
    }

    private function markOmnibusRevalidationSkipped(array $omnibus): array {
        $omnibus['omnibus_valid'] = true;
        $omnibus['will_apply'] = true;
        $omnibus['omnibus_status'] = 'valid';
        $omnibus['omnibus_warning'] = '';
        $omnibus['omnibus_invalid_reason'] = null;
        $omnibus['omnibus_revalidation_skipped'] = true;

        return $omnibus;
    }

    private function isActiveDiscountCorrectionPreview(array $existingPromotion, array $context): bool {
        if (($context['change_type'] ?? self::CHANGE_TYPE_STANDARD) !== self::CHANGE_TYPE_ACTIVE_DISCOUNT_CORRECTION) {
            return false;
        }

        if ((string)($existingPromotion['status'] ?? '') !== 'active') {
            return false;
        }

        if (
            $this->normalizeDateForComparison($existingPromotion['start_date'] ?? null)
                !== $this->normalizeDateForComparison($context['start_date'] ?? null)
        ) {
            return false;
        }

        return round((float)($existingPromotion['discount_percent'] ?? 0), 2)
            !== round((float)($context['discount_percent'] ?? 0), 2);
    }

    private function isExistingPromotionProductCurrentForTerms(array $product, array $promotion): bool {
        if (empty($promotion['id']) || empty($product['product_id']) || !$this->db) {
            return false;
        }

        $key = $this->getPromotionItemKey($product['product_id'],$product['variant_id'] ?? null);
        $row = $this->termsOwners !== null ? ($this->termsOwners[$promotion['id']][$key] ?? null) : $this->db->fetchOne(
            "SELECT synced_at
             FROM promotion_products
             WHERE store_hash = ?
               AND promotion_id = ?
               AND product_id = ?
               AND variant_id <=> ?
             ORDER BY synced_at DESC, id DESC
             LIMIT 1",
            [
                $this->storeHash,
                (int)$promotion['id'],
                (int)$product['product_id'],
                isset($product['variant_id']) && $product['variant_id'] !== null
                    ? (int)$product['variant_id']
                    : null,
            ]
        );

        if (empty($row['synced_at'])) {
            return false;
        }

        $syncedAt = $this->normalizeOptionalReferenceAt($row['synced_at']);
        $termsChangedAt = $this->resolvePromotionTermsChangedAt($promotion);

        return $syncedAt !== null
            && $termsChangedAt !== null
            && $syncedAt >= $termsChangedAt;
    }

    private function resolvePromotionTermsChangedAt(array $promotion): ?\DateTimeImmutable {
        foreach (['omnibus_terms_updated_at', 'created_at', 'updated_at', 'start_date'] as $field) {
            $dateTime = $this->normalizeOptionalReferenceAt($promotion[$field] ?? null);
            if ($dateTime !== null) {
                return $dateTime;
            }
        }

        return null;
    }

    private function validateActiveDiscountCorrectionRequest(
        array $existingPromotion,
        array $newData,
        array $context
    ): ?array {
        $changeType = trim((string)($context['change_type'] ?? self::CHANGE_TYPE_STANDARD));
        if ($changeType === '') {
            $changeType = self::CHANGE_TYPE_STANDARD;
        }

        if ($changeType === self::CHANGE_TYPE_STANDARD) {
            return null;
        }

        if ($changeType !== self::CHANGE_TYPE_ACTIVE_DISCOUNT_CORRECTION) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_mode_invalid',
                [],
                'Unsupported promotion correction mode.'
            ));
        }

        if (
            (string)($existingPromotion['status'] ?? '') !== 'active'
            || (string)($newData['status'] ?? '') !== 'active'
        ) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_active_only',
                [],
                'An Omnibus correction can be used only while the promotion remains active.'
            ));
        }

        $oldDiscount = round((float)($existingPromotion['discount_percent'] ?? 0), 2);
        $newDiscount = round((float)($newData['discount_percent'] ?? 0), 2);
        if ($oldDiscount === $newDiscount) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_discount_required',
                [],
                'An Omnibus correction requires a discount percentage change.'
            ));
        }

        if (
            $this->normalizeDateForComparison($existingPromotion['start_date'] ?? null)
                !== $this->normalizeDateForComparison($newData['start_date'] ?? null)
        ) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_start_date_unchanged',
                [],
                'The promotion start date cannot be changed during an Omnibus correction.'
            ));
        }

        $reason = trim((string)($context['correction_reason'] ?? ''));
        if ($reason === '') {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_reason_required',
                [],
                'Enter the reason for the Omnibus correction.'
            ));
        }

        $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
        if ($reasonLength > self::MAX_CORRECTION_REASON_LENGTH) {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_reason_too_long',
                ['max' => self::MAX_CORRECTION_REASON_LENGTH],
                'The Omnibus correction reason may contain at most {max} characters.'
            ));
        }

        $actorSource = trim((string)($context['actor_source'] ?? ''));
        $actorUserId = trim((string)($context['actor_user_id'] ?? ''));
        if ($actorSource !== 'bigcommerce' || $actorUserId === '') {
            throw new \InvalidArgumentException($this->translateMessage(
                'promotions.validation.correction_bigcommerce_identity_required',
                [],
                'Open the app from the BigCommerce control panel before using an Omnibus correction.'
            ));
        }

        return [
            'change_type' => self::CHANGE_TYPE_ACTIVE_DISCOUNT_CORRECTION,
            'reason' => $reason,
            'actor_source' => $actorSource,
            'actor_user_id' => $actorUserId,
            'actor_email' => trim((string)($context['actor_email'] ?? '')) ?: null,
            'actor_is_owner' => !empty($context['actor_is_owner']) ? 1 : 0,
            'old_discount_percent' => $oldDiscount,
            'new_discount_percent' => $newDiscount,
            'lifecycle_reference_at' => $this->resolvePromotionOmnibusReferenceAt($existingPromotion)->format('Y-m-d H:i:s'),
            'old_terms' => $this->encodePromotionRevisionTerms($existingPromotion),
            'new_terms' => $this->encodePromotionRevisionTerms($newData),
        ];
    }

    private function savePromotionUpdateWithCorrectionRevision(
        $promotionId,
        array $data,
        array $revision
    ) {
        $transactionStarted = $this->db->beginTransaction();

        try {
            if ($this->isOmnibusEnabled()) {
                $this->lockExistingPromotionProductOmnibusReferences(
                    (int)$promotionId,
                    $revision['lifecycle_reference_at']
                );
            }
            $result = $this->promotionModel->update($promotionId, $data);
            $this->db->query(
                "INSERT INTO promotion_revisions
                 (store_hash, promotion_id, change_type, reason, actor_source, actor_user_id, actor_email, actor_is_owner, old_discount_percent, new_discount_percent, old_terms, new_terms, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $this->storeHash,
                    (int)$promotionId,
                    $revision['change_type'],
                    $revision['reason'],
                    $revision['actor_source'],
                    $revision['actor_user_id'],
                    $revision['actor_email'],
                    $revision['actor_is_owner'],
                    $revision['old_discount_percent'],
                    $revision['new_discount_percent'],
                    $revision['old_terms'],
                    $revision['new_terms'],
                ]
            );

            if ($transactionStarted) {
                $this->db->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $this->db->rollback();
            }

            throw $e;
        }
    }

    private function lockExistingPromotionProductOmnibusReferences(
        int $promotionId,
        string $fallbackReferenceAt
    ): void {
        $historyVariantSql = $this->priceHistoryHasVariantId()
            ? 'AND ph.variant_id <=> pp.variant_id'
            : 'AND pp.variant_id IS NULL';
        $this->db->query(
            "UPDATE promotion_products pp
             LEFT JOIN products_cache pc
                ON pc.store_hash COLLATE utf8mb4_unicode_ci = pp.store_hash COLLATE utf8mb4_unicode_ci
               AND pc.product_id = pp.product_id
               AND pc.variant_id <=> pp.variant_id
             SET pp.omnibus_reference_at = COALESCE(
                    pp.omnibus_reference_at,
                    (
                        SELECT MIN(ph.recorded_at)
                        FROM product_price_history ph
                        WHERE ph.store_hash COLLATE utf8mb4_unicode_ci = pp.store_hash COLLATE utf8mb4_unicode_ci
                          AND ph.product_id = pp.product_id
                          {$historyVariantSql}
                          AND ph.ignored_at IS NULL
                          AND ROUND(ph.price, 4) = ROUND(
                              CASE
                                  WHEN pc.sale_price IS NOT NULL AND pc.sale_price > 0 THEN pc.sale_price
                                  ELSE pc.price
                              END,
                              4
                          )
                          AND ph.recorded_at >= ?
                    ),
                    ?
                 ),
                 pp.first_applied_at = COALESCE(pp.first_applied_at, ?)
             WHERE pp.store_hash = ?
               AND pp.promotion_id = ?
               AND pp.omnibus_reference_at IS NULL",
            [
                $fallbackReferenceAt,
                $fallbackReferenceAt,
                $fallbackReferenceAt,
                $this->storeHash,
                $promotionId,
            ]
        );
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

    private function encodePromotionRevisionTerms(array $promotion): string {
        $terms = [];
        foreach (['discount_percent', 'start_date', 'end_date', 'priority', 'filters'] as $field) {
            if (array_key_exists($field, $promotion)) {
                $terms[$field] = $promotion[$field];
            }
        }

        return json_encode($terms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function hasPromotionOmnibusTermsChanged(array $existingPromotion, array $newData): bool {
        if (
            array_key_exists('discount_percent', $newData)
            && round((float)$existingPromotion['discount_percent'], 2) !== round((float)$newData['discount_percent'], 2)
        ) {
            return true;
        }

        if (
            array_key_exists('start_date', $newData)
            && $this->normalizeDateForComparison($existingPromotion['start_date'] ?? null)
                !== $this->normalizeDateForComparison($newData['start_date'] ?? null)
        ) {
            return true;
        }

        return false;
    }

    private function hasPromotionSyncRelevantChanges(array $existingPromotion, array $newData): bool {
        $existingStatus = (string)($existingPromotion['status'] ?? '');
        $newStatus = (string)($newData['status'] ?? $existingStatus);
        if ($existingStatus !== $newStatus) {
            return true;
        }

        if (
            array_key_exists('discount_percent', $newData)
            && round((float)$existingPromotion['discount_percent'], 2) !== round((float)$newData['discount_percent'], 2)
        ) {
            return true;
        }

        if (
            array_key_exists('priority', $newData)
            && (int)($existingPromotion['priority'] ?? 0) !== (int)$newData['priority']
        ) {
            return true;
        }

        foreach (['start_date', 'end_date'] as $field) {
            if (
                array_key_exists($field, $newData)
                && $this->normalizeDateForComparison($existingPromotion[$field] ?? null)
                    !== $this->normalizeDateForComparison($newData[$field] ?? null)
            ) {
                return true;
            }
        }

        if (
            array_key_exists('filters', $newData)
            && $this->normalizeFiltersForComparison($existingPromotion['filters'] ?? [])
                !== $this->normalizeFiltersForComparison($newData['filters'] ?? [])
        ) {
            return true;
        }

        if (
            array_key_exists('custom_field_value', $newData)
            && trim((string)($existingPromotion['custom_field_value'] ?? $existingPromotion['name'] ?? ''))
                !== trim((string)$newData['custom_field_value'])
        ) {
            return true;
        }

        return false;
    }

    private function normalizeDateForComparison($date): ?string {
        $dateTime = $this->normalizeOptionalReferenceAt($date);
        return $dateTime ? $dateTime->format('Y-m-d H:i') : null;
    }

    private function normalizeFiltersForComparison($filters, bool $includeSyncControls = true): string {
        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            $filters = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($filters)) {
            $filters = [];
        }

        if (!$includeSyncControls) {
            unset($filters[self::BLOCK_BELOW_COST_PRICE_FILTER_KEY]);
            unset($filters[self::MANUAL_UNBLOCK_FILTER_KEY]);
        }

        $normalized = $this->sortFilterValueForComparison($filters);
        return json_encode($normalized, JSON_UNESCAPED_UNICODE);
    }

    private function sortFilterValueForComparison($value) {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $normalizedList = array_map([$this, 'sortFilterValueForComparison'], $value);
            usort($normalizedList, static function ($left, $right): int {
                return strcmp(json_encode($left, JSON_UNESCAPED_UNICODE), json_encode($right, JSON_UNESCAPED_UNICODE));
            });

            return $normalizedList;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortFilterValueForComparison($item);
        }

        return $value;
    }

    private function translateMessage(string $key, array $replace, string $fallback): string {
        if (!function_exists('trans')) {
            return $fallback;
        }

        $message = \trans($key, $replace);
        return $message === $key ? $fallback : $message;
    }

    // --- NEDOSTAJUĆE METODE ---

    /**
     * Nalazi najbolju promociju za proizvod na osnovu prioriteta i popusta.
     */
    private function calculateBestPromotion($product, $activePromotions) {
        $bestPromo = null;

        foreach ($activePromotions as $promo) {
            $filters = json_decode($promo['filters'], true) ?: [];
            
            // Provera da li proizvod ispunjava uslove ove promocije
            if ($this->productMatchesFilters($product, $filters)) {
                if (!$bestPromo || $this->isBetterPromotion($promo, $bestPromo)) {
                    $bestPromo = $promo;
                }
            }
        }
        return $bestPromo;
    }

    /**
     * Proverava da li proizvod (PHP niz) odgovara filterima.
     * Ovo je PHP ekvivalent SQL WHERE klauzula iz ProductCacheService.
     */
    private function productMatchesFilters($product, $filters) {
        if (!is_array($filters)) {
            return true;
        }

        $excludeFilters = [];
        if (isset($filters['exclude']) && is_array($filters['exclude'])) {
            $excludeFilters = $filters['exclude'];
            unset($filters['exclude']);
        }

        foreach ($filters as $key => $value) {
            if ($key === 'exclude') {
                continue;
            }

            if (empty($value) && $value !== '0' && $value !== 0) continue;

            if (strpos($key, 'custom_field:') === 0) {
                $fieldName = $this->normalizeEscapedUnicodeString(substr($key, 13));
                $productFields = $product['custom_fields'] ?? [];
                if (is_string($productFields)) $productFields = json_decode($productFields, true);
                if (!is_array($productFields)) $productFields = [];

                $requiredValues = is_array($value) ? $value : [$value];
                $requiredValues = array_values(array_filter(array_map(function($item) {
                    return $this->normalizeEscapedUnicodeString((string)$item);
                }, $requiredValues), function($item) {
                    return $item !== '';
                }));

                $match = false;
                foreach ($productFields as $field) {
                    $normalizedFieldName = $this->normalizeEscapedUnicodeString((string)($field['name'] ?? ''));
                    $normalizedFieldValue = $this->normalizeEscapedUnicodeString((string)($field['value'] ?? ''));

                    if ($normalizedFieldName === $fieldName && in_array($normalizedFieldValue, $requiredValues, true)) {
                        $match = true;
                        break;
                    }
                }

                if (!$match) return false;
                continue;
            }

            switch ($key) {
                case 'brand_id':
                    $requiredBrands = is_array($value) ? $value : explode(',', (string)$value);
                    $requiredBrands = array_values(array_filter(array_map(function($brandId) {
                        return trim((string)$brandId);
                    }, $requiredBrands), function($brandId) {
                        return $brandId !== '';
                    }));

                    if (!in_array((string)($product['brand_id'] ?? ''), $requiredBrands, true)) return false;
                    break;
                case 'categories:in':
                    $productCats = $product['categories'] ?? [];
                    if (is_string($productCats)) $productCats = json_decode($productCats, true);
                    
                    $requiredCats = is_array($value) ? $value : explode(',', $value);
                    // Proveri presek nizova (da li ima bar jednu zajedničku kategoriju)
                    if (empty(array_intersect($productCats, $requiredCats))) return false;
                    break;
                case 'price:min':
                    if (!isset($product['price']) || $product['price'] === '' || (float)$product['price'] < (float)$value) return false;
                    break;
                case 'price:max':
                    if (!isset($product['price']) || $product['price'] === '' || (float)$product['price'] > (float)$value) return false;
                    break;
                case 'product_id':
                    if ((int)$product['product_id'] !== (int)$value) return false;
                    break;
                case 'inventory_level:min':
                    if ((int)$product['inventory_level'] < (int)$value) return false;
                    break;
                case 'is_visible':
                    if ((bool)$product['is_visible'] !== (bool)$value) return false;
                    break;
                case 'is_featured':
                    if ((bool)$product['is_featured'] !== (bool)$value) return false;
                    break;
                case 'sku':
                    if ($product['sku'] !== $value) return false;
                    break;
                case 'sku:in':
                    $skuArray = is_array($value) ? $value : explode(',', $value);
                    // Očistimo potencijalne navodnike i prazna mesta kao u SQL-u
                    $skuArray = array_map(function($item) {
                        return trim($item, " '\"");
                    }, $skuArray);
                    // Ako SKU proizvoda nije u nizu traženih SKU-ova, ne poklapa se
                    if (!in_array(trim($product['sku'] ?? ''), $skuArray)) return false;
                    break;
                case 'name:like':
                    if (stripos($product['name'] ?? '', (string)$value) === false) return false;
                    break;
            }
        }

        if ($this->productMatchesAnyExcludeFilter($product, $excludeFilters)) {
            return false;
        }

        return true;
    }

    private function productMatchesAnyExcludeFilter($product, array $excludeFilters): bool {
        foreach ($excludeFilters as $key => $value) {
            if ($key === 'exclude' || (empty($value) && $value !== '0' && $value !== 0)) {
                continue;
            }

            if ($this->productMatchesFilters($product, [$key => $value])) {
                return true;
            }
        }

        return false;
    }

    private function normalizeEscapedUnicodeString(string $value): string {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($matches) {
            return json_decode('"\\u' . $matches[1] . '"');
        }, trim($value));
    }

    /**
     * Pojedinačna primena promocije (koristi se kao fallback ili unutar petlje ako nije batch).
     * Ipak, preporuka je koristiti batch gde god je moguće.
     */
    private function updateVariantPrices(array $updates): array {
        $results = $this->api->batchUpdateVariants($updates);
        $missing = array_filter($results, static function ($result) {
            return ($result['reason'] ?? null) === 'variant_not_found';
        });
        if ($missing) {
            $queue = $this->verificationQueue ??= new QueueService($this->storeHash);
            foreach ($this->extractUniqueProductIds($missing) as $productId) {
                try {
                    $queue->queueVariantVerification($productId);
                } catch (\Exception $e) {
                    foreach ($results as &$result) {
                        if (($result['reason'] ?? '') === 'variant_not_found' && (int)$result['product_id'] === $productId) {
                            $result['error'] .= '; cache verification could not be queued: ' . $e->getMessage();
                        }
                    }
                    unset($result);
                }
            }
        }
        return $results;
    }

    private function filterPromotionsWithSuccessfulPriceUpdates(array $promotions, array $priceResults): array {
        $successfulKeys = $this->getSuccessfulPriceUpdateKeys($priceResults);
        if (empty($successfulKeys)) {
            return [];
        }

        return array_filter($promotions, function (array $promo) use ($successfulKeys): bool {
            $key = $this->getPromotionItemKey($promo['product_id'], $promo['variant_id'] ?? null);
            return isset($successfulKeys[$key]);
        });
    }

    public static function formatPriceDiagnostics(array $diagnostics, int $limit = 25): string {
        if (empty($diagnostics)) {
            return '';
        }

        $lines = [];
        foreach (array_slice($diagnostics, 0, $limit) as $item) {
            $label = (!empty($item['sku']) ? $item['sku'] . ' ' : '')
                . 'product_id=' . ($item['product_id'] ?? 'n/a');
            $variantId = $item['variant_id'] ?? null;
            if ($variantId !== null && $variantId !== '') {
                $label .= ' variant_id=' . (string)$variantId;
            }

            $promoPrice = array_key_exists('promo_price', $item) && $item['promo_price'] !== null
                ? ' promo_price=' . (string)$item['promo_price']
                : '';
            $error = trim((string)($item['error'] ?? 'Unknown error'));

            $lines[] = "- {$label}{$promoPrice}: {$error}";
        }

        $remaining = count($diagnostics) - count($lines);
        if ($remaining > 0) {
            $lines[] = "- ... {$remaining} more diagnostic item(s)";
        }

        return "Diagnostics:\n" . implode("\n", $lines);
    }

    private function buildPriceUpdateFailureDiagnostics(
        array $promotions,
        array $priceResults,
        array $sourceItems = []
    ): array {
        $successfulKeys = $this->getSuccessfulPriceUpdateKeys($priceResults);
        $failedByKey = [];

        foreach ($priceResults as $result) {
            if (!empty($result['success'])) {
                continue;
            }

            $productId = $result['product_id'] ?? ($result['id'] ?? null);
            $variantId = $result['variant_id'] ?? null;
            if (($productId === null || $productId === '') && ($variantId === null || $variantId === '')) {
                continue;
            }

            $key = $this->getPromotionItemKey(
                (int)($productId ?: 0),
                $variantId !== null && $variantId !== '' ? (int)$variantId : null
            );
            $failedByKey[$key] = $result;
        }

        $sourceByKey = [];
        foreach ($sourceItems as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $variantId = isset($item['variant_id']) && $item['variant_id'] !== null && $item['variant_id'] !== ''
                ? (int)$item['variant_id']
                : null;
            $sourceByKey[$this->getPromotionItemKey($productId, $variantId)] = $item;
        }

        $diagnostics = [];
        foreach ($promotions as $promo) {
            $productId = (int)($promo['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $variantId = isset($promo['variant_id']) && $promo['variant_id'] !== null && $promo['variant_id'] !== ''
                ? (int)$promo['variant_id']
                : null;
            $key = $this->getPromotionItemKey($productId, $variantId);
            if (isset($successfulKeys[$key])) {
                continue;
            }

            $source = $sourceByKey[$key] ?? [];
            $diagnostics[] = [
                'type' => $failedByKey[$key]['reason'] ?? 'price_update_failed',
                'promotion_id' => (int)($promo['promotion_id'] ?? 0),
                'product_id' => $productId,
                'variant_id' => $variantId,
                'sku' => $source['sku'] ?? $promo['sku'] ?? null,
                'product_name' => $promo['product_name'] ?? ($source['name'] ?? null),
                'promo_price' => $promo['promo_price'] ?? null,
                'error' => $failedByKey[$key]['error'] ?? 'No successful price update response returned',
            ];
        }

        return $diagnostics;
    }

    private function hasSuccessfulPriceUpdateForItem($productId, $variantId, array $priceResults): bool {
        $key = $this->getPromotionItemKey($productId, $variantId);
        $successfulKeys = $this->getSuccessfulPriceUpdateKeys($priceResults);

        return isset($successfulKeys[$key]);
    }

    private function getSuccessfulPriceUpdateKeys(array $priceResults): array {
        $keys = [];

        foreach ($priceResults as $result) {
            if (empty($result['success'])) {
                continue;
            }

            $variantId = $result['variant_id'] ?? null;
            $productId = $result['product_id'] ?? null;

            if (($productId === null || $productId === '') && ($variantId === null || $variantId === '')) {
                $productId = $result['id'] ?? null;
            }

            if ($variantId !== null && $variantId !== '') {
                $keys[$this->getPromotionItemKey((int)($productId ?: 0), (int)$variantId)] = true;
                continue;
            }

            if ($productId !== null && $productId !== '') {
                $keys[$this->getPromotionItemKey((int)$productId, null)] = true;
            }
        }

        return $keys;
    }

    private function buildUniquePromotionFieldUpdates(array $promotions): array {
        $updates = [];

        foreach ($promotions as $promo) {
            $productId = (int)($promo['product_id'] ?? 0);
            if ($productId <= 0 || isset($updates[$productId])) {
                continue;
            }

            $updates[$productId] = [
                'product_id' => $productId,
                'field_value' => $promo['custom_field_value'] ?? $promo['promotion_name']
            ];
        }

        return array_values($updates);
    }

    private function extractUniqueProductIds(array $items): array {
        $productIds = [];

        foreach ($items as $item) {
            $productId = is_array($item) ? ($item['product_id'] ?? null) : $item;
            if (!is_numeric($productId)) {
                continue;
            }

            $productId = (int)$productId;
            if ($productId > 0) {
                $productIds[$productId] = true;
            }
        }

        $productIds = array_keys($productIds);
        sort($productIds, SORT_NUMERIC);
        return $productIds;
    }

    private function mergeProductIdLists(array ...$lists): array {
        $merged = [];

        foreach ($lists as $list) {
            foreach ($this->extractUniqueProductIds($list) as $productId) {
                $merged[$productId] = true;
            }
        }

        $productIds = array_keys($merged);
        sort($productIds, SORT_NUMERIC);
        return $productIds;
    }

    private function buildPromotionCachePriceUpdates(array $promotions, ?string $recordedAt = null): array {
        $updates = [];

        foreach ($promotions as $promo) {
            $update = [
                'product_id' => $promo['product_id'],
                'variant_id' => $promo['variant_id'] ?? null,
                'sale_price' => $promo['promo_price']
            ];

            if ($recordedAt !== null) {
                $update['recorded_at'] = $recordedAt;
            }

            $updates[] = $update;
        }

        return $updates;
    }

    private function buildPriceUpdateBatches(array $productPromotions): array {
        $productUpdates = [];
        $variantUpdates = [];

        foreach ($productPromotions as $promo) {
            $productId = (int)($promo['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            if (!empty($promo['variant_id'])) {
                $variantUpdates[] = [
                    'product_id' => $productId,
                    'id' => (int)$promo['variant_id'],
                    'sale_price' => $promo['promo_price'],
                ];
                continue;
            }

            $productUpdates[] = [
                'product_id' => $productId,
                'sale_price' => $promo['promo_price'],
            ];
        }

        return [$productUpdates, $variantUpdates];
    }

    private function getPromotionItemKey($productId, $variantId = null) {
        return $variantId ? "v_{$variantId}" : "p_{$productId}";
    }

    private function isPromotionProductExcluded(int $promotionId, array $product): bool {
        if ($this->db === null || $this->storeHash === null) {
            return false;
        }

        $productId = (int)($product['product_id'] ?? 0);
        if ($promotionId <= 0 || $productId <= 0) {
            return false;
        }

        $variantId = isset($product['variant_id']) && $product['variant_id'] !== null && $product['variant_id'] !== ''
            ? (int)$product['variant_id']
            : null;

        if (is_array($this->promotionProductExclusionMap)) {
            return isset($this->promotionProductExclusionMap[$this->buildPromotionProductExclusionKey($promotionId, $productId, $variantId)]);
        }

        try {
            $row = $this->db->fetchOne(
                "SELECT id
                 FROM promotion_product_exclusions
                 WHERE store_hash = ?
                   AND promotion_id = ?
                   AND product_id = ?
                   AND variant_id <=> ?
                 LIMIT 1",
                [$this->storeHash, $promotionId, $productId, $variantId]
            );
        } catch (\Throwable $e) {
            return false;
        }

        return !empty($row);
    }

    private function normalizeActivePromotionsForBatch(array $activePromotions): array {
        foreach ($activePromotions as &$promotion) {
            if (isset($promotion['filters']) && is_string($promotion['filters'])) {
                $decoded = json_decode($promotion['filters'], true);
                $promotion['filters'] = is_array($decoded) ? $decoded : [];
            }
        }
        unset($promotion);

        return $activePromotions;
    }

    private function prefetchPromotionProductExclusions(array $activePromotions, array $items): void {
        $promotionIds = array_values(array_unique(array_filter(array_map(static function (array $promotion): int {
            return (int)($promotion['id'] ?? $promotion['promotion_id'] ?? 0);
        }, $activePromotions), static fn(int $id): bool => $id > 0)));
        $productIds = array_values(array_unique(array_filter(array_map(static function (array $item): int {
            return (int)($item['product_id'] ?? 0);
        }, $items), static fn(int $id): bool => $id > 0)));

        $this->promotionProductExclusionMap = [];
        if (empty($promotionIds) || empty($productIds)) {
            return;
        }

        $promotionPlaceholders = implode(',', array_fill(0, count($promotionIds), '?'));
        $productPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT promotion_id, product_id, variant_id
             FROM promotion_product_exclusions
             WHERE store_hash = ?
               AND promotion_id IN ($promotionPlaceholders)
               AND product_id IN ($productPlaceholders)",
            array_merge([$this->storeHash], $promotionIds, $productIds)
        );

        foreach ($rows as $row) {
            $variantId = isset($row['variant_id']) && $row['variant_id'] !== null
                ? (int)$row['variant_id']
                : null;
            $this->promotionProductExclusionMap[$this->buildPromotionProductExclusionKey(
                (int)$row['promotion_id'],
                (int)$row['product_id'],
                $variantId
            )] = true;
        }
    }

    private function buildPromotionProductExclusionKey(int $promotionId, int $productId, ?int $variantId): string {
        return $promotionId . ':' . $productId . ':' . ($variantId === null ? 'base' : (string)$variantId);
    }

    private function insertPromotionProductExclusion(
        int $promotionId,
        int $productId,
        ?int $variantId,
        ?int $correctionId,
        string $reason
    ): void {
        $existing = $this->db->fetchOne(
            "SELECT id
             FROM promotion_product_exclusions
             WHERE store_hash = ?
               AND promotion_id = ?
               AND product_id = ?
               AND variant_id <=> ?
             LIMIT 1",
            [$this->storeHash, $promotionId, $productId, $variantId]
        );

        if ($existing) {
            $this->db->query(
                "UPDATE promotion_product_exclusions
                 SET correction_id = COALESCE(correction_id, ?),
                     reason = COALESCE(NULLIF(reason, ''), ?)
                 WHERE store_hash = ? AND id = ?",
                [$correctionId, $reason, $this->storeHash, (int)$existing['id']]
            );
            return;
        }

        $this->db->query(
            "INSERT INTO promotion_product_exclusions
                (store_hash, promotion_id, product_id, variant_id, correction_id, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$this->storeHash, $promotionId, $productId, $variantId, $correctionId, $reason]
        );
    }

    private function ensurePromotionProductExclusionSchema(): void {
        try {
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
        } catch (\Throwable $e) {
            // Existing installs may be mid-upgrade; correction paths will surface hard failures if needed.
        }
    }

    private function isCostPriceBlockingEnabledFromFilters($filters): bool {
        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            $filters = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($filters)) {
            return false;
        }

        return !empty($filters[self::BLOCK_BELOW_COST_PRICE_FILTER_KEY]);
    }

    private function getManualUnblockedItemKeysFromFilters($filters): array {
        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            $filters = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($filters)) {
            return [];
        }

        $keys = $filters[self::MANUAL_UNBLOCK_FILTER_KEY] ?? [];
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        $normalized = [];
        foreach ($keys as $key) {
            $key = trim((string)$key);
            if ($key !== '' && preg_match('/^[pv]_\d+$/', $key)) {
                $normalized[$key] = true;
            }
        }

        return $normalized;
    }

    private function isItemManuallyUnblocked(array $item, array $manualUnblockedItems): bool {
        $productId = (int)($item['product_id'] ?? 0);
        if ($productId <= 0) {
            return false;
        }

        $variantId = isset($item['variant_id']) && $item['variant_id'] !== null && $item['variant_id'] !== ''
            ? (int)$item['variant_id']
            : null;

        return isset($manualUnblockedItems[$this->getPromotionItemKey($productId, $variantId)]);
    }

    private function buildRestoreUpdates(array $items): array {
        $productUpdates = [];
        $variantUpdates = [];
        $cacheUpdates = [];

        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $cacheUpdates[] = [
                'product_id' => $productId,
                'variant_id' => $item['variant_id'] ?? null,
                'sale_price' => $item['restore_price'] ?? null
            ];

            if (!empty($item['variant_id'])) {
                $variantUpdates[] = [
                    'product_id' => $productId,
                    'id' => (int)$item['variant_id'],
                    'sale_price' => $item['restore_price'] ?? null
                ];
            } else {
                $productUpdates[] = [
                    'product_id' => $productId,
                    'sale_price' => $item['restore_price'] ?? null
                ];
            }
        }

        return [$productUpdates, $variantUpdates, $cacheUpdates];
    }

    private function fetchPromotionProductsWithCachePrice(string $whereSql, array $params = [], string $suffixSql = ''): array {
        $sql = "
            SELECT
                pp.id,
                pp.promotion_id,
                pp.product_id,
                pp.variant_id,
                pp.custom_field_id,
                pc.id AS cache_id,
                pc.type,
                pc.name,
                pc.sku,
                pc.price,
                pc.sale_price,
                pc.cost_price,
                pc.retail_price,
                pc.tax_class_id,
                pc.tax_rate,
                pc.weight,
                pc.inventory_level,
                pc.inventory_warning_level,
                pc.brand_id,
                pc.brand_name,
                pc.categories,
                pc.is_visible,
                pc.is_featured,
                pc.availability,
                pc.`condition`,
                pc.option_values,
                pc.date_created,
                pc.date_modified,
                pc.custom_fields,
                pc.images,
                pc.cached_at,
                pc.price AS original_price
            FROM promotion_products pp
            LEFT JOIN products_cache pc
                ON pc.store_hash = pp.store_hash
               AND pc.product_id = pp.product_id
               AND pc.variant_id <=> pp.variant_id
            WHERE {$whereSql}
            {$suffixSql}
        ";

        return $this->db->fetchAll($sql, $params);
    }

    private function hasActivePromotionEntriesForProduct($productId, array $excludedIds = []): bool {
        $sql = "SELECT COUNT(*) AS cnt FROM promotion_products WHERE store_hash = ? AND product_id = ?";
        $params = [$this->storeHash, $productId];

        if (!empty($excludedIds)) {
            $placeholders = str_repeat('?,', count($excludedIds) - 1) . '?';
            $sql .= " AND id NOT IN ($placeholders)";
            $params = array_merge($params, $excludedIds);
        }

        $result = $this->db->fetchOne($sql, $params);
        return !empty($result['cnt']);
    }

    public function removeUnusedPromotionField(int $productId): void {
        if (!$this->hasActivePromotionEntriesForProduct($productId)) {
            $results = $this->customFieldService->batchRemovePromotionFields([$productId]);
            if (empty($results[0]['success'])) {
                throw new \RuntimeException("Unable to remove promotion field from product {$productId}");
            }
        }
    }

    private function getProductsWithoutActivePromotionEntries(array $items, array $deletedIds = []): array {
        $candidateProductIds = array_values(array_unique(array_column($items, 'product_id')));
        $productsToClear = [];

        foreach ($candidateProductIds as $productId) {
            if (!$this->hasActivePromotionEntriesForProduct($productId, $deletedIds)) {
                $productsToClear[] = $productId;
            }
        }

        return $productsToClear;
    }

    private function recordAppliedPromotionItems(array $promotion, array $items): void {
        if (!$this->hasArchiveServiceMethod('recordAppliedItems')) {
            return;
        }

        $this->archiveService->recordAppliedItems($promotion, $items);
    }

    private function buildPromotionArchiveMetaFromAppliedItems(array $items): array {
        $first = reset($items);
        if (!is_array($first)) {
            return [];
        }

        return [
            'id' => $first['promotion_id'] ?? ($first['id'] ?? null),
            'promotion_id' => $first['promotion_id'] ?? ($first['id'] ?? null),
            'name' => $first['promotion_name'] ?? '',
            'discount_percent' => $first['discount_percent'] ?? null,
        ];
    }

    private function recordRemovedPromotionItems(?int $promotionId, array $items, string $reason): void {
        if (!$this->hasArchiveServiceMethod('recordRemovedItems')) {
            return;
        }

        $this->archiveService->recordRemovedItems($promotionId, $items, $reason);
    }

    private function recordReplacedPromotionItems(array $items): void {
        if (empty($items)) {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', array_column($items, 'id')), static function (int $id): bool {
            return $id > 0;
        }));

        if (!empty($ids)) {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $detailedItems = $this->fetchPromotionProductsWithCachePrice(
                "pp.store_hash = ? AND pp.id IN ($placeholders)",
                array_merge([$this->storeHash], $ids)
            );

            if (!empty($detailedItems)) {
                $items = $detailedItems;
            }
        }

        $this->recordRemovedPromotionItems(null, $items, 'promotion_replaced');
    }

    private function extractPromotionIds(array $items): array {
        $promotionIds = [];

        foreach ($items as $item) {
            $promotionId = (int)($item['promotion_id'] ?? 0);
            if ($promotionId > 0) {
                $promotionIds[$promotionId] = true;
            }
        }

        return array_keys($promotionIds);
    }

    private function finalizePromotionArchive(int $promotionId, string $reason): void {
        if (!$this->hasArchiveServiceMethod('finalizeArchive')) {
            return;
        }

        $this->archiveService->finalizeArchive($promotionId, $reason);
    }

    public function markPromotionArchiveCleanupCompleted(int $promotionId): void {
        if (!$this->hasArchiveServiceMethod('markCleanupCompleted')) {
            return;
        }

        $this->archiveService->markCleanupCompleted($promotionId);
    }

    private function shouldArchivePromotionBeforeDelete(array $promotion, int $appliedProducts): bool {
        if ($appliedProducts > 0) {
            return true;
        }

        $status = (string)($promotion['status'] ?? '');
        if ($status === 'active' || $status === 'expired') {
            return true;
        }

        if ($this->hasArchiveServiceMethod('hasHistoricalProducts')) {
            return $this->archiveService->hasHistoricalProducts((int)($promotion['id'] ?? 0));
        }

        return false;
    }

    private function hasArchiveServiceMethod(string $method): bool {
        return is_object($this->archiveService) && method_exists($this->archiveService, $method);
    }
}
