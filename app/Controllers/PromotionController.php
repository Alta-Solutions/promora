<?php
namespace App\Controllers;

use App\Models\Database;
use App\Models\Promotion;
use App\Services\BigCommerceAPI;
use App\Services\PromotionService;
use App\Services\ProductCacheService;
use App\Services\QueueService;
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
            $this->promotionModel->update($id, $data);
            
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
            // 1. Provera da li ima proizvoda vezanih za ovu promociju
            $db = \App\Models\Database::getInstance();
            $count = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM promotion_products WHERE promotion_id = ?", 
                [$id]
            );
            
            if ($count['cnt'] > 0) {
                // 2. Ako ima proizvoda, kreiraj posao za čišćenje (Async)
                $queue = new QueueService();
                $queue->createJob('cleanup_single', $id, $count['cnt']);
                
                // 3. Postavi status na 'expired' umesto brisanja (dok worker ne završi)
                $this->promotionModel->update($id, ['status' => 'expired']);
            } else {
                // 4. Ako nema proizvoda, brišemo odmah
                $this->promotionModel->delete($id);
            }
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
        
        $filters = json_decode($_POST['filters'] ?? '{}', true);
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
        
        $filters = json_decode($_POST['filters'] ?? '{}', true);
        
        try {
            $stats = $this->promotionService->getFilterStats($filters);
            echo json_encode(['success' => true, 'data' => $stats]);
        } catch (\Exception $e) {
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
            'filters' => json_decode($_POST['filters'] ?? '{}', true),
            'color' => $_POST['color'] ?? '#3b82f6',
            'description' => $_POST['description'] ?? '',
            'target_category_id' => $_POST['target_category_id'] ?? null
        ];
    }

    private function getStoreSettings() {
        $db = \App\Models\Database::getInstance();
        $data = $db->fetchOne("SELECT settings FROM bigcommerce_stores WHERE store_hash = ?", [$db->getStoreContext()]);
        return json_decode($data['settings'] ?? '{}', true);
    }
}
