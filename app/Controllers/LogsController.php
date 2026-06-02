<?php
namespace App\Controllers;

use App\Models\Database;

class LogsController {
    
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
            "SELECT * FROM webhook_events WHERE store_hash = ? ORDER BY received_at DESC LIMIT 100",
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
}
