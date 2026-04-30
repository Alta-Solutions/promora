<?php
require_once '../config.php';
require_once __DIR__ . '/../app/Support/session.php';

appStartSession();

/**
 * OAuth Callback Handler
 * Handles BigCommerce OAuth authorization
 */

// Log request za debugging
error_log("OAuth callback received");
error_log("GET params: " . json_encode($_GET));
error_log("Redirect URI configured: " . Config::$APP_URL . '/bigcommerce-app/auth.php');

// Verify required parameters
if (!isset($_GET['code']) || !isset($_GET['scope']) || !isset($_GET['context'])) {
    error_log("OAuth error: Missing required parameters");
    error_log("Received: code=" . (isset($_GET['code']) ? 'YES' : 'NO') . 
              ", scope=" . (isset($_GET['scope']) ? 'YES' : 'NO') . 
              ", context=" . (isset($_GET['context']) ? 'YES' : 'NO'));
    
    die('Invalid OAuth callback request. Missing required parameters: ' . 
        (!isset($_GET['code']) ? 'code ' : '') .
        (!isset($_GET['scope']) ? 'scope ' : '') .
        (!isset($_GET['context']) ? 'context' : ''));
}

$code = $_GET['code'];
$scope = $_GET['scope'];
$context = $_GET['context']; // Format: stores/{store_hash}

// Extract store hash
$storeHash = str_replace('stores/', '', $context);

if (empty($storeHash)) {
    error_log("OAuth error: Invalid store hash. Context: {$context}");
    die('Invalid store hash in context');
}

error_log("Processing OAuth for store: {$storeHash}");

// Exchange authorization code for access token
$tokenUrl = 'https://login.bigcommerce.com/oauth2/token';

// KRITIČNO: redirect_uri MORA biti TAČNO isti kao u DevTools
$redirectUri = Config::$APP_URL . '/bigcommerce-app/auth.php';

$data = [
    'client_id' => Config::$BC_CLIENT_ID,
    'client_secret' => Config::$BC_CLIENT_SECRET,
    'code' => $code,
    'scope' => $scope,
    'grant_type' => 'authorization_code',
    'redirect_uri' => $redirectUri,  // ← MORA BITI IDENTIČAN SA DEVTOOLS
    'context' => $context
];

error_log("Requesting access token with redirect_uri: {$redirectUri}");
error_log("Token request data: " . json_encode(array_merge($data, ['client_secret' => '***HIDDEN***'])));

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    error_log("OAuth token exchange failed: HTTP {$httpCode}");
    error_log("Response: {$response}");
    
    $errorData = json_decode($response, true);
    $errorMessage = $errorData['error_description'] ?? $response;
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>OAuth Error</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
            }
            .error-box {
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                max-width: 600px;
            }
            .error-icon { font-size: 64px; text-align: center; margin-bottom: 20px; }
            h1 { color: #1f2937; margin-bottom: 10px; }
            .error-detail {
                background: #fee2e2;
                border: 1px solid #fca5a5;
                padding: 15px;
                border-radius: 8px;
                margin: 20px 0;
                color: #991b1b;
            }
            .fix-steps {
                background: #fef3c7;
                border: 1px solid #f59e0b;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
            }
            .fix-steps h3 { margin-top: 0; color: #92400e; }
            .fix-steps ol { margin: 10px 0; padding-left: 20px; }
            .fix-steps li { margin: 8px 0; color: #78350f; }
            code {
                background: #f3f4f6;
                padding: 2px 6px;
                border-radius: 4px;
                font-size: 13px;
            }
            .url-compare {
                background: #1f2937;
                color: #f9fafb;
                padding: 15px;
                border-radius: 8px;
                font-family: monospace;
                font-size: 12px;
                margin: 10px 0;
            }
            .btn {
                display: inline-block;
                margin-top: 20px;
                padding: 12px 24px;
                background: #3b82f6;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 500;
            }
        </style>
    </head>
    <body>
        <div class="error-box">
            <div class="error-icon">⚠️</div>
            <h1>OAuth Authorization Failed</h1>
            
            <div class="error-detail">
                <strong>Error:</strong> <?= htmlspecialchars($errorMessage) ?>
            </div>
            
            <?php if (isset($errorData['error']) && $errorData['error'] === 'redirect_uri_mismatch'): ?>
                <div class="fix-steps">
                    <h3>🔧 How to Fix Redirect URI Mismatch:</h3>
                    <ol>
                        <li>Go to <strong><a href="https://devtools.bigcommerce.com" target="_blank">BigCommerce DevTools</a></strong></li>
                        <li>Open your app → Click <strong>Edit</strong></li>
                        <li>Find <strong>"Auth Callback URL"</strong> field</li>
                        <li>Enter <strong>EXACTLY</strong> this URL (copy-paste):
                            <div class="url-compare">
                                <?= htmlspecialchars($redirectUri) ?>
                            </div>
                        </li>
                        <li>Click <strong>Update & Close</strong></li>
                        <li>Wait 1-2 minutes for changes to propagate</li>
                        <li>Try installing the app again</li>
                    </ol>
                    
                    <p style="margin-top: 15px;"><strong>⚠️ Common mistakes:</strong></p>
                    <ul>
                        <li>Using <code>http://</code> instead of <code>https://</code></li>
                        <li>Adding trailing slash: <code>/auth.php/</code></li>
                        <li>Wrong path: <code>/auth.php</code> instead of <code>/bigcommerce-app/auth.php</code></li>
                        <li>Extra spaces before or after URL</li>
                    </ul>
                </div>
                
                <div style="background: #dbeafe; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <p style="margin: 0;"><strong>💡 Pro Tip:</strong> After updating in DevTools, wait 1-2 minutes before retrying. BigCommerce needs time to update the whitelist.</p>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                <p style="font-size: 14px; color: #6b7280;">
                    <strong>Your APP_URL setting:</strong><br>
                    <code><?= htmlspecialchars(Config::$APP_URL) ?></code>
                </p>
                <p style="font-size: 14px; color: #6b7280; margin-top: 10px;">
                    <strong>Expected redirect_uri:</strong><br>
                    <code><?= htmlspecialchars($redirectUri) ?></code>
                </p>
            </div>
            
            <a href="https://devtools.bigcommerce.com" target="_blank" class="btn">
                Open BigCommerce DevTools →
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    error_log("OAuth error: No access token in response");
    error_log("Response: {$response}");
    die('Failed to obtain access token. Response: ' . htmlspecialchars($response));
}

error_log("Access token obtained successfully for store: {$storeHash}");

// Store credentials in database
try {
    $db = \App\Models\Database::getInstance();
    
    $db->query(
        "INSERT INTO bigcommerce_stores (store_hash, access_token, scope, user_id, user_email, context, installed_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE 
         access_token = VALUES(access_token),
         scope = VALUES(scope),
         user_id = VALUES(user_id),
         user_email = VALUES(user_email),
         updated_at = NOW()",
        [
            $storeHash,
            $tokenData['access_token'],
            $tokenData['scope'],
            $tokenData['user']['id'] ?? null,
            $tokenData['user']['email'] ?? null,
            $context
        ]
    );
    
    error_log("Store credentials saved to database: {$storeHash}");
    
    // Set session
    $_SESSION['store_hash'] = $storeHash;
    $_SESSION['access_token'] = $tokenData['access_token'];
    $_SESSION['authenticated'] = true;
    $_SESSION['user_email'] = $tokenData['user']['email'] ?? null;
    
    // Redirect to app with success message
    header('Location: ../index.php?route=dashboard&installed=1');
    exit;
    
} catch (Exception $e) {
    error_log("Error storing OAuth credentials: " . $e->getMessage());
    die('Error completing installation: ' . htmlspecialchars($e->getMessage()));
}
