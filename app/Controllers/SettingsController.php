<?php
namespace App\Controllers;

use App\Models\Database;
use App\Services\ProductCacheService;
use App\Services\QueueService;
use App\Support\Csrf;

class SettingsController {
    public function index() {
        $db = Database::getInstance();
        $storeHash = $_SESSION['store_hash'] ?? $db->getStoreContext();
        $db->setStoreContext($storeHash);
        $store = $db->fetchOne(
            "SELECT settings, enable_omnibus FROM bigcommerce_stores WHERE store_hash = ?",
            [$storeHash]
        );
        $settings = json_decode($store['settings'] ?? '{}', true);

        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['allowed_filters'] = $this->normalizeAllowedFilters($settings['allowed_filters'] ?? []);
        $availableCustomFieldFilters = $this->getAvailableCustomFieldFilters($settings['allowed_filters'], $db);
        $promotionCustomFieldName = $settings['promotion_custom_field_name'] ?? \Config::$CUSTOM_FIELD_NAME;
        $enableOmnibus = !empty($store['enable_omnibus']);
        $queueService = new QueueService($storeHash);
        $activeOmnibusJob = $queueService->getActiveJobByType('omnibus_sync');

        if (isset($_SESSION['flash_message'])) {
            $flashMessage = $_SESSION['flash_message'];
            $flashType = $_SESSION['flash_type'] ?? 'success';
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        }

        require_once __DIR__ . '/../Views/layouts/header.php';
        require_once __DIR__ . '/../Views/settings/index.php';
        require_once __DIR__ . '/../Views/layouts/footer.php';
    }

    public function save() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?route=settings');
            return;
        }

        if (!Csrf::validateRequest()) {
            http_response_code(403);
            $_SESSION['flash_message'] = 'Sesija je istekla. Podesavanja nisu sacuvana.';
            $_SESSION['flash_type'] = 'error';
            header('Location: index.php?route=settings');
            exit;
        }

        $db = Database::getInstance();
        $storeHash = $_SESSION['store_hash'] ?? $db->getStoreContext();
        $enableOmnibus = isset($_POST['enable_omnibus']) ? 1 : 0;
        $rawInput = $_POST['custom_fields'] ?? [];
        $promotionCustomFieldName = trim($_POST['promotion_custom_field_name'] ?? '');

        $filtersArray = is_array($rawInput) ? $rawInput : explode(',', (string)$rawInput);
        $filtersArray = $this->normalizeAllowedFilters($filtersArray);

        try {
            $store = $db->fetchOne(
                "SELECT settings FROM bigcommerce_stores WHERE store_hash = ?",
                [$storeHash]
            );
            $settings = json_decode($store['settings'] ?? '{}', true);

            if (!is_array($settings)) {
                $settings = [];
            }

            $currentCustomFieldName = trim((string)($settings['promotion_custom_field_name'] ?? \Config::$CUSTOM_FIELD_NAME));
            $nextCustomFieldName = $promotionCustomFieldName !== '' ? $promotionCustomFieldName : \Config::$CUSTOM_FIELD_NAME;

            if ($currentCustomFieldName !== $nextCustomFieldName) {
                $activePromotion = $db->fetchOne(
                    "SELECT id
                     FROM promotions
                     WHERE store_hash = ?
                       AND status = 'active'
                       AND start_date <= NOW()
                       AND end_date >= NOW()
                     LIMIT 1",
                    [$storeHash]
                );

                if ($activePromotion) {
                    $_SESSION['flash_message'] = 'Naziv promotion custom field-a ne moze se menjati dok postoje aktivne promocije.';
                    $_SESSION['flash_type'] = 'error';
                    header('Location: index.php?route=settings');
                    exit;
                }
            }

            $settings['allowed_filters'] = $filtersArray;
            $settings['promotion_custom_field_name'] = $promotionCustomFieldName;

            $db->query(
                "UPDATE bigcommerce_stores SET settings = ?, enable_omnibus = ? WHERE store_hash = ?",
                [json_encode($settings), $enableOmnibus, $storeHash]
            );

            $_SESSION['flash_message'] = 'Podesavanja su uspesno sacuvana.';
            $_SESSION['flash_type'] = 'success';
            header('Location: index.php?route=settings');
            exit;
        } catch (\Exception $e) {
            error_log("Settings save failed: " . $e->getMessage());
            $_SESSION['flash_message'] = 'Cuvanje podesavanja nije uspelo.';
            $_SESSION['flash_type'] = 'error';
            header('Location: index.php?route=settings');
            exit;
        }
    }

    public function triggerOmnibusSync() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['flash_message'] = 'Rucni Omnibus sync se pokrece iskljucivo preko POST zahteva iz settings stranice.';
            $_SESSION['flash_type'] = 'error';
            header('Location: index.php?route=settings');
            exit;
        }

        if (!Csrf::validateRequest()) {
            http_response_code(403);
            $_SESSION['flash_message'] = 'Sesija je istekla. Omnibus sync nije pokrenut.';
            $_SESSION['flash_type'] = 'error';
            header('Location: index.php?route=settings');
            exit;
        }

        $db = Database::getInstance();
        $storeHash = $_SESSION['store_hash'] ?? $db->getStoreContext();
        $db->setStoreContext($storeHash);

        try {
            $store = $db->fetchOne(
                "SELECT enable_omnibus FROM bigcommerce_stores WHERE store_hash = ?",
                [$storeHash]
            );

            if (!$store || empty($store['enable_omnibus'])) {
                $_SESSION['flash_message'] = 'Omnibus Price Tracker nije aktiviran za ovu instancu.';
                $_SESSION['flash_type'] = 'error';
                header('Location: index.php?route=settings');
                exit;
            }

            $totalItems = $this->countOmnibusParentProducts($db, $storeHash);
            $queueService = new QueueService($storeHash);
            $result = $queueService->createOmnibusSyncJob($totalItems);

            if (!empty($result['created'])) {
                $_SESSION['flash_message'] = 'Omnibus sync je zakazan. Job #' . (int)$result['job_id'] . '.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = $result['message'] ?? 'Omnibus sync nije pokrenut.';
                $_SESSION['flash_type'] = 'error';
            }
        } catch (\Throwable $e) {
            error_log("Manual omnibus sync scheduling failed: " . $e->getMessage());
            $_SESSION['flash_message'] = 'Pokretanje Omnibus sync-a nije uspelo.';
            $_SESSION['flash_type'] = 'error';
        }

        header('Location: index.php?route=settings');
        exit;
    }

    private function countOmnibusParentProducts(Database $db, string $storeHash): int {
        $typeColumn = $db->fetchOne("SHOW COLUMNS FROM products_cache LIKE 'type'");
        $baseProductClause = $typeColumn ? " AND type = 'product'" : '';

        $row = $db->fetchOne(
            "SELECT COUNT(DISTINCT product_id) AS total
             FROM products_cache
             WHERE store_hash = ?" . $baseProductClause,
            [$storeHash]
        );

        return (int)($row['total'] ?? 0);
    }

    private function normalizeAllowedFilters($filters): array {
        if (!is_array($filters)) {
            $filters = explode(',', (string)$filters);
        }

        $normalizedFilters = [];

        foreach ($filters as $filterName) {
            $normalizedFilterName = $this->normalizeEscapedUnicodeString((string)$filterName);
            if ($normalizedFilterName !== '' && !in_array($normalizedFilterName, $normalizedFilters, true)) {
                $normalizedFilters[] = $normalizedFilterName;
            }
        }

        return array_values($normalizedFilters);
    }

    private function normalizeEscapedUnicodeString(string $value): string {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($matches) {
            return json_decode('"\\u' . $matches[1] . '"');
        }, trim($value));
    }

    private function getAvailableCustomFieldFilters(array $selectedFilters, Database $db): array {
        try {
            $cacheService = new ProductCacheService($db);
            $availableFilters = $cacheService->getCustomFieldFilterNames();
        } catch (\Throwable $e) {
            error_log("Failed to load custom field filter names: " . $e->getMessage());
            $availableFilters = [];
        }

        $filtersByName = [];
        foreach ($availableFilters as $filter) {
            $name = $this->normalizeEscapedUnicodeString((string)($filter['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $filtersByName[$name] = [
                'name' => $name,
                'product_count' => (int)($filter['product_count'] ?? 0),
            ];
        }

        foreach ($selectedFilters as $filterName) {
            $name = $this->normalizeEscapedUnicodeString((string)$filterName);
            if ($name === '' || isset($filtersByName[$name])) {
                continue;
            }

            $filtersByName[$name] = [
                'name' => $name,
                'product_count' => 0,
            ];
        }

        ksort($filtersByName, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($filtersByName);
    }
}
