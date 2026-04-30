<?php
define('ROOT_PATH', __DIR__ . '/');
require_once ROOT_PATH . 'config.php';
require_once ROOT_PATH . 'app/Support/session.php';

appStartSession();

$configuredLocale = null;
if (!empty($_SESSION['store_hash'])) {
    try {
        $db = \App\Models\Database::getInstance();
        $db->setStoreContext($_SESSION['store_hash']);
        $storeSettings = \App\Support\StoreSettings::load($db, $_SESSION['store_hash']);
        $configuredLocale = $storeSettings['language'] ?? null;
    } catch (Throwable $e) {
        error_log('Locale boot failed: ' . $e->getMessage());
    }
}

\App\Support\Translator::initialize($configuredLocale);

$route = $_GET['route'] ?? 'dashboard';
$action = $_GET['action'] ?? 'index';

if (isset($_GET['signed_payload_jwt']) || isset($_GET['signed_payload'])) {
    $params = http_build_query($_GET);
    $loadUrl = Config::$APP_URL . '/bigcommerce-app/load.php?' . $params;
    header("Location: " . $loadUrl);
    exit;
}

$publicRoutes = ['auth/login', 'auth/logout', 'install/index', 'cron/index'];
$currentRoute = $route . '/' . $action;

if (!in_array($currentRoute, $publicRoutes, true) && !isset($_SESSION['authenticated'])) {
    header('Location: ?route=auth&action=login');
    exit;
}

if (
    isset($_SESSION['authenticated'])
    && $_SESSION['authenticated'] === true
    && empty($_SESSION['store_hash'])
    && !in_array($currentRoute, $publicRoutes, true)
    && $currentRoute !== 'auth/selectStore'
    && $currentRoute !== 'auth/setStore'
) {
    header('Location: ?route=auth&action=selectStore');
    exit;
}

$controllerMap = [
    'auth' => [
        'class' => \App\Controllers\AuthController::class,
        'actions' => ['login', 'selectStore', 'setStore', 'logout'],
    ],
    'dashboard' => [
        'class' => \App\Controllers\DashboardController::class,
        'actions' => ['index'],
    ],
    'promotions' => [
        'class' => \App\Controllers\PromotionController::class,
        'actions' => ['index', 'create', 'edit', 'duplicate', 'delete', 'preview', 'filterStats', 'customFieldOptions', 'productOptions'],
    ],
    'sync' => [
        'class' => \App\Controllers\SyncController::class,
        'actions' => ['index', 'single', 'startSingle', 'getActiveJobStatus'],
    ],
    'logs' => [
        'class' => \App\Controllers\LogsController::class,
        'actions' => ['index', 'webhooks'],
    ],
    'cache' => [
        'class' => \App\Controllers\CacheController::class,
        'actions' => ['fullSync', 'registerWebhooks', 'unregisterWebhooks', 'stats', 'quickSync', 'clearCache', 'debugWebhooks'],
    ],
    'settings' => [
        'class' => \App\Controllers\SettingsController::class,
        'actions' => ['index', 'save', 'triggerOmnibusSync'],
    ],
];

try {
    switch ($route) {
        case 'install':
            require_once 'app/install/install.php';
            break;

        case 'cron':
            $controller = new \App\Controllers\SyncController();
            $controller->cronJob();
            break;

        case 'api':
            $controller = new \App\Controllers\ApiController();
            $controller->handleRequest();
            break;

        default:
            if (!isset($controllerMap[$route]) || !in_array($action, $controllerMap[$route]['actions'], true)) {
                http_response_code(404);
                echo trans('common.page_not_found');
                exit;
            }

            $controllerClass = $controllerMap[$route]['class'];
            $controller = new $controllerClass();
            $controller->{$action}();
            break;
    }
} catch (Throwable $e) {
    error_log(sprintf(
        'Unhandled app error on route=%s action=%s: %s in %s:%d',
        $route,
        $action,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    http_response_code(500);
    echo trans('common.internal_error');
}
