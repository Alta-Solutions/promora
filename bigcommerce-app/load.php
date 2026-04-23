<?php
session_start();
require_once '../config.php';

// Ako koristiš composer autoload:
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * App Load Endpoint
 * Called when merchant opens the app from BigCommerce control panel
 */

$signedPayloadJwt = $_GET['signed_payload_jwt'] ?? null;
$signedPayload    = $_GET['signed_payload'] ?? null;

$data = null;

if (!$signedPayloadJwt && !$signedPayload) {
    error_log("Load error: Missing signed_payload and signed_payload_jwt");
    die('Missing signed payload. Please open the app from BigCommerce control panel.');
}

$clientSecret = trim($_ENV['BC_CLIENT_SECRET'] ?? '');

if (!$clientSecret) {
    die('Missing BC_CLIENT_SECRET in environment config.');
}


    if ($signedPayloadJwt) {
        // ✅ NOVI JWT FORMAT
        $payload = JWT::decode($signedPayloadJwt, new Key($clientSecret, 'HS256'));
        // pretvori u array radi lakšeg rada
        $data = json_decode(json_encode($payload), true);
    } else {
        // ✅ STARI signed_payload FORMAT: data.signature (HMAC)
        list($dataPart, $signature) = explode('.', $signedPayload, 2);

        $expectedSig = hash_hmac('sha256', $dataPart, $clientSecret);

        // BigCommerce šalje hex string, ne menjaš ništa osim poređenja
        if (!hash_equals($expectedSig, $signature)) {
            throw new Exception('Invalid signed_payload signature');
        }

        $json = base64_decode($dataPart);
        $data = json_decode($json, true);


    }

    // Sada u $data imaš:
    // - $data['context'] = "stores/xxxxxx"
    // - $data['user']['id'], $data['user']['email'], ...
    // - $data['owner'] ...

    $context = $data['context'] ?? $data['sub'] ?? null;

    if (!$context) {
        throw new Exception('Missing context/sub in payload');
    }

    // Izvuci store_hash iz context-a: "stores/{hash}"
    $parts   = explode('/', $context);
    $storeHash = $parts[1] ?? null;

    if (!$storeHash) {
        throw new Exception('Could not extract store_hash from context');
    }

    // ✅ Ovde povlačiš iz svoje baze kredencijale za taj store
    // npr. tabela "stores" sa kolonama (store_hash, access_token, scope, user_id, itd.)
    // $db = Database::getInstance();
    // $store = $db->fetch("SELECT * FROM stores WHERE store_hash = ?", [$storeHash]);
    //
    // if (!$store) throw new Exception("Store not registered in app");
try {
    $db = \App\Models\Database::getInstance();
    
    // Dohvati store podatke. Prethodno dobijeni user_id i access_token
    // nisu bitni, bitan je samo store_hash i access_token.
    $store = $db->fetchOne(
        "SELECT access_token, store_hash FROM bigcommerce_stores WHERE store_hash = ?", 
        [$storeHash]
    );

    if (!$store) {
        throw new Exception("Store is not registered or active in the application.");
    }

    // Setuj sesiju za korisnika koji se prijavio (bilo koji admin)
    // Svi admini unutar istog BigCommerce stvora dele isti access_token!
    $_SESSION['store_hash'] = $storeHash;
    $_SESSION['access_token'] = $store['access_token']; // <--- BITNO: Token iz DB
    $_SESSION['authenticated'] = true;
    
    // Ovi podaci se menjaju zavisno od admina i koriste se samo za prikaz/logovanje
    $_SESSION['context']    = $context;
    $_SESSION['user_id']    = $data['user']['id']   ?? null;
    $_SESSION['user_email'] = $data['user']['email'] ?? null;
    $_SESSION['is_owner']   = (($data['owner']['id'] ?? null) === ($data['user']['id'] ?? null));

    // ✅ Gotovo, user je autentifikovan → idi na dashboard
    header("Location: /index.php?route=dashboard");
    exit;

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    error_log("Load error during DB fetch/session set: " . $errorMessage);

    // Ovde možeš zadržati svoj postojeći HTML za debugging
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Error Loading App</title>
        <style>
            body {
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                background: #0f172a;
                color: #f9fafb;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
            }
            .card {
                background: #020617;
                border-radius: 16px;
                padding: 32px;
                max-width: 560px;
                width: 100%;
                box-shadow: 0 25px 50px -12px rgba(15,23,42,0.8);
                border: 1px solid #1f2937;
            }
            h1 {
                font-size: 24px;
                margin-top: 0;
                margin-bottom: 12px;
            }
            .error {
                background: #1f2937;
                padding: 12px 16px;
                border-radius: 8px;
                font-size: 14px;
                color: #fecaca;
                border: 1px solid #b91c1c;
                margin-bottom: 16px;
                word-break: break-all;
            }
            .hint {
                font-size: 14px;
                color: #9ca3af;
            }
            .btn {
                display: inline-block;
                margin-top: 12px;
                padding: 10px 16px;
                border-radius: 999px;
                background: #22c55e;
                color: #022c22;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Unable to load the app</h1>
            <div class="error">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <p class="hint">
                Make sure that:<br>
                – The <code>BC_CLIENT_SECRET</code> in your <code>.env</code> matches the one in BigCommerce DevTools<br>
                – You are opening the app from BigCommerce control panel, not directly via URL.
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
