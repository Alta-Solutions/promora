<?php
namespace App\Models;

use PDO;
use PDOException;

class Database {
    private static $instance = null;
    private $pdo;
    private $storeHash = null;
    
    private function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . \Config::$DB_HOST . ";dbname=" . \Config::$DB_NAME . ";charset=utf8mb4",
                \Config::$DB_USER,
                \Config::$DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            // Set store context from session
            if (isset($_SESSION['store_hash'])) {
                $this->storeHash = $_SESSION['store_hash'];
            }
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Set store context (for multi-tenant operations)
     */
    public function setStoreContext($storeHash) {
        $this->storeHash = $storeHash;
    }
    
    /**
     * Get current store context
     */
    public function getStoreContext() {
        return $this->storeHash;
    }
    
    /**
     * Validate store context is set
     */
    private function requireStoreContext() {
        if (empty($this->storeHash)) {
            throw new \Exception("Store context not set. Multi-tenant operation requires store_hash.");
        }
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            $placeholderCount = substr_count($sql, '?');
            $paramCount = is_array($params) ? count($params) : 0;
            $sqlPreview = preg_replace('/\s+/', ' ', trim($sql));
            $sqlPreview = mb_substr($sqlPreview, 0, 300);

            throw new \Exception(
                $e->getMessage() .
                " | placeholders={$placeholderCount} params={$paramCount} | SQL={$sqlPreview}",
                0,
                $e
            );
        }
    }
    
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    public function commit() {
        return $this->pdo->commit();
    }
    
    public function rollback() {
        return $this->pdo->rollBack();
    }
}
