<?php
session_start();
require_once '../config.php';

$signedPayload = $_GET['signed_payload'] ?? '';

if (empty($signedPayload)) {
    die('Missing signed payload');
}

try {
    $parts = explode('.', $signedPayload);
    if (count($parts) !== 2) {
        throw new Exception('Invalid payload format');
    }
    
    list($encodedData, $encodedSignature) = $parts;
    
    // Decode data
    $jsonData = base64_decode($encodedData);
    $data = json_decode($jsonData, true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    // BigCommerce može slati signature u dva formata:
    // 1. Base64(binary signature)
    // 2. Base64(hex string of signature) - ŠTO JE VAŠ SLUČAJ
    
    $signature = base64_decode($encodedSignature);
    
    // Proveri da li je signature zapravo hex string
    if (ctype_xdigit($signature)) {
        // Konvertuj hex string u binary
        $signature = hex2bin($signature);
    }
    
    // Verify signature
    $secret = trim(Config::$BC_CLIENT_SECRET);
    $expectedSignature = hash_hmac('sha256', $encodedData, $secret, true);
    
    if (!hash_equals($expectedSignature, $signature)) {
        // Log za debug
        error_log("Signature mismatch:");
        error_log("Expected (base64): " . base64_encode($expectedSignature));
        error_log("Received (base64): " . $encodedSignature);
        error_log("Received decoded: " . bin2hex($signature));
        
        throw new Exception('Invalid signature');
    }
    
    // Extract store information
    $storeHash = $data['store_hash'] ?? null;
    if (!$storeHash) {
        throw new Exception('Store hash not found');
    }
    
    // Load store from database
    $db = \App\Models\Database::getInstance();
    $store = $db->fetchOne(
        "SELECT * FROM bigcommerce_stores WHERE store_hash = ?",
        [$storeHash]
    );
    
    if (!$store) {
        throw new Exception('Store not found. Please reinstall the app.');
    }
    
    // Set session
    $_SESSION['store_hash'] = $storeHash;
    $_SESSION['access_token'] = $store['access_token'];
    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = $data['user']['id'] ?? null;
    $_SESSION['user_email'] = $data['user']['email'] ?? null;
    
    // Update last accessed
    $db->query(
        "UPDATE bigcommerce_stores SET last_accessed = NOW() WHERE store_hash = ?",
        [$storeHash]
    );
    
    // Redirect
    header('Location: ../index.php?route=dashboard');
    exit;
    
} catch (Exception $e) {
    error_log("Load error: " . $e->getMessage());
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Error Loading App</title>
        <style>
            body { 
                font-family: -apple-system, sans-serif; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex; align-items: center; justify-content: center; 
                min-height: 100vh; margin: 0; 
            }
            .error-box { 
                background: white; padding: 40px; border-radius: 12px; 
                box-shadow: 0 10px 40px rgba(0,0,0,0.2); max-width: 500px; text-align: center; 
            }
            .error-icon { font-size: 64px; }
            h1 { color: #1f2937; margin: 20px 0 10px; }
            p { color: #6b7280; line-height: 1.6; margin: 10px 0; }
            .btn { 
                display: inline-block; margin-top: 20px; padding: 12px 24px; 
                background: #3b82f6; color: white; text-decoration: none; 
                border-radius: 6px; 
            }
        </style>
    </head>
    <body>
        <div class="error-box">
            <div class="error-icon">⚠️</div>
            <h1>Unable to Load App</h1>
            <p><?= htmlspecialchars($e->getMessage()) ?></p>
            <p style="font-size: 14px; margin-top: 20px;">Please refresh the page or contact support.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}