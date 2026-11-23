<?php
// ISPRAVKA: Učitavanje autoloader-a i config fajla sa ispravnim putanjama.
// Ovo je neophodno da bi se klase poput WebhookService mogle pronaći.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['health'])) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(200);
    echo 'ok';
    exit;
}

// Prvo čitamo sirovi payload da bismo ga imali za logovanje
$rawPayload = file_get_contents('php://input');

// Dekodiramo sirovi payload koji smo već pročitali
$payload = json_decode($rawPayload, true);

if (!$payload) {
    // Ako je payload nevalidan, i dalje želimo da ga logujemo u standardni error log
    error_log("Webhook rejected: invalid JSON (" . strlen($rawPayload) . " bytes)");
    http_response_code(400);
    die('Invalid payload');
}

try {
    $webhookService = new \App\Services\WebhookService();
    // Prosleđujemo i payload i headere servisu na obradu.
    // POBOLJŠANJE: Prosleđujemo rezultat getallheaders() ako postoji, jer je pouzdaniji za custom headere.
    $requestHeaders = function_exists('getallheaders') ? getallheaders() : $_SERVER;

    $result = $webhookService->acceptWebhook($payload, $requestHeaders);
    
    if ($result) {
        http_response_code(200);
        echo json_encode(['status' => 'queued']);
    } else {
        $statusCode = $webhookService->getLastStatusCode();
        http_response_code($statusCode > 0 ? $statusCode : 500);
        echo json_encode([
            'status' => 'error',
            'message' => $webhookService->getLastError() ?? 'Webhook processing failed.'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Webhook processing error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
