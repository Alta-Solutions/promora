<!DOCTYPE html>
<html lang="sr">
<head>
    <?php
        $isPromotionFilterPage = ($_GET['route'] ?? '') === 'promotions'
            && in_array($_GET['action'] ?? '', ['create', 'edit', 'duplicate'], true);
        $usesTomSelect = $isPromotionFilterPage || ($_GET['route'] ?? '') === 'settings';
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promora</title>
    <link rel="stylesheet" href="public/css/style.css">
    <link rel="stylesheet" href="public/css/promotions.css?v=<?= filemtime(ROOT_PATH . 'public/css/promotions.css') ?>">
    <link rel="stylesheet" href="public/css/dashboard.css">
    <link rel="stylesheet" href="public/css/settings.css">
    <link rel="stylesheet" href="public/css/logs.css">
    <?php if ($usesTomSelect): ?>
        <link rel="stylesheet" href="public/vendor/tom-select/tom-select.css">
        <script src="public/vendor/tom-select/tom-select.complete.min.js"></script>
    <?php endif; ?>
    <?php if ($isPromotionFilterPage): ?>
        <script src="public/js/promotion-product-picker.js?v=<?= filemtime(ROOT_PATH . 'public/js/promotion-product-picker.js') ?>"></script>
    <?php endif; ?>
</head>
<body>
    <div class="app-header">
        <div class="header-content">
            <div class="header-left">
                <a href="?route=dashboard" class="brand-logo"><img src="public/assets/icons/logo.png" alt="Promora Logo" /><span>Promora</span></a>
                
                <nav class="main-nav">
                    <a href="?route=dashboard" class="<?= ($_GET['route'] ?? 'dashboard') === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
                    <a href="?route=promotions" class="<?= ($_GET['route'] ?? '') === 'promotions' ? 'active' : '' ?>">Promocije</a>
                    <a href="?route=logs" class="<?= ($_GET['route'] ?? '') === 'logs' ? 'active' : '' ?>">Logovi</a>
                    <a href="?route=settings" class="<?= ($_GET['route'] ?? '') === 'settings' ? 'active' : '' ?>">Podešavanja</a>
                </nav>
            </div>
            
            <div class="header-right">
                <?php 
                    use App\Models\Database;
                    $db = Database::getInstance();
                    $stores = $db->fetchAll("SELECT store_hash, context FROM bigcommerce_stores WHERE is_active = TRUE ORDER BY installed_at DESC");
                    $currentStoreHash = $_SESSION['store_hash'] ?? null;
                    
                    if (count($stores) > 1 && isset($_SESSION['username'])):
                ?>
                    <div class="store-selector">
                        <select id="store-select" onchange="window.location.href = '?route=auth&action=setStore&store_hash=' + this.value">
                            <?php foreach ($stores as $store):
                                $contextParts = explode('/', $store['context']);
                                $displayName = end($contextParts); 
                                $isSelected = $store['store_hash'] === $currentStoreHash;
                            ?>
                                <option value="<?= htmlspecialchars($store['store_hash']) ?>" <?= $isSelected ? 'selected' : '' ?>>
                                    #<?= htmlspecialchars($displayName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif (count($stores) === 1): 
                    $contextParts = explode('/', $stores[0]['context']);
                    $displayName = end($contextParts);
                ?>
                    <span style="font-size: 0.85rem; color: #6b7280;">#<?= htmlspecialchars($displayName) ?></span>
                <?php endif; ?>
                
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)) ?>
                    </div>
                    <span style="font-weight: 500;"><?= $_SESSION['username'] ?? 'Admin' ?></span>
                    
                    <?php if (isset($_SESSION['username'])): ?> 
                        <a href="?route=auth&action=logout" class="btn-logout" title="Odjavi se" style="margin-left: 10px;">
                            ⏻
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container">
