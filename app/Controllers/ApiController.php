<?php
namespace App\Controllers;

use App\Models\SyncLog;
use App\Services\PromotionService;

class ApiController {
    
    public function handleRequest() {
        header('Content-Type: application/json');
        
        $action = $_GET['action'] ?? 'index';
        
        try {
            switch ($action) {
                case 'sync_all':
                    $this->syncAll();
                    break;
                    
                case 'get_logs':
                    $this->getLogs();
                    break;
                    
                case 'get_log_details':
                    $this->getLogDetails();
                    break;
                    
                case 'get_stats':
                    $this->getStats();
                    break;
                    
                case 'test_filters':
                    $this->testFilters();
                    break;
                    
                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Unknown action']);
            }
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    private function syncAll() {
        $promotionService = new PromotionService();
        $result = $promotionService->queueAllPromotions();
        
        echo json_encode([
            'success' => true,
            'result' => $result
        ]);
    }
    
    private function getLogs() {
        $syncLog = new SyncLog();
        $limit = $_GET['limit'] ?? 100;
        
        $logs = $syncLog->findAll($limit);
        echo json_encode($logs);
    }
    
    private function getLogDetails() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing log ID']);
            return;
        }
        
        $syncLog = new SyncLog();
        $log = $syncLog->findById($id);
        
        if (!$log) {
            http_response_code(404);
            echo json_encode(['error' => 'Log not found']);
            return;
        }
        
        echo json_encode($log);
    }
    
    private function getStats() {
        $syncLog = new SyncLog();
        $stats = $syncLog->getStats();
        
        echo json_encode($stats);
    }
    
    private function testFilters() {
        // Test endpoint to preview which products match filters
        $filters = json_decode($_POST['filters'] ?? '{}', true);
        $filters = $this->normalizeFilters(is_array($filters) ? $filters : []);
        
        $api = new \App\Services\BigCommerceAPI();
        $products = $api->getProducts($filters);
        
        echo json_encode([
            'success' => true,
            'count' => count($products),
            'products' => array_slice($products, 0, 10), // First 10 for preview
            'filters_used' => $filters
        ]);
    }

    private function normalizeFilters(array $filters) {
        $normalizedFilters = [];

        foreach ($filters as $key => $value) {
            $normalizedKey = $key;

            if (strpos($key, 'custom_field:') === 0) {
                $normalizedKey = 'custom_field:' . $this->normalizeEscapedUnicodeString(substr($key, 13));
            }

            if (
                in_array($key, ['categories:in', 'brand_id'], true) ||
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
}
