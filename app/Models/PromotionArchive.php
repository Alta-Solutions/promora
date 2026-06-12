<?php
namespace App\Models;

use App\Services\PromotionArchiveService;

class PromotionArchive {
    private $db;
    private string $storeHash;

    public function __construct($db = null) {
        $this->db = $db ?? Database::getInstance();
        $this->storeHash = (string)$this->db->getStoreContext();

        if ($this->storeHash === '') {
            throw new \Exception("Store context required for promotion archive.");
        }

        PromotionArchiveService::ensureSchema($this->db);
    }

    public function findAll(array $options = []): array {
        $search = $this->normalizeSearch($options['search'] ?? '');
        $dateFrom = $this->normalizeDate($options['date_from'] ?? '');
        $dateTo = $this->normalizeDate($options['date_to'] ?? '');
        $limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 25;
        $offset = isset($options['offset']) ? max(0, (int)$options['offset']) : 0;
        $params = [];
        $whereSql = $this->buildArchiveWhereClause($search, $dateFrom, $dateTo, $params);

        return $this->db->fetchAll(
            "SELECT pa.*
             FROM promotion_archives pa
             WHERE {$whereSql}
             ORDER BY pa.end_date DESC, pa.archived_at DESC, pa.id DESC
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
            $params
        );
    }

    public function countAll(array $options = []): int {
        $search = $this->normalizeSearch($options['search'] ?? '');
        $dateFrom = $this->normalizeDate($options['date_from'] ?? '');
        $dateTo = $this->normalizeDate($options['date_to'] ?? '');
        $params = [];
        $whereSql = $this->buildArchiveWhereClause($search, $dateFrom, $dateTo, $params);
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM promotion_archives pa
             WHERE {$whereSql}",
            $params
        );

        return (int)($row['cnt'] ?? 0);
    }

    public function findById(int $id) {
        return $this->db->fetchOne(
            "SELECT *
             FROM promotion_archives
             WHERE store_hash = ? AND id = ?
             LIMIT 1",
            [$this->storeHash, $id]
        );
    }

    public function findProducts(int $archiveId, array $options = []): array {
        $search = $this->normalizeSearch($options['search'] ?? '');
        $limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 50;
        $offset = isset($options['offset']) ? max(0, (int)$options['offset']) : 0;
        $params = [$this->storeHash, $archiveId];
        $where = "pph.store_hash = ? AND pph.archive_id = ?";

        if ($search !== '') {
            $where .= $this->buildProductSearchClause($search, $params);
        }

        return $this->db->fetchAll(
            "SELECT pph.*
             FROM promotion_product_history pph
             WHERE {$where}
             ORDER BY pph.applied_at DESC, pph.product_id ASC, pph.variant_id ASC, pph.id DESC
             LIMIT " . (int)$limit . " OFFSET " . (int)$offset,
            $params
        );
    }

    public function countProducts(int $archiveId, array $options = []): int {
        $search = $this->normalizeSearch($options['search'] ?? '');
        $params = [$this->storeHash, $archiveId];
        $where = "pph.store_hash = ? AND pph.archive_id = ?";

        if ($search !== '') {
            $where .= $this->buildProductSearchClause($search, $params);
        }

        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM promotion_product_history pph
             WHERE {$where}",
            $params
        );

        return (int)($row['cnt'] ?? 0);
    }

    private function buildArchiveWhereClause(string $search, string $dateFrom, string $dateTo, array &$params): string {
        $where = ["pa.store_hash = ?"];
        $params[] = $this->storeHash;

        if ($dateFrom !== '') {
            $where[] = "pa.end_date >= ?";
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== '') {
            $where[] = "pa.start_date <= ?";
            $params[] = $dateTo . ' 23:59:59';
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(
                pa.name LIKE ?
                OR COALESCE(pa.custom_field_value, '') LIKE ?
                OR COALESCE(pa.description, '') LIKE ?
                OR COALESCE(pa.filters_text, '') LIKE ?
                OR CAST(pa.promotion_id AS CHAR) LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM promotion_product_history pph
                    WHERE pph.store_hash = ?
                      AND pph.promotion_id = pa.promotion_id
                      AND (
                        COALESCE(pph.product_name, '') LIKE ?
                        OR COALESCE(pph.sku, '') LIKE ?
                        OR CAST(pph.product_id AS CHAR) LIKE ?
                        OR CAST(COALESCE(pph.variant_id, 0) AS CHAR) LIKE ?
                      )
                    LIMIT 1
                )
            )";
            array_push($params, $like, $like, $like, $like, $like, $this->storeHash, $like, $like, $like, $like);
        }

        return implode(' AND ', $where);
    }

    private function buildProductSearchClause(string $search, array &$params): string {
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);

        return " AND (
            COALESCE(pph.product_name, '') LIKE ?
            OR COALESCE(pph.sku, '') LIKE ?
            OR COALESCE(pph.removal_reason, '') LIKE ?
            OR CAST(pph.product_id AS CHAR) LIKE ?
            OR CAST(COALESCE(pph.variant_id, 0) AS CHAR) LIKE ?
        )";
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

    private function normalizeDate($value): string {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
