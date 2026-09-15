<?php
namespace App\Controllers;

use App\Models\Database;

class DashboardController {
    private $db;
    private $storeHash;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->storeHash = $this->db->getStoreContext();
        
        if (!$this->storeHash) {
            throw new \Exception("Store hash nije dostupan. Kontekst prodavnice je obavezan.");
        }
    }
    
    public function index() {
        // Get statistics
        $stats = $this->getStats();
        $promotionAttention = $this->getPromotionAttention();
        
        // Render view
        $this->renderView($stats, $promotionAttention);
    }
    
    private function getStats() {
        $promotionStats = $this->db->fetchOne(
            "SELECT COUNT(*) AS total_promotions,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_promotions
             FROM promotions
             WHERE store_hash = ?",
            [$this->storeHash]
        );
        $productStats = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT product_id) AS total_products
             FROM promotion_products
             WHERE store_hash = ?",
            [$this->storeHash]
        );
        $syncStats = $this->getSyncSummary();

        return [
            'total_promotions' => (int)($promotionStats['total_promotions'] ?? 0),
            'active_promotions' => (int)($promotionStats['active_promotions'] ?? 0),
            'total_products' => (int)($productStats['total_products'] ?? 0),
            'last_sync' => $syncStats['last_sync'] ?? null,
        ];
    }
    
    private function getPromotionAttention(): array {
        $attentionSummary = $this->getPromotionAttentionSummary();

        return [
            'summary' => $attentionSummary,
            'items' => $this->getPromotionAttentionItems(),
        ];
    }

    private function getPromotionAttentionItems(): array {
        return $this->db->fetchAll(
            "SELECT *
             FROM (
                SELECT
                    p.id,
                    p.name,
                    p.color,
                    p.discount_percent,
                    p.start_date,
                    p.end_date,
                    p.priority,
                    p.status,
                    sl.last_sync,
                    CASE
                        WHEN p.status = 'active'
                         AND p.start_date <= NOW()
                         AND p.end_date >= NOW()
                         AND DATE(p.end_date) = CURDATE()
                            THEN 'expires_today'
                        WHEN p.status = 'active'
                         AND p.start_date <= NOW()
                         AND p.end_date > NOW()
                         AND DATE(p.end_date) > CURDATE()
                         AND p.end_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                            THEN 'expires_soon'
                        WHEN p.status = 'scheduled'
                         AND p.start_date > NOW()
                         AND p.start_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                            THEN 'starts_soon'
                        WHEN p.status = 'active'
                         AND p.start_date <= NOW()
                         AND p.end_date >= NOW()
                         AND sl.last_sync IS NULL
                            THEN 'never_synced'
                        ELSE NULL
                    END AS attention_type,
                    CASE
                        WHEN p.status = 'scheduled' THEN p.start_date
                        ELSE p.end_date
                    END AS attention_at
                FROM promotions p
                LEFT JOIN (
                    SELECT store_hash, promotion_id, MAX(synced_at) AS last_sync
                    FROM sync_log
                    WHERE store_hash = ?
                    GROUP BY store_hash, promotion_id
                ) sl
                    ON sl.store_hash = p.store_hash
                   AND sl.promotion_id = p.id
                WHERE p.store_hash = ?
             ) attention
             WHERE attention_type IS NOT NULL
             ORDER BY
                CASE attention_type
                    WHEN 'expires_today' THEN 1
                    WHEN 'expires_soon' THEN 2
                    WHEN 'never_synced' THEN 3
                    WHEN 'starts_soon' THEN 4
                    ELSE 5
                END,
                attention_at ASC,
                priority DESC
             LIMIT 6",
            [$this->storeHash, $this->storeHash]
        );
    }

    private function getSyncSummary(): array {
        $row = $this->db->fetchOne(
            "SELECT MAX(synced_at) AS last_sync
             FROM sync_log
             WHERE store_hash = ?",
            [$this->storeHash]
        );

        return $row ?: ['last_sync' => null];
    }

    private function getPromotionAttentionSummary(): array {
        $row = $this->db->fetchOne(
            "SELECT
                SUM(CASE
                    WHEN p.status = 'active'
                     AND p.start_date <= NOW()
                     AND p.end_date >= NOW()
                     AND DATE(p.end_date) = CURDATE()
                        THEN 1 ELSE 0 END
                ) AS expires_today,
                SUM(CASE
                    WHEN p.status = 'active'
                     AND p.start_date <= NOW()
                     AND p.end_date > NOW()
                     AND DATE(p.end_date) > CURDATE()
                     AND p.end_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                        THEN 1 ELSE 0 END
                ) AS expires_soon,
                SUM(CASE
                    WHEN p.status = 'scheduled'
                     AND p.start_date > NOW()
                     AND p.start_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                        THEN 1 ELSE 0 END
                ) AS starts_soon,
                SUM(CASE
                    WHEN p.status = 'active'
                     AND p.start_date <= NOW()
                     AND p.end_date >= NOW()
                     AND sl.last_sync IS NULL
                        THEN 1 ELSE 0 END
                ) AS never_synced
             FROM promotions p
             LEFT JOIN (
                SELECT promotion_id, MAX(synced_at) AS last_sync
                FROM sync_log
                WHERE store_hash = ?
                GROUP BY promotion_id
             ) sl ON sl.promotion_id = p.id
             WHERE p.store_hash = ?",
            [$this->storeHash, $this->storeHash]
        );

        return [
            'expires_today' => (int)($row['expires_today'] ?? 0),
            'expires_soon' => (int)($row['expires_soon'] ?? 0),
            'starts_soon' => (int)($row['starts_soon'] ?? 0),
            'never_synced' => (int)($row['never_synced'] ?? 0),
        ];
    }

    private function renderView($stats, $promotionAttention) {
        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/dashboard/index.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }
}
