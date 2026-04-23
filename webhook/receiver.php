<?php
// ISPRAVKA: Učitavanje autoloader-a i config fajla sa ispravnim putanjama.
// Ovo je neophodno da bi se klase poput WebhookService mogle pronaći.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

// Prvo čitamo sirovi payload da bismo ga imali za logovanje
$rawPayload = file_get_contents('php://input');

// NOVO: Debug logovanje ako je omogućeno u konfiguraciji
if (isset(\Config::$DEBUG_WEBHOOKS) && \Config::$DEBUG_WEBHOOKS === true) {
    $headers = function_exists('getallheaders') ? json_encode(getallheaders()) : "[]"; // getallheaders() radi na Apache (XAMPP)
    $logEntry = "--- INCOMING WEBHOOK [" . date('Y-m-d H:i:s') . "] ---\n";
    $logEntry .= "Headers: " . $headers . "\n";
    $logEntry .= "Payload: " . $rawPayload . "\n\n";
    
    $logFile = __DIR__ . '/../logs/webhook_debug.log';
    $logDir = dirname($logFile);
    
    // Kreiraj 'logs' direktorijum ako ne postoji
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// POBOLJŠANJE: Uklonjena redundantna provera autentifikacije.
// WebhookService->processWebhook() već sadrži ovu logiku, čime se izbegava dupliranje koda.

// Dekodiramo sirovi payload koji smo već pročitali
$payload = json_decode($rawPayload, true);

if (!$payload) {
    // Ako je payload nevalidan, i dalje želimo da ga logujemo u standardni error log
    error_log("Webhook Error: Invalid or empty JSON payload received. Raw: " . $rawPayload);
    http_response_code(400);
    die('Invalid payload');
}

try {
    $webhookService = new \App\Services\WebhookService();
    // Prosleđujemo i payload i headere servisu na obradu.
    // POBOLJŠANJE: Prosleđujemo rezultat getallheaders() ako postoji, jer je pouzdaniji za custom headere.
    $requestHeaders = function_exists('getallheaders') ? getallheaders() : $_SERVER;

    $result = $webhookService->processWebhook($payload, $requestHeaders);
    
    if ($result) {
        http_response_code(200);
        echo json_encode(['status' => 'success']);
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
