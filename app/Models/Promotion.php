<?php
namespace App\Models;

class Promotion {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureSchema();
    }
    
    public function create($data) {
        $storeHash = $this->db->getStoreContext();
        
        if (!$storeHash) {
            throw new \Exception("Store context required");
        }
        
        $sql = "INSERT INTO promotions 
                (store_hash, name, custom_field_value, discount_percent, start_date, end_date, priority, filters, 
                 target_category_id, target_attribute, color, description, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $status = strtotime($data['start_date']) > time() ? 'scheduled' : 'active';
        
        $this->db->query($sql, [
            $storeHash,
            $data['name'],
            $data['custom_field_value'] ?? $data['name'],
            $data['discount_percent'],
            $data['start_date'],
            $data['end_date'],
            $data['priority'] ?? 0,
            json_encode($data['filters'] ?? []),
            $data['target_category_id'] ?? null,
            $data['target_attribute'] ?? null,
            $data['color'] ?? '#3b82f6',
            $data['description'] ?? '',
            $status
        ]);
        
        return $this->db->lastInsertId();
    }
    
    public function update($id, $data) {
        $storeHash = $this->db->getStoreContext();
        
        $fields = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            if ($key === 'filters') {
                $value = json_encode($value);
            }
            $fields[] = "$key = ?";
            $values[] = $value;
        }
        
        $values[] = $id;
        $values[] = $storeHash;
        
        $sql = "UPDATE promotions SET " . implode(', ', $fields) . " WHERE id = ? AND store_hash = ?";
        return $this->db->query($sql, $values);
    }
    
    public function delete($id) {
        $storeHash = $this->db->getStoreContext();
        return $this->db->query("DELETE FROM promotions WHERE id = ? AND store_hash = ?", [$id, $storeHash]);
    }
    
    public function findById($id) {
        $storeHash = $this->db->getStoreContext();
        return $this->db->fetchOne("SELECT * FROM promotions WHERE id = ? AND store_hash = ?", [$id, $storeHash]);
    }
    
    public function findAll($includeExpired = true) {
        $storeHash = $this->db->getStoreContext();
        
        $sql = "SELECT p.*, 
                COUNT(DISTINCT pp.product_id) as product_count,
                MAX(sl.synced_at) as last_sync
                FROM promotions p
                LEFT JOIN promotion_products pp ON p.id = pp.promotion_id AND pp.store_hash = ?
                LEFT JOIN sync_log sl ON p.id = sl.promotion_id AND sl.store_hash = ?
                WHERE p.store_hash = ?";
        
        $sql .= " GROUP BY p.id ORDER BY 
                  CASE 
                    WHEN p.status = 'active' THEN 1
                    WHEN p.status = 'scheduled' THEN 2
                    WHEN p.status = 'inactive' THEN 3
                    WHEN p.status = 'expired' THEN 4
                    ELSE 5
                  END,
                  p.priority DESC, 
                  p.created_at DESC";
        
        return $this->db->fetchAll($sql, [$storeHash, $storeHash, $storeHash]);
    }
    
    public function findActive() {
        $storeHash = $this->db->getStoreContext();
        $now = date('Y-m-d H:i:s');
        
        return $this->db->fetchAll(
            "SELECT * FROM promotions 
             WHERE store_hash = ?
             AND status = 'active' 
             AND start_date <= ? 
             AND end_date >= ?
             ORDER BY priority DESC, id",
            [$storeHash, $now, $now]
        );
    }
    
    public function updateStatuses() {
        $storeHash = $this->db->getStoreContext();
        $now = date('Y-m-d H:i:s');
        
        // Activate scheduled
        $this->db->query(
            "UPDATE promotions SET status = 'active' 
             WHERE store_hash = ? AND status = 'scheduled' AND start_date <= ?",
            [$storeHash, $now]
        );
        
        // Expire active
        $this->db->query(
            "UPDATE promotions SET status = 'expired' 
             WHERE store_hash = ? AND status = 'active' AND end_date < ?",
            [$storeHash, $now]
        );
    }

    private function ensureSchema() {
        $column = $this->db->fetchOne(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotions' AND COLUMN_NAME = 'custom_field_value'"
        );

        if (!$column) {
            $this->db->query(
                "ALTER TABLE promotions ADD COLUMN custom_field_value VARCHAR(255) NULL AFTER name"
            );
            $this->db->query(
                "UPDATE promotions SET custom_field_value = name WHERE custom_field_value IS NULL OR custom_field_value = ''"
            );
        }
    }
}
