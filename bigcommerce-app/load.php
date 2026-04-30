<?php
require_once '../config.php';
require_once __DIR__ . '/../app/Support/session.php';

appStartSession();

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function bcLoadBase64Decode(string $value) {
    $normalized = strtr($value, '-_', '+/');
    $padding = strlen($normalized) % 4;

    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    return base64_decode($normalized, true);
}

function bcLoadDecodeJson(string $json): array {
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new Exception('Invalid payload JSON');
    }

    return $data;
}

function bcLoadDecodeLegacyPayload(string $signedPayload, string $clientSecret): array {
    $parts = explode('.', $signedPayload, 2);

    if (count($parts) !== 2) {
        throw new Exception('Invalid signed_payload format');
    }

    [$dataPart, $signaturePart] = $parts;

    $expectedRaw = hash_hmac('sha256', $dataPart, $clientSecret, true);
    $expectedHex = hash_hmac('sha256', $dataPart, $clientSecret);
    $signatureIsValid = hash_equals($expectedHex, $signaturePart);

    if (!$signatureIsValid) {
        $decodedSignature = bcLoadBase64Decode($signaturePart);

        if ($decodedSignature !== false && ctype_xdigit($decodedSignature)) {
            $decodedSignature = hex2bin($decodedSignature);
        } elseif (ctype_xdigit($signaturePart) && strlen($signaturePart) === 64) {
            $decodedSignature = hex2bin($signaturePart);
        }

        $signatureIsValid = is_string($decodedSignature)
            && hash_equals($expectedRaw, $decodedSignature);
    }

    if (!$signatureIsValid) {
        throw new Exception('Invalid signed_payload signature');
    }

    $json = bcLoadBase64Decode($dataPart);

    if ($json === false) {
        throw new Exception('Could not decode signed_payload data');
    }

    return bcLoadDecodeJson($json);
}

function bcLoadDecodeJwtPayload(string $signedPayloadJwt, string $clientSecret): array {
    $payload = JWT::decode($signedPayloadJwt, new Key($clientSecret, 'HS256'));
    $data = json_decode(json_encode($payload), true);

    if (!is_array($data)) {
        throw new Exception('Invalid signed_payload_jwt data');
    }

    $clientId = trim((string) (Config::$BC_CLIENT_ID ?? ''));
    $audience = $data['aud'] ?? null;

    if ($clientId !== '' && $audience !== $clientId) {
        throw new Exception('Invalid signed_payload_jwt audience');
    }

    if (($data['iss'] ?? null) !== 'bc') {
        throw new Exception('Invalid signed_payload_jwt issuer');
    }

    return $data;
}

function bcLoadExtractStoreContext(array $data): array {
    $context = $data['context'] ?? $data['sub'] ?? null;

    if (is_string($context) && preg_match('#^stores/([^/]+)$#', $context, $matches)) {
        return [$matches[1], $context];
    }

    if (!empty($data['store_hash']) && is_string($data['store_hash'])) {
        return [$data['store_hash'], 'stores/' . $data['store_hash']];
    }

    throw new Exception('Missing valid store context in payload');
}

function bcLoadUserData(array $data): array {
    $user = is_array($data['user'] ?? null) ? $data['user'] : [];
    $owner = is_array($data['owner'] ?? null) ? $data['owner'] : [];

    $userId = $user['id'] ?? $data['user_id'] ?? null;
    $userEmail = $user['email'] ?? $data['user_email'] ?? null;
    $ownerId = $owner['id'] ?? $data['owner_id'] ?? null;
    $ownerEmail = $owner['email'] ?? $data['owner_email'] ?? null;

    $isOwner = false;

    if ($userId !== null && $ownerId !== null) {
        $isOwner = (string) $userId === (string) $ownerId;
    } elseif ($userEmail && $ownerEmail) {
        $isOwner = strtolower((string) $userEmail) === strtolower((string) $ownerEmail);
    }

    return [
        'user_id' => $userId,
        'user_email' => $userEmail,
        'user_locale' => $user['locale'] ?? null,
        'owner_id' => $ownerId,
        'owner_email' => $ownerEmail,
        'is_owner' => $isOwner,
    ];
}

$signedPayloadJwt = $_GET['signed_payload_jwt'] ?? null;
$signedPayload = $_GET['signed_payload'] ?? null;

if (!$signedPayloadJwt && !$signedPayload) {
    error_log('Load error: Missing signed_payload and signed_payload_jwt');
    die('Missing signed payload. Please open the app from BigCommerce control panel.');
}

$clientSecret = trim((string) (Config::$BC_CLIENT_SECRET ?? ($_ENV['BC_CLIENT_SECRET'] ?? '')));

if ($clientSecret === '') {
    die('Missing BC_CLIENT_SECRET in environment config.');
}

try {
    $data = $signedPayloadJwt
        ? bcLoadDecodeJwtPayload($signedPayloadJwt, $clientSecret)
        : bcLoadDecodeLegacyPayload($signedPayload, $clientSecret);

    [$storeHash, $context] = bcLoadExtractStoreContext($data);
    $bcUser = bcLoadUserData($data);

    $db = \App\Models\Database::getInstance();
    $store = $db->fetchOne(
        'SELECT access_token, store_hash FROM bigcommerce_stores WHERE store_hash = ? AND is_active = 1',
        [$storeHash]
    );

    if (!$store) {
        throw new Exception('Store is not registered or active in the application.');
    }

    session_regenerate_id(true);
    $db->setStoreContext($storeHash);

    $_SESSION['authenticated'] = true;
    $_SESSION['auth_source'] = 'bigcommerce';
    $_SESSION['store_hash'] = $storeHash;
    $_SESSION['access_token'] = $store['access_token'];
    $_SESSION['context'] = $context;

    // These values identify the BigCommerce control panel user that launched the app.
    $_SESSION['user_id'] = $bcUser['user_id'];
    $_SESSION['user_email'] = $bcUser['user_email'];
    $_SESSION['user_locale'] = $bcUser['user_locale'];
    $_SESSION['owner_id'] = $bcUser['owner_id'];
    $_SESSION['owner_email'] = $bcUser['owner_email'];
    $_SESSION['is_owner'] = $bcUser['is_owner'];

    $db->query('UPDATE bigcommerce_stores SET last_accessed = NOW() WHERE store_hash = ?', [$storeHash]);

    session_write_close();
    header('Location: ../index.php?route=dashboard');
    exit;
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
    error_log('Load error during payload verification/session set: ' . $errorMessage);
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
                line-height: 1.5;
            }
            code {
                color: #e5e7eb;
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
                - <code>BC_CLIENT_ID</code> and <code>BC_CLIENT_SECRET</code> match the BigCommerce app profile<br>
                - The store is installed and active in <code>bigcommerce_stores</code><br>
                - Multiple Users is enabled in the BigCommerce Developer Portal app profile when non-owner users need access<br>
                - The store owner has granted this BigCommerce user permission to load the app.
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}
