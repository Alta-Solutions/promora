<?php
namespace App\Models;

use App\Models\Database;

class SyncLog {
    private $db;
    private $storeHash;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->storeHash = $this->db->getStoreContext();
        
        if (!$this->storeHash) {
            throw new \Exception("Store hash nije dostupan za SyncLog.");
        }
    }
    
    public function create($data) {
        $sql = "INSERT INTO sync_log 
                (store_hash, promotion_id, products_synced, products_added, products_removed, errors, duration_seconds, log_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        return $this->db->query($sql, [
            $this->storeHash,
            $data['promotion_id'] ?? null,
            $data['products_synced'] ?? 0,
            $data['products_added'] ?? 0,
            $data['products_removed'] ?? 0,
            $data['errors'] ?? 0,
            $data['duration_seconds'] ?? 0,
            $data['log_message'] ?? ''
        ]);
    }
    
    public function findAll($limit = 100) {
        $sql = "SELECT sl.*, p.name as promotion_name 
                FROM sync_log sl
                LEFT JOIN promotions p ON sl.promotion_id = p.id AND p.store_hash = ?
                WHERE sl.store_hash = ?
                ORDER BY sl.synced_at DESC 
                LIMIT ?";
                
        return $this->db->fetchAll(
            $sql,
            [$this->storeHash, $this->storeHash, $limit]
        );
    }
    
    public function findById($id) {
        $sql = "SELECT sl.*, p.name as promotion_name 
                FROM sync_log sl
                LEFT JOIN promotions p ON sl.promotion_id = p.id AND p.store_hash = ?
                WHERE sl.id = ? AND sl.store_hash = ?";
                
        return $this->db->fetchOne(
            $sql,
            [$this->storeHash, $id, $this->storeHash]
        );
    }
    
    public function findByPromotion($promotionId, $limit = 50) {
        return $this->db->fetchAll(
            "SELECT * FROM sync_log 
             WHERE promotion_id = ? AND store_hash = ?
             ORDER BY synced_at DESC 
             LIMIT ?",
            [$promotionId, $this->storeHash, $limit]
        );
    }
    
    public function getStats() {
        return [
            'total_syncs' => $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM sync_log WHERE store_hash = ?", 
                [$this->storeHash]
            )['cnt'],
            
            'total_products_synced' => $this->db->fetchOne(
                "SELECT SUM(products_synced) as total FROM sync_log WHERE store_hash = ?", 
                [$this->storeHash]
            )['total'] ?? 0,
            
            'total_errors' => $this->db->fetchOne(
                "SELECT SUM(errors) as total FROM sync_log WHERE store_hash = ?", 
                [$this->storeHash]
            )['total'] ?? 0,
            
            'last_sync' => $this->db->fetchOne(
                "SELECT MAX(synced_at) as last_sync FROM sync_log WHERE store_hash = ?", 
                [$this->storeHash]
            )['last_sync']
        ];
    }
}