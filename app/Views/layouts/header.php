<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\App\Support\Translator::locale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <?php
        $isPromotionFilterPage = ($_GET['route'] ?? '') === 'promotions'
            && in_array($_GET['action'] ?? '', ['create', 'edit', 'duplicate'], true);
        $usesTomSelect = $isPromotionFilterPage || ($_GET['route'] ?? '') === 'settings';
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= trans_e('common.app_name') ?></title>
    <link rel="stylesheet" href="public/css/style.css">
    <link rel="stylesheet" href="public/css/promotions.css?v=<?= filemtime(ROOT_PATH . 'public/css/promotions.css') ?>">
    <link rel="stylesheet" href="public/css/dashboard.css">
    <link rel="stylesheet" href="public/css/settings.css">
    <link rel="stylesheet" href="public/css/logs.css">
    <?php if ($usesTomSelect): ?>
        <link rel="stylesheet" href="public/vendor/tom-select/tom-select.css">
        <script src="public/vendor/tom-select/tom-select.complete.min.js"></script>
    <?php endif; ?>
    <script>
        window.appLocale = <?= json_encode(\App\Support\Translator::browserLocale(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        window.appTranslations = <?= \App\Support\Translator::jsonExport() ?>;
        window.appT = function(key, replacements) {
            let text = Object.prototype.hasOwnProperty.call(window.appTranslations || {}, key)
                ? window.appTranslations[key]
                : key;

            Object.entries(replacements || {}).forEach(function(entry) {
                text = String(text).split('{' + entry[0] + '}').join(String(entry[1]));
            });

            return text;
        };
    </script>
    <?php if ($isPromotionFilterPage): ?>
        <script src="public/js/promotion-product-picker.js?v=<?= filemtime(ROOT_PATH . 'public/js/promotion-product-picker.js') ?>"></script>
    <?php endif; ?>
</head>
<body>
    <div class="app-header">
        <div class="header-content">
            <div class="header-left">
                <a href="?route=dashboard" class="brand-logo"><img src="public/assets/icons/logo.png" alt="<?= trans_e('common.brand_logo_alt') ?>" /><span><?= trans_e('common.app_name') ?></span></a>
                
                <nav class="main-nav">
                    <a href="?route=dashboard" class="<?= ($_GET['route'] ?? 'dashboard') === 'dashboard' ? 'active' : '' ?>"><?= trans_e('common.dashboard') ?></a>
                    <a href="?route=promotions" class="<?= ($_GET['route'] ?? '') === 'promotions' ? 'active' : '' ?>"><?= trans_e('common.promotions') ?></a>
                    <a href="?route=logs" class="<?= ($_GET['route'] ?? '') === 'logs' ? 'active' : '' ?>"><?= trans_e('common.logs') ?></a>
                    <a href="?route=settings" class="<?= ($_GET['route'] ?? '') === 'settings' ? 'active' : '' ?>"><?= trans_e('common.settings') ?></a>
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
                    <span style="font-weight: 500;"><?= htmlspecialchars($_SESSION['username'] ?? trans('common.admin'), ENT_QUOTES, 'UTF-8') ?></span>
                    
                    <?php if (isset($_SESSION['username'])): ?> 
                        <a href="?route=auth&action=logout" class="btn-logout" title="<?= trans_e('common.logout') ?>" style="margin-left: 10px;">
                            ⏻
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container">
