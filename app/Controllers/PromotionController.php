<?php
namespace App\Controllers;

use App\Models\Database;
use App\Models\Promotion;
use App\Services\BigCommerceAPI;
use App\Services\PromotionService;
use App\Services\ProductCacheService;
use App\Support\Csrf;

class PromotionController {
    private $promotionModel;
    private $api;
    private $promotionService;
    private $cacheService;
    
    public function __construct() {
        // Get the shared database instance. It should be aware of the store context from the session.
        $db = Database::getInstance();

        $this->promotionModel = new Promotion();

        // Initialize the API object with credentials from the session for direct use in this controller.
        $this->api = new BigCommerceAPI($_SESSION['store_hash'] ?? null, $_SESSION['access_token'] ?? null);

        // ISPRAVKA: Prosleđujemo instancu baze podataka ($db) servisima.
        // PromotionService očekuje Database objekat, a ne BigCommerceAPI objekat.
        // Ovo osigurava da svi servisi dele istu konekciju i kontekst prodavnice.
        $this->promotionService = new PromotionService($db);
        $this->cacheService = new ProductCacheService($db);
    }
    
    public function index() {
        $promotions = $this->promotionModel->findAll();
        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/promotions/index.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }
    
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validateRequest()) {
                http_response_code(403);
                echo 'Invalid CSRF token';
                return;
            }

            $data = $this->getFormData();
            // Umesto direktnog upisa u model, koristimo servis koji kreira i Job za sync
            $id = $this->promotionService->createPromotion($data);
            
            header('Location: ?route=promotions&success=created');
            exit;
        }
        
        $settings = $this->getStoreSettings();
        $allowedCustomFields = $settings['allowed_filters'] ?? [];
        // Load categories and brands from CACHE
        $categories = $this->api->getCategories();
        $brands = $this->api->getBrands();
        
        // Get cache statistics
        $cacheStats = $this->cacheService->getCacheStats();
        
        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/promotions/create.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }

    public function duplicate() {
        $id = $_GET['id'] ?? null;

        if (!$id) {
            header('Location: ?route=promotions');
            exit;
        }

        $sourcePromotion = $this->promotionModel->findById($id);

        if (!$sourcePromotion) {
            header('Location: ?route=promotions');
            exit;
        }

        $settings = $this->getStoreSettings();
        $allowedCustomFields = $settings['allowed_filters'] ?? [];
        $categories = $this->api->getCategories();
        $brands = $this->api->getBrands();
        $cacheStats = $this->cacheService->getCacheStats();
        $duplicatePromotion = $this->buildDuplicatePromotionDraft($sourcePromotion);

        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/promotions/create.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }
    
    public function edit() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            header('Location: ?route=promotions');
            exit;
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validateRequest()) {
                http_response_code(403);
                echo 'Invalid CSRF token';
                return;
            }

            $data = $this->getFormData();
            $this->promotionService->updatePromotion($id, $data);
            
            header('Location: ?route=promotions&success=updated');
            exit;
        }

        $settings = $this->getStoreSettings();
        $allowedCustomFields = $settings['allowed_filters'] ?? [];
        
        $promotion = $this->promotionModel->findById($id);
        $categories = $this->api->getCategories();
        $brands = $this->api->getBrands();
        
        include __DIR__ . '/../Views/layouts/header.php';
        include __DIR__ . '/../Views/promotions/edit.php';
        include __DIR__ . '/../Views/layouts/footer.php';
    }
    
    public function delete() {
        $id = $_GET['id'] ?? null;
        
        if ($id) {
            $this->promotionService->deletePromotion($id);
        }
        
        header('Location: ?route=promotions&success=deleted');
        exit;
    }
    
    /**
     * NOVO: Preview products before creating promotion
     */
    public function preview() {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            return;
        }
        
        $filters = $this->getSubmittedFilters();
        $discountPercent = floatval($_POST['discount_percent'] ?? 0);
        
        try {
            $preview = $this->promotionService->previewPromotionProducts($filters, $discountPercent);
            echo json_encode(['success' => true, 'data' => $preview]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * NOVO: Get filter statistics in real-time
     */
    public function filterStats() {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            return;
        }
        
        $filters = $this->getSubmittedFilters();
        
        try {
            $stats = $this->promotionService->getFilterStats($filters);
            echo json_encode(['success' => true, 'data' => $stats]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function customFieldOptions() {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            return;
        }

        $fieldName = $this->normalizeEscapedUnicodeString(trim((string)($_POST['field_name'] ?? '')));
        $query = $this->normalizeEscapedUnicodeString(trim((string)($_POST['q'] ?? '')));
        $limit = max(1, min(100, (int)($_POST['limit'] ?? 50)));

        $allowedCustomFields = $this->getStoreSettings()['allowed_filters'] ?? [];
        if ($fieldName === '' || !in_array($fieldName, $allowedCustomFields, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid custom field']);
            return;
        }

        try {
            $options = $this->cacheService->getCustomFieldFilterValues($fieldName, $query, $limit);
            echo json_encode(['success' => true, 'data' => ['options' => $options]]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function productOptions() {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::validateRequest()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            return;
        }

        $query = $this->normalizeEscapedUnicodeString(trim((string)($_POST['q'] ?? '')));
        $limit = max(1, min(100, (int)($_POST['limit'] ?? 50)));

        try {
            if (!empty($_POST['select_all_search'])) {
                echo json_encode(['success' => true, 'data' => $this->cacheService->getProductFilterSkusForSearch($query)]);
                return;
            }

            if (isset($_POST['page']) || isset($_POST['per_page'])) {
                $page = max(1, (int)($_POST['page'] ?? 1));
                $perPage = max(1, min(100, (int)($_POST['per_page'] ?? 50)));
                echo json_encode(['success' => true, 'data' => $this->cacheService->getProductFilterPage($query, $page, $perPage)]);
                return;
            }

            $options = $this->cacheService->getProductFilterOptions($query, $limit);
            echo json_encode(['success' => true, 'data' => ['options' => $options]]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    private function getFormData() {
        return [
            'name' => $_POST['name'] ?? '',
            'custom_field_value' => $_POST['custom_field_value'] ?? ($_POST['name'] ?? ''),
            'discount_percent' => $_POST['discount_percent'] ?? 0,
            'start_date' => $_POST['start_date'] ?? date('Y-m-d H:i:s'),
            'end_date' => $_POST['end_date'] ?? date('Y-m-d H:i:s', strtotime('+7 days')),
            'priority' => $_POST['priority'] ?? 0,
            'filters' => $this->getSubmittedFilters(),
            'color' => $_POST['color'] ?? '#3b82f6',
            'description' => $_POST['description'] ?? '',
            'target_category_id' => $_POST['target_category_id'] ?? null
        ];
    }

    private function buildDuplicatePromotionDraft(array $promotion): array {
        $duplicate = $promotion;
        $sourceName = trim((string)($promotion['name'] ?? ''));

        $duplicate['id'] = null;
        $duplicate['name'] = ($sourceName !== '' ? $sourceName : 'Promocija') . ' (kopija)';
        $duplicate['custom_field_value'] = $promotion['custom_field_value'] ?? $promotion['name'] ?? '';
        $duplicate['description'] = $promotion['description'] ?? '';
        $duplicate['filters'] = $promotion['filters'] ?? '{}';

        return $duplicate;
    }

    private function getSubmittedFilters() {
        $filters = json_decode($_POST['filters'] ?? '{}', true);
        return $this->normalizeFilters(is_array($filters) ? $filters : []);
    }

    private function normalizeFilters(array $filters) {
        $normalizedFilters = [];

        foreach ($filters as $key => $value) {
            if ($key === 'exclude' && is_array($value)) {
                $normalizedExcludeFilters = $this->normalizeFilters($value);
                if (!empty($normalizedExcludeFilters)) {
                    $normalizedFilters['exclude'] = $normalizedExcludeFilters;
                }
                continue;
            }

            $normalizedKey = $key;

            if (strpos($key, 'custom_field:') === 0) {
                $normalizedKey = 'custom_field:' . $this->normalizeEscapedUnicodeString(substr($key, 13));
            }

            if (
                in_array($key, ['categories:in', 'brand_id', 'sku:in'], true) ||
                (strpos($key, 'custom_field:') === 0 && is_array($value))
            ) {
                $normalizedFilters[$normalizedKey] = $this->normalizeMultiSelectFilterValues($value);
            } else {
                $normalizedFilters[$normalizedKey] = is_string($value)
                    ? $this->normalizeEscapedUnicodeString(trim($value))
                    : $value;
            }
        }

        return $normalizedFilters;
    }

    private function normalizeMultiSelectFilterValues($value) {
        if (is_array($value)) {
            return array_values(array_filter(array_map(function($item) {
                return $this->normalizeEscapedUnicodeString(trim((string)$item));
            }, $value), function($item) {
                return $item !== '';
            }));
        }

        if ($value === null || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map(function($item) {
            return $this->normalizeEscapedUnicodeString(trim((string)$item));
        }, explode(',', (string)$value)), function($item) {
            return $item !== '';
        }));
    }

    private function normalizeEscapedUnicodeString(string $value): string {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($matches) {
            return json_decode('"\\u' . $matches[1] . '"');
        }, trim($value));
    }

    private function getStoreSettings() {
        $db = \App\Models\Database::getInstance();
        $data = $db->fetchOne("SELECT settings FROM bigcommerce_stores WHERE store_hash = ?", [$db->getStoreContext()]);
        $settings = json_decode($data['settings'] ?? '{}', true);

        if (!is_array($settings)) {
            return [];
        }

        $allowedFilters = $settings['allowed_filters'] ?? [];
        $normalizedAllowedFilters = [];

        foreach ($allowedFilters as $filterName) {
            $normalizedFilterName = $this->normalizeEscapedUnicodeString((string)$filterName);
            if ($normalizedFilterName !== '' && !in_array($normalizedFilterName, $normalizedAllowedFilters, true)) {
                $normalizedAllowedFilters[] = $normalizedFilterName;
            }
        }

        $settings['allowed_filters'] = $normalizedAllowedFilters;
        return $settings;
    }
}
