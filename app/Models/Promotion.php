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
                 color, description, status, created_at, omnibus_terms_updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
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
    
    public function findAll($includeExpired = true, array $options = []) {
        $storeHash = $this->db->getStoreContext();
        $search = $this->normalizeSearch($options['search'] ?? '');
        $statusFilter = $this->normalizeListStatusFilter($options['status_filter'] ?? null);
        $limit = isset($options['limit']) ? max(1, (int)$options['limit']) : null;
        $offset = isset($options['offset']) ? max(0, (int)$options['offset']) : 0;
        $whereParams = [];
        $whereSql = $this->buildListWhereClause((bool)$includeExpired, $storeHash, $search, $statusFilter, $whereParams);
        
        $sql = "SELECT p.*, 
                COUNT(DISTINCT pp.product_id) as product_count,
                MAX(sl.synced_at) as last_sync
                FROM promotions p
                LEFT JOIN promotion_products pp ON p.id = pp.promotion_id AND pp.store_hash = ?
                LEFT JOIN sync_log sl ON p.id = sl.promotion_id AND sl.store_hash = ?
                WHERE {$whereSql}";
        
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

        if ($limit !== null) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }
        
        return $this->db->fetchAll($sql, array_merge([$storeHash, $storeHash], $whereParams));
    }

    public function countAll($includeExpired = true, string $search = '', array $options = []): int {
        $storeHash = $this->db->getStoreContext();
        $statusFilter = $this->normalizeListStatusFilter($options['status_filter'] ?? null);
        $whereParams = [];
        $whereSql = $this->buildListWhereClause((bool)$includeExpired, $storeHash, $this->normalizeSearch($search), $statusFilter, $whereParams);

        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt FROM promotions p WHERE {$whereSql}",
            $whereParams
        );

        return (int)($row['cnt'] ?? 0);
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

        $descriptionColumn = $this->db->fetchOne(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotions' AND COLUMN_NAME = 'description'"
        );

        if (!$descriptionColumn) {
            $this->db->query(
                "ALTER TABLE promotions ADD COLUMN description TEXT NULL AFTER color"
            );
        }

        $omnibusTermsColumn = $this->db->fetchOne(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotions' AND COLUMN_NAME = 'omnibus_terms_updated_at'"
        );

        if (!$omnibusTermsColumn) {
            $this->db->query(
                "ALTER TABLE promotions ADD COLUMN omnibus_terms_updated_at DATETIME NULL AFTER created_at"
            );
        }

        $firstAppliedAtColumn = $this->db->fetchOne(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotion_products' AND COLUMN_NAME = 'first_applied_at'"
        );

        if (!$firstAppliedAtColumn) {
            $this->db->query(
                "ALTER TABLE promotion_products ADD COLUMN first_applied_at DATETIME NULL AFTER custom_field_id"
            );
        }

        $omnibusReferenceAtColumn = $this->db->fetchOne(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotion_products' AND COLUMN_NAME = 'omnibus_reference_at'"
        );

        if (!$omnibusReferenceAtColumn) {
            $this->db->query(
                "ALTER TABLE promotion_products ADD COLUMN omnibus_reference_at DATETIME NULL AFTER first_applied_at"
            );
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS promotion_revisions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                store_hash VARCHAR(255) NOT NULL,
                promotion_id INT NOT NULL,
                change_type VARCHAR(50) NOT NULL,
                reason TEXT NOT NULL,
                actor_source VARCHAR(50) NOT NULL,
                actor_user_id VARCHAR(255) NULL,
                actor_email VARCHAR(255) NULL,
                actor_is_owner TINYINT(1) NOT NULL DEFAULT 0,
                old_discount_percent DECIMAL(5, 2) NOT NULL,
                new_discount_percent DECIMAL(5, 2) NOT NULL,
                old_terms LONGTEXT NULL,
                new_terms LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_store_promotion_created (store_hash, promotion_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function buildListWhereClause(bool $includeExpired, ?string $storeHash, string $search, ?string $statusFilter, array &$params): string {
        $where = ["p.store_hash = ?"];
        $params[] = $storeHash;

        if (!$includeExpired) {
            $where[] = "p.status != 'expired'";
        }

        if ($statusFilter === 'current') {
            $where[] = "(p.status IN ('active', 'scheduled') AND p.end_date >= NOW())";
        } elseif ($statusFilter === 'expired') {
            $where[] = "(p.status = 'expired' OR p.end_date < NOW())";
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(
                p.name LIKE ?
                OR COALESCE(p.custom_field_value, '') LIKE ?
                OR COALESCE(p.description, '') LIKE ?
                OR p.status LIKE ?
                OR CAST(p.id AS CHAR) LIKE ?
                OR CAST(p.priority AS CHAR) LIKE ?
                OR CAST(p.discount_percent AS CHAR) LIKE ?
            )";
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        return implode(' AND ', $where);
    }

    private function normalizeSearch($search): string {
        $search = trim((string)$search);

        if ($search === '') {
            return '';
        }

        return function_exists('mb_substr')
            ? mb_substr($search, 0, 120)
            : substr($search, 0, 120);
    }

    private function normalizeListStatusFilter($statusFilter): ?string {
        $statusFilter = (string)$statusFilter;

        return in_array($statusFilter, ['current', 'expired', 'all'], true)
            ? $statusFilter
            : null;
    }
}
