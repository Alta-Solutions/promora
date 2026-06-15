<?php
namespace App\Controllers;

use App\Models\Database;
use App\Models\PromotionArchive;
use App\Services\PromotionArchiveService;

class LogsController {
    private const ARCHIVE_PER_PAGE_OPTIONS = [10, 25, 50, 100];
    
    public function index() {
        $db = Database::getInstance();
        $storeHash = $db->getStoreContext();

        // Dohvati poslednjih 100 logova
        $logs = $db->fetchAll(
            "SELECT * FROM sync_log WHERE store_hash = ? ORDER BY synced_at DESC LIMIT 100",
            [$storeHash]
        );

        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/logs/index.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }

    public function webhooks() {
        $db = Database::getInstance();
        $storeHash = $db->getStoreContext();

        // Dohvati poslednjih 100 webhook događaja
        $logs = $db->fetchAll(
            "SELECT webhook_events.*, created_at AS received_at
             FROM webhook_events
             WHERE store_hash = ?
             ORDER BY created_at DESC
             LIMIT 100",
            [$storeHash]
        );

        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/logs/webhooks.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }

    public function corrections() {
        $db = Database::getInstance();
        $storeHash = $db->getStoreContext();
        $logs = [];

        $revisionTable = $db->fetchOne(
            "SELECT TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'promotion_revisions'"
        );

        if ($revisionTable) {
            $logs = $db->fetchAll(
                "SELECT pr.*, p.name AS promotion_name
                 FROM promotion_revisions pr
                 LEFT JOIN promotions p
                    ON p.id = pr.promotion_id
                   AND p.store_hash = ?
                 WHERE pr.store_hash = ?
                 ORDER BY pr.created_at DESC, pr.id DESC
                 LIMIT 100",
                [$storeHash, $storeHash]
            );
        }

        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/logs/corrections.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }

    public function promotions() {
        $db = Database::getInstance();
        $storeHash = $db->getStoreContext();
        $archiveService = new PromotionArchiveService($db, $storeHash);
        $archiveService->backfillExistingExpiredPromotions();

        $archiveModel = new PromotionArchive($db);
        $filters = $this->getArchiveFilters();
        $perPage = $this->getPerPage();
        $page = max(1, (int)$this->getQueryParam('page', '1'));
        $totalArchives = $archiveModel->countAll($filters);
        $totalPages = max(1, (int)ceil($totalArchives / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $archives = $archiveModel->findAll($filters + [
            'limit' => $perPage,
            'offset' => $offset,
        ]);
        $pagination = [
            'page' => $page,
            'per_page' => $perPage,
            'per_page_options' => self::ARCHIVE_PER_PAGE_OPTIONS,
            'total' => $totalArchives,
            'total_pages' => $totalPages,
            'from' => $totalArchives > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $totalArchives),
        ] + $filters;

        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/logs/promotions.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }

    public function promotionArchive() {
        $db = Database::getInstance();
        $storeHash = $db->getStoreContext();
        $archiveService = new PromotionArchiveService($db, $storeHash);
        $archiveService->backfillExistingExpiredPromotions();

        $archiveModel = new PromotionArchive($db);
        $archiveId = max(0, (int)$this->getQueryParam('id', '0'));
        $archive = $archiveId > 0 ? $archiveModel->findById($archiveId) : null;

        if (!$archive) {
            http_response_code(404);
            echo \trans('common.page_not_found');
            return;
        }

        $archive['filters_text'] = $archiveService->buildArchiveFiltersText($archive['filters'] ?? []);

        $search = $this->normalizeSearch($this->getQueryParam('q'));
        $perPage = $this->getPerPage(50);
        $page = max(1, (int)$this->getQueryParam('page', '1'));
        $productOptions = ['search' => $search];
        $totalProducts = $archiveModel->countProducts($archiveId, $productOptions);
        $totalPages = max(1, (int)ceil($totalProducts / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $products = $archiveModel->findProducts($archiveId, $productOptions + [
            'limit' => $perPage,
            'offset' => $offset,
        ]);
        $pagination = [
            'page' => $page,
            'per_page' => $perPage,
            'per_page_options' => self::ARCHIVE_PER_PAGE_OPTIONS,
            'total' => $totalProducts,
            'total_pages' => $totalPages,
            'from' => $totalProducts > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $totalProducts),
            'search' => $search,
        ];

        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/logs/promotion_archive.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }

    private function getArchiveFilters(): array {
        return [
            'search' => $this->normalizeSearch($this->getQueryParam('q')),
            'date_from' => $this->normalizeDate($this->getQueryParam('date_from')),
            'date_to' => $this->normalizeDate($this->getQueryParam('date_to')),
        ];
    }

    private function getPerPage(int $default = 25): int {
        $perPage = (int)$this->getQueryParam('per_page', (string)$default);

        return in_array($perPage, self::ARCHIVE_PER_PAGE_OPTIONS, true)
            ? $perPage
            : $default;
    }

    private function getQueryParam(string $key, string $default = ''): string {
        $value = $_GET[$key] ?? $default;

        return is_scalar($value) ? (string)$value : $default;
    }

    private function normalizeSearch(string $search): string {
        $search = trim($this->normalizeEscapedUnicodeString($search));

        return function_exists('mb_substr')
            ? mb_substr($search, 0, 120)
            : substr($search, 0, 120);
    }

    private function normalizeDate(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function normalizeEscapedUnicodeString(string $value): string {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($matches) {
            return json_decode('"\\u' . $matches[1] . '"');
        }, trim($value));
    }
}
