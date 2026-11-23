<?php
namespace App\Services;

use App\Models\Database;

class QueueService {
    private $db;
    private $storeHash;
    private $syncJobsPayloadColumnEnsured = false;
    
    // Konstanta: Maksimalan broj pokušaja
    private const MAX_RETRIES = 3; 

    public function __construct($storeHash = null, ?Database $db = null) {
        $this->db = $db ?? Database::getInstance();
        $this->storeHash = $storeHash ?? $this->db->getStoreContext();
    }

    public function createJob($type, $promotionId = null, $totalItems = 0) {
        $storeHash = $this->requireStoreHash('create sync job');

        $this->db->query(
            "INSERT INTO sync_jobs (store_hash, job_type, promotion_id, total_items, status, attempts, created_at) 
             VALUES (?, ?, ?, ?, 'pending', 0, NOW())",
            [$storeHash, $type, $promotionId, $totalItems]
        );
        return $this->db->lastInsertId();
    }

    public function createJobIfNotOpen($type, $promotionId = null, $totalItems = 0): array {
        $storeHash = $this->requireStoreHash('create sync job');
        $lockName = 'sync_job:' . $storeHash . ':' . (string)$type . ':' . ($promotionId === null ? 'null' : (string)$promotionId);
        $lockAcquired = $this->acquireLock($lockName, 5);

        if (!$lockAcquired) {
            return [
                'created' => false,
                'job_id' => null,
                'reason' => 'lock_timeout',
                'message' => 'Nije moguce rezervisati job u ovom trenutku.',
            ];
        }

        try {
            $existingJob = $this->findOpenJob($type, $promotionId);
            if ($existingJob) {
                return [
                    'created' => false,
                    'job_id' => (int)$existingJob['id'],
                    'reason' => 'already_exists',
                    'message' => 'Job je vec zakazan ili u toku.',
                    'job' => $existingJob,
                ];
            }

            $jobId = $this->createJob($type, $promotionId, max(0, $totalItems));

            return [
                'created' => true,
                'job_id' => (int)$jobId,
                'reason' => 'created',
                'message' => 'Job je uspesno zakazan.',
            ];
        } finally {
            $this->releaseLock($lockName);
        }
    }

    public function createOmnibusSyncJob(int $totalItems, bool $deduplicateOpenJobs = true): array {
        $storeHash = $this->requireStoreHash('create Omnibus sync job');
        $lockName = 'omnibus_sync:' . $storeHash;
        $lockAcquired = $this->acquireLock($lockName, 5);

        if (!$lockAcquired) {
            return [
                'created' => false,
                'job_id' => null,
                'reason' => 'lock_timeout',
                'message' => 'Nije moguce rezervisati Omnibus sync u ovom trenutku.',
            ];
        }

        try {
            if ($deduplicateOpenJobs) {
                $existingJob = $this->findOpenJobByType('omnibus_sync');
                if ($existingJob) {
                    return [
                        'created' => false,
                        'job_id' => (int)$existingJob['id'],
                        'reason' => 'already_exists',
                        'message' => 'Omnibus sync je vec zakazan ili u toku.',
                        'job' => $existingJob,
                    ];
                }
            }

            $jobId = $this->createJob('omnibus_sync', null, $totalItems > 0 ? $totalItems : 1);

            return [
                'created' => true,
                'job_id' => (int)$jobId,
                'reason' => 'created',
                'message' => 'Omnibus sync je uspesno zakazan.',
            ];
        } finally {
            $this->releaseLock($lockName);
        }
    }

    public function createTargetedOmnibusSyncJob(array $productIds, array $meta = []): array {
        $storeHash = $this->requireStoreHash('create targeted Omnibus sync job');
        $productIds = $this->normalizeProductIds($productIds);

        if (empty($productIds)) {
            return [
                'created' => false,
                'job_id' => null,
                'reason' => 'no_products',
                'message' => 'No product IDs were provided for targeted Omnibus sync.',
            ];
        }

        $lockName = 'omnibus_sync_products:' . $storeHash;
        $lockAcquired = $this->acquireLock($lockName, 5);

        if (!$lockAcquired) {
            return [
                'created' => false,
                'job_id' => null,
                'reason' => 'lock_timeout',
                'message' => 'Nije moguce rezervisati targeted Omnibus sync u ovom trenutku.',
            ];
        }

        try {
            $this->ensureSyncJobsPayloadColumn();

            $openFullJob = $this->findOpenJobByType('omnibus_sync');
            if ($openFullJob && $openFullJob['status'] === 'pending' && empty($openFullJob['checkpoint']) && empty($openFullJob['payload'])) {
                return [
                    'created' => false,
                    'job_id' => (int)$openFullJob['id'],
                    'reason' => 'covered_by_full_sync',
                    'message' => 'Open full Omnibus sync already covers these products.',
                    'job' => $openFullJob,
                ];
            }

            $pendingTargetedJob = $this->findJobByTypeAndStatus('omnibus_sync_products', 'pending');
            if ($pendingTargetedJob) {
                $existingPayload = $this->decodePayload($pendingTargetedJob['payload'] ?? null);
                $mergedProductIds = $this->normalizeProductIds(array_merge(
                    $existingPayload['product_ids'] ?? [],
                    $productIds
                ));
                $payload = $this->buildTargetedOmnibusPayload(
                    $mergedProductIds,
                    $meta + [
                        'merged_from_job_id' => (int)$pendingTargetedJob['id'],
                    ]
                );

                $updated = $this->db->query(
                    "UPDATE sync_jobs
                     SET payload = ?, total_items = ?, updated_at = NOW()
                     WHERE id = ? AND store_hash = ? AND status = 'pending' AND checkpoint IS NULL AND payload <=> ?",
                    [json_encode($payload, JSON_UNESCAPED_SLASHES), count($mergedProductIds), (int)$pendingTargetedJob['id'], $storeHash, $pendingTargetedJob['payload']]
                );

                if ($updated->rowCount()) {
                    return [
                        'created' => false,
                        'job_id' => (int)$pendingTargetedJob['id'],
                        'reason' => 'merged',
                        'message' => 'Targeted Omnibus sync products were merged into an existing pending job.',
                        'product_ids' => $mergedProductIds,
                    ];
                }
            }

            $payload = $this->buildTargetedOmnibusPayload($productIds, $meta);
            $this->db->query(
                "INSERT INTO sync_jobs (store_hash, job_type, promotion_id, payload, total_items, status, attempts, created_at)
                 VALUES (?, 'omnibus_sync_products', ?, ?, ?, 'pending', 0, NOW())",
                [
                    $storeHash,
                    isset($meta['promotion_id']) ? (int)$meta['promotion_id'] : null,
                    json_encode($payload, JSON_UNESCAPED_SLASHES),
                    count($productIds),
                ]
            );

            return [
                'created' => true,
                'job_id' => (int)$this->db->lastInsertId(),
                'reason' => 'created',
                'message' => 'Targeted Omnibus sync job je uspesno zakazan.',
                'product_ids' => $productIds,
            ];
        } finally {
            $this->releaseLock($lockName);
        }
    }

    public function queueVariantVerification(int $productId): void {
        $store = $this->requireStoreHash('verify product variants');
        if ($productId <= 0) {
            throw new \InvalidArgumentException('Product ID required for variant verification');
        }
        $payload = json_encode(['product_ids' => [$productId]]);
        $lock = 'variant_check:' . sha1($store . ':' . $productId);
        $this->ensureSyncJobsPayloadColumn();
        if (!$this->acquireLock($lock, 5)) {
            throw new \RuntimeException("Unable to queue variant verification for product {$productId}");
        }
        try {
            $pending = $this->db->fetchOne(
                "SELECT id FROM sync_jobs WHERE store_hash = ? AND job_type = 'verify_variants'
                 AND status = 'pending' AND payload = ? LIMIT 1", [$store, $payload]
            );
            if (!$pending) {
                $this->db->query(
                    "INSERT INTO sync_jobs (store_hash, job_type, payload, total_items, status, attempts, created_at)
                     VALUES (?, 'verify_variants', ?, 1, 'pending', 0, NOW())", [$store, $payload]
                );
            }
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function createWebhookEventJob(int $eventId): array {
        $storeHash = $this->requireStoreHash('create webhook event job');
        if ($eventId <= 0) {
            throw new \InvalidArgumentException('Webhook event ID must be positive.');
        }

        $lockName = 'webhook_event:' . $storeHash;
        $lockAcquired = $this->acquireLock($lockName, 5);

        if (!$lockAcquired) {
            $this->ensureSyncJobsPayloadColumn();
            return $this->insertWebhookEventJob($storeHash, $eventId, 'lock_timeout_fallback');
        }

        try {
            $this->ensureSyncJobsPayloadColumn();

            $pendingJob = $this->findJobByTypeAndStatus('webhook_event', 'pending');
            if ($pendingJob) {
                $existingEventIds = $this->extractWebhookEventIdsFromPayload($pendingJob['payload'] ?? null);
                if (!empty($existingEventIds)) {
                    $mergedEventIds = $this->normalizeWebhookEventIds(array_merge($existingEventIds, [$eventId]));
                    $payload = $this->buildWebhookEventPayload($mergedEventIds);

                    $updated = $this->db->query(
                        "UPDATE sync_jobs
                         SET payload = ?, total_items = ?, updated_at = NOW()
                         WHERE id = ? AND store_hash = ? AND status = 'pending' AND checkpoint IS NULL AND payload <=> ?",
                        [json_encode($payload, JSON_UNESCAPED_SLASHES), count($mergedEventIds), (int)$pendingJob['id'], $storeHash, $pendingJob['payload']]
                    );

                    if (!$updated->rowCount() && !in_array($eventId, $existingEventIds, true)) {
                        return $this->insertWebhookEventJob($storeHash, $eventId, 'claimed_during_merge');
                    }
                    return [
                        'created' => false,
                        'job_id' => (int)$pendingJob['id'],
                        'event_id' => $eventId,
                        'event_ids' => $mergedEventIds,
                        'reason' => 'merged',
                    ];
                }
            }

            return $this->insertWebhookEventJob($storeHash, $eventId, 'created');
        } finally {
            $this->releaseLock($lockName);
        }
    }

    private function requireStoreHash(string $operation): string {
        $storeHash = trim((string)$this->storeHash);

        if ($storeHash === '') {
            throw new \InvalidArgumentException("Store context required to {$operation}.");
        }

        $this->storeHash = $storeHash;

        return $storeHash;
    }

    // --- IZMENJENO: Sada gleda i 'next_run_at' ---
    private function nextReadyRow(bool $lock = false) {
        $canaries = array_values(array_filter(array_map('trim',explode(',',$_ENV['PROMORA_WORKER_STORES'] ?? ''))));
        $scope = $canaries ? ' AND store_hash IN (' . implode(',',array_fill(0,count($canaries),'?')) . ')' : '';
        return $this->db->fetchOne(
            "SELECT * FROM sync_jobs
             WHERE (status = 'pending' OR (status = 'processing' AND lease_token IS NOT NULL AND lease_until < NOW()))
             AND (next_run_at IS NULL OR next_run_at <= NOW())$scope
             ORDER BY
                CASE job_type
                    WHEN 'sync_promotion' THEN 10
                    WHEN 'single_sync' THEN 10
                    WHEN 'cleanup_single' THEN 20
                    WHEN 'cleanup' THEN 20
                    WHEN 'resume_correction' THEN 11
                    WHEN 'retry_promotion_product' THEN 21
                    WHEN 'verify_variants' THEN 22
                    WHEN 'cleanup_promotion_field' THEN 23
                    WHEN 'omnibus_sync_products' THEN 25
                    WHEN 'webhook_event' THEN 30
                    WHEN 'omnibus_sync' THEN 40
                    ELSE 50
                END,
                created_at ASC,
                id ASC
             LIMIT 1" . ($lock ? " FOR UPDATE" : ""), $canaries
        );
    }

    public function claim(): ?array {
        $this->db->beginTransaction();
        try {
            $row = $this->nextReadyRow(true);
            if ($row) {
                $row['lease_token'] = bin2hex(random_bytes(16));
                $this->db->query("UPDATE sync_jobs SET status='processing', lease_token=?,
                    lease_until=DATE_ADD(NOW(), INTERVAL 5 MINUTE), updated_at=NOW()
                    WHERE store_hash=? AND id=?", [$row['lease_token'], $row['store_hash'], $row['id']]);
            }
            $this->db->commit();
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function checkpoint(array $job, array $state, string $status = 'processing', ?string $outcome = null): void {
        $token = $status === 'processing' ? $job['lease_token'] : null;
        $result = $this->db->query("UPDATE sync_jobs SET checkpoint=?, processed_items=?, status=?,
            outcome=?, lease_token=?, lease_until=DATE_ADD(NOW(), INTERVAL 5 MINUTE), updated_at=NOW(),
            next_run_at=IF(?='pending', DATE_ADD(NOW(), INTERVAL 1 SECOND), NULL)
            WHERE id=? AND store_hash=? AND status='processing' AND lease_token=? AND lease_until>=NOW()",
            [json_encode($state, JSON_THROW_ON_ERROR), $state['processed'], $status, $outcome, $token, $status,
                $job['id'], $job['store_hash'], $job['lease_token']]);
        if (!$result->rowCount()) {
            // A heartbeat in the same second can leave every column unchanged.
            $owned = $this->db->fetchOne("SELECT id FROM sync_jobs WHERE id=? AND store_hash=?
                AND status='processing' AND lease_token=? AND lease_until>=NOW()", [$job['id'],$job['store_hash'],$job['lease_token']]);
            if (!$owned) {
                throw new \RuntimeException('Job reservation expired');
            }
        }
    }

    /**
     * Dohvata trenutno aktivan posao (processing) za prikaz u UI
     */
    public function getActiveJob() {
        return $this->db->fetchOne(
            "SELECT * FROM sync_jobs 
             WHERE store_hash = ? AND (
                status IN ('processing', 'pending') 
                OR (status IN ('completed','failed') AND updated_at > DATE_SUB(NOW(), INTERVAL 10 SECOND))
             )
             ORDER BY 
                CASE status 
                    WHEN 'processing' THEN 1 
                    WHEN 'pending' THEN 2 
                    ELSE 3 
                END,
                CASE job_type
                    WHEN 'sync_promotion' THEN 10
                    WHEN 'single_sync' THEN 10
                    WHEN 'cleanup_single' THEN 20
                    WHEN 'cleanup' THEN 20
                    WHEN 'resume_correction' THEN 11
                    WHEN 'retry_promotion_product' THEN 21
                    WHEN 'verify_variants' THEN 22
                    WHEN 'cleanup_promotion_field' THEN 23
                    WHEN 'omnibus_sync_products' THEN 25
                    WHEN 'webhook_event' THEN 30
                    WHEN 'omnibus_sync' THEN 40
                    ELSE 50
                END,
                updated_at DESC 
             LIMIT 1",
            [$this->storeHash]
        );
    }

    public function getActiveJobByType(string $jobType) {
        return $this->db->fetchOne(
            "SELECT * FROM sync_jobs
             WHERE store_hash = ?
               AND job_type = ?
               AND status IN ('pending', 'processing')
             ORDER BY
                CASE status
                    WHEN 'processing' THEN 1
                    WHEN 'pending' THEN 2
                    ELSE 3
                END,
                created_at ASC
             LIMIT 1",
            [$this->storeHash, $jobType]
        );
    }

    public function extractProductIdsFromPayload($payload): array {
        $payload = $this->decodePayload($payload);
        return $this->normalizeProductIds($payload['product_ids'] ?? []);
    }

    public function extractWebhookEventIdFromPayload($payload): ?int {
        $eventIds = $this->extractWebhookEventIdsFromPayload($payload);
        return $eventIds[0] ?? null;
    }

    public function extractWebhookEventIdsFromPayload($payload): array {
        $payload = $this->decodePayload($payload);
        $eventIds = [];

        if (isset($payload['webhook_event_ids']) && is_array($payload['webhook_event_ids'])) {
            $eventIds = $payload['webhook_event_ids'];
        }

        if (isset($payload['webhook_event_id'])) {
            $eventIds[] = $payload['webhook_event_id'];
        }

        return $this->normalizeWebhookEventIds($eventIds);
    }

    public function renew(array $job): void {
        $args = [$job['id'],$job['store_hash'],$job['lease_token']];
        $this->db->query("UPDATE sync_jobs SET lease_until=DATE_ADD(NOW(), INTERVAL 5 MINUTE)
            WHERE id=? AND store_hash=? AND lease_token=? AND lease_until>=NOW()", $args);
        if (!$this->db->fetchOne("SELECT id FROM sync_jobs WHERE id=? AND store_hash=? AND lease_token=?
            AND lease_until>=NOW()", $args)) {
            throw new \RuntimeException('Job reservation expired');
        }
    }

    public function scheduleProductRetries(array $job, array $diagnostics): void {
        foreach ($this->normalizeProductIds(array_column($diagnostics,'product_id')) as $id) {
            $payload = json_encode(['product_ids'=>[$id]]);
            $exists = $this->db->fetchOne("SELECT id FROM sync_jobs WHERE store_hash=? AND job_type='retry_promotion_product'
                AND status='pending' AND payload=? LIMIT 1", [$job['store_hash'],$payload]);
            if (!$exists) {
                $this->db->query("INSERT INTO sync_jobs(store_hash,job_type,payload,status,total_items,created_at,next_run_at)
                    VALUES (?,'retry_promotion_product',?,'pending',1,NOW(),DATE_ADD(NOW(),INTERVAL 1 MINUTE))", [$job['store_hash'],$payload]);
            }
        }
    }

    public function retryWebhookOrMarkTerminal(array $job, int $event, array $state): void {
        $this->db->beginTransaction();
        try {
            $this->checkpoint($job,$state);
            $attempt = (int)$job['attempts'] + 1;
            if ($attempt < self::MAX_RETRIES) {
                $delay = 2 ** $attempt;
                $this->db->query("INSERT INTO sync_jobs(store_hash,job_type,payload,total_items,status,attempts,created_at,next_run_at)
                    VALUES (?,'webhook_event',?,1,'pending',?,NOW(),DATE_ADD(NOW(), INTERVAL $delay MINUTE))",
                    [$job['store_hash'],json_encode($this->buildWebhookEventPayload([$event])),$attempt]);
            } else {
                $this->db->query('UPDATE webhook_events SET terminal_at=COALESCE(terminal_at,NOW())
                    WHERE store_hash=? AND id=? AND processed=0', [$job['store_hash'],$event]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function buildWebhookEventPayload(array $eventIds): array {
        $eventIds = $this->normalizeWebhookEventIds($eventIds);
        $firstEventId = $eventIds[0] ?? null;

        $payload = [
            'webhook_event_ids' => $eventIds,
        ];

        if ($firstEventId !== null) {
            $payload['webhook_event_id'] = $firstEventId;
        }

        return $payload;
    }

    private function insertWebhookEventJob(string $storeHash, int $eventId, string $reason): array {
        $payload = $this->buildWebhookEventPayload([$eventId]);
        $this->db->query(
            "INSERT INTO sync_jobs (store_hash, job_type, payload, total_items, status, attempts, created_at)
             VALUES (?, 'webhook_event', ?, 1, 'pending', 0, NOW())",
            [$storeHash, json_encode($payload, JSON_UNESCAPED_SLASHES)]
        );

        return [
            'created' => true,
            'job_id' => (int)$this->db->lastInsertId(),
            'event_id' => $eventId,
            'event_ids' => [$eventId],
            'reason' => $reason,
        ];
    }

    public function failClaim(array $job, string $message): void {
        $attempts = (int)$job['attempts'] + 1;
        $terminal = $attempts >= self::MAX_RETRIES;
        $delay = 2 ** min($attempts, self::MAX_RETRIES);
        $this->db->query("UPDATE sync_jobs SET attempts=?, status=?, error_message=?, outcome=?,
            next_run_at=DATE_ADD(NOW(), INTERVAL $delay MINUTE), lease_token=NULL, lease_until=NULL, updated_at=NOW()
            WHERE id=? AND store_hash=? AND lease_token=? AND lease_until>=NOW()",
            [$attempts, $terminal ? 'failed' : 'pending', $message, $terminal ? 'failed' : null,
                $job['id'], $job['store_hash'], $job['lease_token']]);
    }

    private function findOpenJobByType(string $jobType) {
        return $this->db->fetchOne(
            "SELECT *
             FROM sync_jobs
             WHERE store_hash = ?
               AND job_type = ?
               AND status IN ('pending', 'processing')
             ORDER BY created_at ASC
             LIMIT 1",
            [$this->storeHash, $jobType]
        );
    }

    private function findJobByTypeAndStatus(string $jobType, string $status) {
        return $this->db->fetchOne(
            "SELECT *
             FROM sync_jobs
             WHERE store_hash = ?
               AND job_type = ?
               AND status = ? AND checkpoint IS NULL AND attempts=0
             ORDER BY created_at ASC, id ASC
             LIMIT 1",
            [$this->storeHash, $jobType, $status]
        );
    }

    private function findOpenJob($jobType, $promotionId = null) {
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

    private function acquireLock(string $lockName, int $timeoutSeconds): bool {
        $row = $this->db->fetchOne("SELECT GET_LOCK(?, ?) AS acquired", [$lockName, $timeoutSeconds]);
        return !empty($row) && (int)($row['acquired'] ?? 0) === 1;
    }

    private function releaseLock(string $lockName): void {
        try {
            $this->db->fetchOne("SELECT RELEASE_LOCK(?) AS released", [$lockName]);
        } catch (\Throwable $e) {
            // Lock cleanup failure should not break the main flow.
        }
    }

    private function ensureSyncJobsPayloadColumn(): void {
        if ($this->syncJobsPayloadColumnEnsured) {
            return;
        }

        $column = $this->db->fetchOne("SHOW COLUMNS FROM sync_jobs LIKE 'payload'");
        if (!$column) {
            $this->db->query("ALTER TABLE sync_jobs ADD COLUMN payload LONGTEXT NULL AFTER promotion_id");
        }

        $this->syncJobsPayloadColumnEnsured = true;
    }

    private function normalizeProductIds(array $productIds): array {
        $normalized = [];
        foreach ($productIds as $productId) {
            if (!is_numeric($productId)) {
                continue;
            }

            $productId = (int)$productId;
            if ($productId > 0) {
                $normalized[$productId] = true;
            }
        }

        $productIds = array_keys($normalized);
        sort($productIds, SORT_NUMERIC);
        return $productIds;
    }

    private function normalizeWebhookEventIds(array $eventIds): array {
        $normalized = [];
        foreach ($eventIds as $eventId) {
            if (!is_numeric($eventId)) {
                continue;
            }

            $eventId = (int)$eventId;
            if ($eventId > 0) {
                $normalized[$eventId] = true;
            }
        }

        $eventIds = array_keys($normalized);
        sort($eventIds, SORT_NUMERIC);
        return $eventIds;
    }

    private function buildTargetedOmnibusPayload(array $productIds, array $meta): array {
        $payload = [
            'product_ids' => $this->normalizeProductIds($productIds),
        ];

        foreach ([
            'source',
            'promotion_id',
            'source_job_id',
            'merged_from_job_id',
            'day_start',
            'cache_dirty_count',
            'history_dirty_count',
            'ignored_history_dirty_count',
            'total_dirty_count',
        ] as $key) {
            if (!array_key_exists($key, $meta) || $meta[$key] === null || $meta[$key] === '') {
                continue;
            }

            $payload[$key] = in_array($key, [
                'promotion_id',
                'source_job_id',
                'merged_from_job_id',
                'cache_dirty_count',
                'history_dirty_count',
                'ignored_history_dirty_count',
                'total_dirty_count',
            ], true)
                ? (int)$meta[$key]
                : (string)$meta[$key];
        }

        return $payload;
    }

    private function decodePayload($payload): array {
        if (is_array($payload)) {
            return $payload;
        }

        if (!is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }
}
