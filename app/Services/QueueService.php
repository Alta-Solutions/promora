<?php
namespace App\Services;

use App\Models\Database;

class QueueService {
    private $db;
    private $storeHash;
    
    // Konstanta: Maksimalan broj pokušaja
    private const MAX_RETRIES = 3; 

    public function __construct($storeHash = null) {
        $this->db = Database::getInstance();
        $this->storeHash = $storeHash ?? $this->db->getStoreContext();
    }

    public function createJob($type, $promotionId = null, $totalItems = 0) {
        $this->db->query(
            "INSERT INTO sync_jobs (store_hash, job_type, promotion_id, total_items, status, attempts, created_at) 
             VALUES (?, ?, ?, ?, 'pending', 0, NOW())",
            [$this->storeHash, $type, $promotionId, $totalItems]
        );
        return $this->db->lastInsertId();
    }

    public function createOmnibusSyncJob(int $totalItems, bool $deduplicateOpenJobs = true): array {
        $lockName = 'omnibus_sync:' . (string)$this->storeHash;
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

    // --- IZMENJENO: Sada gleda i 'next_run_at' ---
    public function getNextPendingJob() {
        return $this->db->fetchOne(
            "SELECT * FROM sync_jobs
             WHERE status = 'pending'
             AND (next_run_at IS NULL OR next_run_at <= NOW())
             ORDER BY created_at ASC, id ASC LIMIT 1"
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
}
