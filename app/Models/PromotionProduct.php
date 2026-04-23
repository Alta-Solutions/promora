<?php
namespace App\Models;

use App\Models\Database;

class PromotionProduct {
    private $db;
    private $storeHash;
    
    
    public function __construct() {
        $this->db = Database::getInstance();
        
        $this->storeHash = $this->db->getStoreContext(); 
        
        if (!$this->storeHash) {
            throw new \Exception("Store hash nije dostupan. Kontekst prodavnice je obavezan.");
        }
    }
    
    public function create($data) {
        $sql = "INSERT INTO promotion_products 
                (store_hash, promotion_id, product_id, original_price, promo_price, custom_field_id, synced_at)
                VALUES (?, ?, ?, ?, ?, ?)";
        
        return $this->db->query($sql, [
            $this->storeHash,
            $data['promotion_id'],
            $data['product_id'],
            $data['original_price'],
            $data['promo_price'],
            $data['custom_field_id'] ?? null,
            NOW()
        ]);
    }
    
    public function update($promotionId, $productId, $data) {
        $sql = "UPDATE promotion_products 
                SET original_price = ?, promo_price = ?, custom_field_id = ?, synced_at = NOW()
                WHERE promotion_id = ? AND product_id = ? AND store_hash = ?";
        
        return $this->db->query($sql, [
            $data['original_price'],
            $data['promo_price'],
            $data['custom_field_id'] ?? null,
            $promotionId,
            $productId,
            $this->storeHash
        ]);
    }
    
    public function findByPromotion($promotionId) {
        return $this->db->fetchAll(
            "SELECT * FROM promotion_products WHERE promotion_id = ? AND store_hash = ? ORDER BY synced_at DESC",
            [$promotionId, $this->storeHash]
        );
    }
    
    public function findByProduct($productId) {
        $sql = "SELECT pp.*, p.name as promotion_name 
                FROM promotion_products pp
                JOIN promotions p ON pp.promotion_id = p.id
                WHERE pp.product_id = ? AND pp.store_hash = ? AND p.store_hash = ?";
        
        return $this->db->fetchAll(
            $sql,
            [$productId, $this->storeHash, $this->storeHash]
        );
    }
    
    public function delete($promotionId, $productId) {
        return $this->db->query(
            "DELETE FROM promotion_products WHERE promotion_id = ? AND product_id = ? AND store_hash = ?",
            [$promotionId, $productId, $this->storeHash]
        );
    }
    
    public function deleteByPromotion($promotionId) {
        return $this->db->query(
            "DELETE FROM promotion_products WHERE promotion_id = ? AND store_hash = ?",
            [$promotionId, $this->storeHash]
        );
    }
}