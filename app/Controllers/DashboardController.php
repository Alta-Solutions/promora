<?php
namespace App\Controllers;

use App\Models\Promotion;
use App\Models\Database;

class DashboardController {
    private $promotionModel;
    private $db;
    private $storeHash;
    
    public function __construct() {
        $this->promotionModel = new Promotion();
        $this->db = Database::getInstance();
        $this->storeHash = $this->db->getStoreContext();
        
        if (!$this->storeHash) {
            throw new \Exception("Store hash nije dostupan. Kontekst prodavnice je obavezan.");
        }
    }
    
    public function index() {
        // Get statistics
        $stats = $this->getStats();
        
        // Get active promotions (pretpostavlja se da Promotion model interno koristi storeHash)
        $activePromotions = $this->promotionModel->findActive();
        
        // Render view
        $this->renderView($stats, $activePromotions);
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
    
    private function renderView($stats, $activePromotions) {
        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/dashboard/index.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }
}