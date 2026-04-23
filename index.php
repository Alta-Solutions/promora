<?php
session_start();
require_once 'config.php';

define('ROOT_PATH', __DIR__ . '/');

$route = $_GET['route'] ?? 'dashboard';
$action = $_GET['action'] ?? 'index';

if (!isset($_SESSION['authenticated']) && (isset($_GET['signed_payload_jwt']) || isset($_GET['signed_payload']))) {
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
        'actions' => ['index', 'create', 'edit', 'delete', 'preview', 'filterStats'],
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
                echo "404 - Page not found";
                exit;
            }

            $controllerClass = $controllerMap[$route]['class'];
            $controller = new $controllerClass();
            $controller->{$action}();
            break;
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo "An internal error occurred.";
}
