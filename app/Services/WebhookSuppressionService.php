<?php
namespace App\Services;

use App\Models\Database;

class WebhookSuppressionService {
    private const DEFAULT_SCOPE = 'store/product/updated';
    private const DEFAULT_TTL_SECONDS = 300;
    private const MAX_PENDING_EVENTS = 25;

    private $db;
    private static $tableReady = false;

    public function __construct(Database $db = null) {
        $this->db = $db ?? Database::getInstance();
    }

    public function suppressProductUpdates(string $storeHash, array $productIds, string $reason = 'app_update', int $ttlSeconds = self::DEFAULT_TTL_SECONDS): void {
        $counts = [];

        foreach ($productIds as $productId) {
            $productId = (int)$productId;
            if ($productId <= 0) {
                continue;
            }

            $counts[$productId] = ($counts[$productId] ?? 0) + 1;
        }

        if (empty($counts) || trim($storeHash) === '') {
            return;
        }

        $this->ensureTable();
        $this->cleanupExpired();

        $ttlSeconds = max(30, min($ttlSeconds, 3600));
        $reason = substr($reason, 0, 100);
        $values = [];
        $params = [];

        foreach ($counts as $productId => $eventCount) {
            $values[] = "(?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))";
            array_push(
                $params,
                $storeHash,
                self::DEFAULT_SCOPE,
                $productId,
                min((int)$eventCount, self::MAX_PENDING_EVENTS),
                $reason,
                $ttlSeconds
            );
        }

        $sql = "INSERT INTO webhook_suppressions
                (store_hash, scope, product_id, remaining_events, reason, expires_at)
                VALUES " . implode(', ', $values) . "
                ON DUPLICATE KEY UPDATE
                    remaining_events = LEAST(remaining_events + VALUES(remaining_events), " . self::MAX_PENDING_EVENTS . "),
                    reason = VALUES(reason),
                    expires_at = GREATEST(expires_at, VALUES(expires_at))";

        $this->db->query($sql, $params);
    }

    public function consumeProductUpdate(string $storeHash, int $productId, string $scope = self::DEFAULT_SCOPE): bool {
        if ($productId <= 0 || trim($storeHash) === '') {
            return false;
        }

        $this->ensureTable();
        $this->cleanupExpired();

        $row = $this->db->fetchOne(
            "SELECT id, remaining_events
             FROM webhook_suppressions
             WHERE store_hash = ?
               AND scope = ?
               AND product_id = ?
               AND expires_at >= NOW()
             LIMIT 1",
            [$storeHash, $scope, $productId]
        );

        if (!$row || empty($row['id'])) {
            return false;
        }

        if ((int)($row['remaining_events'] ?? 0) <= 1) {
            $this->db->query(
                "DELETE FROM webhook_suppressions WHERE id = ?",
                [(int)$row['id']]
            );
        } else {
            $this->db->query(
                "UPDATE webhook_suppressions
                 SET remaining_events = remaining_events - 1
                 WHERE id = ?",
                [(int)$row['id']]
            );
        }

        return true;
    }

    private function ensureTable(): void {
        if (self::$tableReady) {
            return;
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `webhook_suppressions` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `store_hash` VARCHAR(255) NOT NULL,
                `scope` VARCHAR(255) NOT NULL,
                `product_id` INT UNSIGNED NOT NULL,
                `remaining_events` INT UNSIGNED NOT NULL DEFAULT 1,
                `reason` VARCHAR(100) NOT NULL DEFAULT 'app_update',
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                UNIQUE KEY `uniq_store_scope_product` (`store_hash`, `scope`, `product_id`),
                INDEX `idx_expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$tableReady = true;
    }

    private function cleanupExpired(): void {
        $this->db->query(
            "DELETE FROM webhook_suppressions WHERE expires_at < NOW()"
        );
    }
}
