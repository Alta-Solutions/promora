<?php

function appSessionEnvBoolean(string $name): ?bool {
    $value = $_ENV[$name] ?? getenv($name);

    if ($value === false || $value === null || $value === '') {
        return null;
    }

    $normalized = strtolower(trim((string) $value));

    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return null;
}

function appSessionIsSecureRequest(): bool {
    $secureOverride = appSessionEnvBoolean('SESSION_COOKIE_SECURE');
    if ($secureOverride !== null) {
        return $secureOverride;
    }

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

    return false;
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
