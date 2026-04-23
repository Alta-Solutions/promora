<?php
namespace App\Controllers;

use App\Services\ProductCacheService;
use App\Services\WebhookService;

class CacheController {
    private $cacheService;
    private $webhookService;

    public function __construct() {
        $this->cacheService = new ProductCacheService();
        $this->webhookService = new WebhookService();
    }

    /**
     * Full cache sync - first time setup
     */
    public function fullSync() {
        set_time_limit(0);

        include __DIR__ . '/../Views/layouts/header.php';

        echo '<div class="container">';
        echo '<div class="card">';
        echo '<h2>Full Product Cache Sync</h2>';
        echo '<pre id="sync-output" style="background: #1f2937; color: #fff; padding: 20px; border-radius: 8px; max-height: 600px; overflow-y: auto;">';

        ob_implicit_flush(true);
        ob_end_flush();

        $this->cacheService->fullSync();

        echo '</pre>';
        echo '<div style="margin-top: 20px;">';
        echo '<a href="?route=settings" class="btn btn-primary">Back to settings</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        include __DIR__ . '/../Views/layouts/footer.php';
    }

    /**
     * Register webhooks
     */
    public function registerWebhooks() {
        $db = \App\Models\Database::getInstance();
        $storeHash = $db->getStoreContext();

        if (!$storeHash) {
            $_SESSION['flash_message'] = 'Greska: Nije detektovana aktivna prodavnica.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?route=settings');
            exit;
        }

        try {
            $registered = $this->webhookService->registerWebhooks($storeHash);
            $count = count($registered);

            if ($count > 0) {
                $_SESSION['flash_message'] = "Uspesno registrovano {$count} novih webhook-ova.";
            } else {
                $_SESSION['flash_message'] = 'Provera zavrsena. Svi potrebni webhook-ovi su vec registrovani.';
            }
            $_SESSION['flash_type'] = 'success';
        } catch (\Exception $e) {
            $_SESSION['flash_message'] = 'Greska: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }

        header('Location: ?route=settings');
        exit;
    }

    /**
     * Unregister all active webhooks for current store
     */
    public function unregisterWebhooks() {
        $db = \App\Models\Database::getInstance();
        $storeHash = $db->getStoreContext();
        $webhookRowId = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

        if (!$storeHash) {
            $_SESSION['flash_message'] = 'Greska: Nije detektovana aktivna prodavnica.';
            $_SESSION['flash_type'] = 'error';
            header('Location: ?route=settings');
            exit;
        }

        try {
            if ($webhookRowId > 0) {
                $result = $this->webhookService->unregisterWebhookById($storeHash, $webhookRowId);

                if (!empty($result['deleted'])) {
                    if (($result['reason'] ?? '') === 'missing_on_bc') {
                        $_SESSION['flash_message'] = 'Webhook je vec bio uklonjen na BigCommerce strani, pa je obrisan iz lokalne baze.';
                    } else {
                        $_SESSION['flash_message'] = 'Webhook je uspesno obrisan.';
                    }
                } else {
                    $_SESSION['flash_message'] = 'Trazeni webhook nije pronadjen.';
                }
            } else {
                $deletedCount = $this->webhookService->unregisterWebhooks($storeHash);
                if ($deletedCount > 0) {
                    $_SESSION['flash_message'] = "Obrisano {$deletedCount} webhook-ova.";
                } else {
                    $_SESSION['flash_message'] = 'Nema webhook-ova za brisanje ili su vec uklonjeni na BigCommerce strani.';
                }
            }
            $_SESSION['flash_type'] = 'success';
        } catch (\Exception $e) {
            $_SESSION['flash_message'] = 'Greska pri brisanju webhook-ova: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
        }

        header('Location: ?route=settings');
        exit;
    }

    /**
     * Cache statistics
     */
    public function stats() {
        header('Content-Type: application/json');

        try {
            $stats = $this->cacheService->getCacheStats();
            echo json_encode(['success' => true, 'data' => $stats]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function quickSync() {
        header('Content-Type: application/json');

        try {
            $modifiedProducts = $this->cacheService->syncModifiedProducts();

            echo json_encode([
                'success' => true,
                'updated' => $modifiedProducts,
            ]);
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function clearCache() {
        header('Content-Type: application/json');

        try {
            $this->cacheService->clearCache();
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Debug page to see actual webhooks on BigCommerce
     */
    public function debugWebhooks() {
        $db = \App\Models\Database::getInstance();
        $storeHash = $db->getStoreContext();

        include __DIR__ . '/../Views/layouts/header.php';

        echo '<div class="card">';
        echo '<h3>BigCommerce Webhook Status</h3>';

        try {
            $bcWebhooks = $this->webhookService->getBigCommerceWebhooks($storeHash);
            echo '<p>Ovo su webhookovi koji su trenutno aktivni na BigCommerce serverima:</p>';
            echo '<pre style="background: #f3f4f6; padding: 15px; border-radius: 6px; overflow: auto;">' . print_r($bcWebhooks, true) . '</pre>';
        } catch (\Exception $e) {
            echo '<div class="alert alert-error">Greska: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        echo '<a href="?route=settings" class="btn btn-secondary" style="margin-top: 15px;">Nazad na podesavanja</a>';
        echo '</div>';

        include __DIR__ . '/../Views/layouts/footer.php';
    }
}
