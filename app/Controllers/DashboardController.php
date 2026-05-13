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
        return [
            'total_promotions' => $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM promotions WHERE store_hash = ?", 
                [$this->storeHash]
            )['cnt'],
            
            'active_promotions' => $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM promotions WHERE status = 'active' AND store_hash = ?", 
                [$this->storeHash]
            )['cnt'],
            
            'total_products' => $this->db->fetchOne(
                "SELECT COUNT(DISTINCT product_id) as cnt FROM promotion_products WHERE store_hash = ?", 
                [$this->storeHash]
            )['cnt'],
            
            'last_sync' => $this->db->fetchOne(
                "SELECT MAX(synced_at) as last_sync FROM sync_log WHERE store_hash = ?", 
                [$this->storeHash]
            )['last_sync']
        ];
    }
    
    private function getPromotionAttention(): array {
        return [
            'summary' => [
                'expires_today' => $this->countPromotions(
                    "status = 'active'
                     AND start_date <= NOW()
                     AND end_date >= NOW()
                     AND DATE(end_date) = CURDATE()"
                ),
                'expires_soon' => $this->countPromotions(
                    "status = 'active'
                     AND start_date <= NOW()
                     AND end_date > NOW()
                     AND DATE(end_date) > CURDATE()
                     AND end_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)"
                ),
                'starts_soon' => $this->countPromotions(
                    "status = 'scheduled'
                     AND start_date > NOW()
                     AND start_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)"
                ),
                'never_synced' => $this->countNeverSyncedActivePromotions(),
            ],
            'items' => $this->getPromotionAttentionItems(),
        ];
    }

    private function countPromotions(string $whereSql): int {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM promotions WHERE store_hash = ? AND {$whereSql}",
            [$this->storeHash]
        );

        return (int)($row['cnt'] ?? 0);
    }

    private function countNeverSyncedActivePromotions(): int {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt
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
               AND p.status = 'active'
               AND p.start_date <= NOW()
               AND p.end_date >= NOW()
               AND sl.last_sync IS NULL",
            [$this->storeHash, $this->storeHash]
        );

        return (int)($row['cnt'] ?? 0);
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

    private function renderView($stats, $promotionAttention) {
        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/dashboard/index.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }
}
