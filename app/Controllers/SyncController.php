<?php
namespace App\Controllers;

use App\Models\Promotion;
use App\Models\Database;
use App\Services\PromotionService;
use App\Services\ProductCacheService;
use App\Services\QueueService;

class SyncController {
    private $promotionService;
    private $promotionModel;
    private $cacheService;

    public function __construct() {
        $this->promotionService = new PromotionService();
        $this->promotionModel = new Promotion();
        $this->cacheService = new ProductCacheService();
    }
    
    public function index() {
        header('Content-Type: application/json');
        
        try {
            $result = $this->promotionService->syncAllPromotions();
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    public function cronJob() {
        // Verify cron key
        if (!isset($_GET['key']) || $_GET['key'] !== \Config::$SECRET_CRON_KEY) {
            http_response_code(401);
            die('Unauthorized');
        }
        
        header('Content-Type: application/json');
        
        try {
            $result = $this->promotionService->syncAllPromotions();
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function single() {
        $promotionId = $_GET['id'] ?? null;
        
        if (!$promotionId) {
            echo json_encode(['success' => false, 'error' => 'Missing Promotion ID']);
            return;
        }

        try {
            // Opcija A: Direktno (za male prodavnice)
            // $result = $this->promotionService->syncSinglePromotion($promotionId);
            
            // Opcija B: Preko Queue (Preporučeno)
            $queue = new \App\Services\QueueService();
            $jobId = $queue->createJob('single_sync', $promotionId);
            
            echo json_encode(['success' => true, 'job_id' => $jobId]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function startSingle() {
        $promotionId = $_GET['id'] ?? null;
        
        // Provera da li ID postoji
        if (!$promotionId) {
            echo json_encode(['success' => false, 'error' => 'Missing ID']);
            return;
        }

        $promotion = $this->promotionModel->findById($promotionId);
        
        // Provera da li je promocija istekla ili deaktivirana
        $isExpired = ($promotion['status'] !== 'active') || 
                     (strtotime($promotion['end_date']) < time());

        $db = \App\Models\Database::getInstance();
        $queue = new \App\Services\QueueService();
        
        if ($isExpired) {
            // Ako je istekla, brojimo koliko proizvoda treba UKLONITI (onih koji su već u bazi)
            $count = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM promotion_products WHERE promotion_id = ?", 
                [$promotionId]
            );
            $totalItems = $count['cnt'];
            $jobType = 'cleanup_single';
        } else {
            // Ako je aktivna, brojimo koliko proizvoda treba DODATI/AŽURIRATI po filterima
            $filters = json_decode($promotion['filters'], true);
            $cacheService = new \App\Services\ProductCacheService();
            $totalItems = $cacheService->countProductsByFilters($filters);
            $jobType = 'single_sync';
        }
        
        // Kreiraj Job u bazi (Queue) sa odgovarajućim tipom
        $jobId = $queue->createJob($jobType, $promotionId, $totalItems > 0 ? $totalItems : 1);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'job_id' => $jobId, 
            'total' => $totalItems,
            'type' => $jobType
        ]);
    }

    // U SyncController.php
    public function getActiveJobStatus() {
        header('Content-Type: application/json');
        $queue = new \App\Services\QueueService();
        $job = $queue->getActiveJob(); // Metoda koju smo ranije definisali

        if (!$job) {
            echo json_encode(['active' => false]);
            return;
        }

        $percentage = ($job['total_items'] > 0) 
            ? round(($job['processed_items'] / $job['total_items']) * 100) 
            : 0;

        echo json_encode([
            'active' => true,
            'job_id' => $job['id'],
            'type' => $job['job_type'],
            'status' => $job['status'],
            'processed' => $job['processed_items'],
            'total' => $job['total_items'],
            'percentage' => $percentage
        ]);
    }
}