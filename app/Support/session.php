<?php

function appSessionIsSecureRequest(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }

    if (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
        return true;
    }

    return class_exists('Config', false)
        && !empty(Config::$APP_URL)
        && stripos((string) Config::$APP_URL, 'https://') === 0;
}

function appStartSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = appSessionIsSecureRequest();
    $params = session_get_cookie_params();

    session_set_cookie_params([
        'lifetime' => $params['lifetime'] ?? 0,
        'path' => '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $secure ? 'None' : 'Lax',
    ]);

    session_start();
}

function appSessionCookieDebug(): array {
    $params = session_get_cookie_params();

    return [
        'name' => session_name(),
        'path' => $params['path'] ?? null,
        'domain' => $params['domain'] ?? null,
        'secure' => $params['secure'] ?? null,
        'httponly' => $params['httponly'] ?? null,
        'samesite' => $params['samesite'] ?? null,
    ];
}
