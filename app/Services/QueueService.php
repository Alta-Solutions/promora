<?php
namespace App\Services;

use App\Models\Database;

class QueueService {
    private $db;
    private $storeHash;
    private $syncJobsPayloadColumnEnsured = false;
    
    // Konstanta: Maksimalan broj pokušaja
    private const MAX_RETRIES = 3; 

    public function __construct($storeHash = null) {
        $this->db = Database::getInstance();
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

            $jobId = $this->createJob($type, $promotionId, $totalItems > 0 ? $totalItems : 1);

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
            if ($openFullJob) {
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

                $this->db->query(
                    "UPDATE sync_jobs
                     SET payload = ?, total_items = ?, updated_at = NOW()
                     WHERE id = ?",
                    [json_encode($payload, JSON_UNESCAPED_SLASHES), count($mergedProductIds), (int)$pendingTargetedJob['id']]
                );

                return [
                    'created' => false,
                    'job_id' => (int)$pendingTargetedJob['id'],
                    'reason' => 'merged',
                    'message' => 'Targeted Omnibus sync products were merged into an existing pending job.',
                    'product_ids' => $mergedProductIds,
                ];
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

                    $this->db->query(
                        "UPDATE sync_jobs
                         SET payload = ?, total_items = ?, updated_at = NOW()
                         WHERE id = ?",
                        [json_encode($payload, JSON_UNESCAPED_SLASHES), count($mergedEventIds), (int)$pendingJob['id']]
                    );

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
    public function getNextPendingJob() {
        return $this->db->fetchOne(
            "SELECT * FROM sync_jobs
             WHERE status = 'pending'
             AND (next_run_at IS NULL OR next_run_at <= NOW())
             ORDER BY
                CASE job_type
                    WHEN 'sync_promotion' THEN 10
                    WHEN 'single_sync' THEN 10
                    WHEN 'cleanup_single' THEN 20
                    WHEN 'cleanup' THEN 20
                    WHEN 'omnibus_sync_products' THEN 25
                    WHEN 'webhook_event' THEN 30
                    WHEN 'omnibus_sync' THEN 40
                    ELSE 50
                END,
                created_at ASC,
                id ASC
             LIMIT 1"
        );
    }

    public function updateProgress($jobId, $processed) {
        return $this->db->query(
            "UPDATE sync_jobs SET processed_items = ?, updated_at = NOW() WHERE id = ?",
            [$processed, $jobId]
        );
    }

    public function updateJobStatus($jobId, $status, $error = null) {
        return $this->db->query(
            "UPDATE sync_jobs SET status = ?, error_message = ?, updated_at = NOW() WHERE id = ?",
            [$status, $error, $jobId]
        );
    }

    /**
     * Dohvata trenutno aktivan posao (processing) za prikaz u UI
     */
    public function getActiveJob() {
        return $this->db->fetchOne(
            "SELECT * FROM sync_jobs 
             WHERE store_hash = ? AND (
                status IN ('processing', 'pending') 
                OR (status = 'completed' AND updated_at > DATE_SUB(NOW(), INTERVAL 10 SECOND))
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

    public function deferWebhookEventJob(int $jobId, string $storeHash, array $remainingEventIds, int $delaySeconds = 0): void {
        $remainingEventIds = $this->normalizeWebhookEventIds($remainingEventIds);
        if (empty($remainingEventIds)) {
            return;
        }

        $delaySeconds = max(0, min(300, $delaySeconds));
        $nextRunAtSql = $delaySeconds > 0
            ? "DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND)"
            : "NULL";
        $payload = $this->buildWebhookEventPayload($remainingEventIds);

        $this->db->query(
            "UPDATE sync_jobs
             SET payload = ?,
                 total_items = ?,
                 processed_items = 0,
                 status = 'pending',
                 error_message = NULL,
                 next_run_at = {$nextRunAtSql},
                 updated_at = NOW()
             WHERE id = ? AND store_hash = ?",
            [
                json_encode($payload, JSON_UNESCAPED_SLASHES),
                count($remainingEventIds),
                $jobId,
                $storeHash,
            ]
        );
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

    public function purgeOldJobs(int $completedRetentionDays = 14, int $failedRetentionDays = 90): array {
        $completedRetentionDays = max(1, $completedRetentionDays);
        $failedRetentionDays = max(1, $failedRetentionDays);

        $completedStmt = $this->db->query(
            "DELETE FROM sync_jobs
             WHERE store_hash = ?
               AND status = 'completed'
               AND COALESCE(updated_at, created_at) < DATE_SUB(NOW(), INTERVAL {$completedRetentionDays} DAY)",
            [$this->storeHash]
        );

        $failedStmt = $this->db->query(
            "DELETE FROM sync_jobs
             WHERE store_hash = ?
               AND status = 'failed'
               AND COALESCE(updated_at, created_at) < DATE_SUB(NOW(), INTERVAL {$failedRetentionDays} DAY)",
            [$this->storeHash]
        );

        return [
            'completed_deleted' => $completedStmt->rowCount(),
            'failed_deleted' => $failedStmt->rowCount(),
        ];
    }

    /**
     * NOVO: Pametna obrada greške sa Retry logikom
     */
    public function handleJobFailure($jobId, $errorMessage) {
        // 1. Dohvati trenutni broj pokušaja
        $job = $this->db->fetchOne("SELECT attempts FROM sync_jobs WHERE id = ?", [$jobId]);
        $attempts = ($job['attempts'] ?? 0) + 1;

        if ($attempts < self::MAX_RETRIES) {
            // SCENARIO: Ponovni pokušaj (Retry)
            
            // Exponential backoff: čeka 2min, pa 4min, pa 8min...
            $delayMinutes = pow(2, $attempts); 
            
            $sql = "UPDATE sync_jobs 
                    SET status = 'pending', 
                        attempts = ?, 
                        error_message = ?, 
                        next_run_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                        updated_at = NOW() 
                    WHERE id = ?";
            
            $this->db->query($sql, [$attempts, "Retry #{$attempts}: " . $errorMessage, $delayMinutes, $jobId]);
            
            echo "⚠️ Job #{$jobId} failed. Scheduling retry #{$attempts} in {$delayMinutes} minutes.\n";
            
        } else {
            // SCENARIO: Trajni neuspeh (Failed)
            $this->db->query(
                "UPDATE sync_jobs 
                 SET status = 'failed', 
                     attempts = ?, 
                     error_message = ?, 
                     updated_at = NOW() 
                 WHERE id = ?",
                [$attempts, "Max retries reached. Last error: " . $errorMessage, $jobId]
            );
            
            echo "❌ Job #{$jobId} failed permanently after {$attempts} attempts.\n";
        }
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
               AND status = ?
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
