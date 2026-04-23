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
}