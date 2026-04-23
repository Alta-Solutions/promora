<?php
namespace App\Support;

class Csrf {
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function inputField(): string {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
    }

    public static function validateRequest(): bool {
        $token = $_POST['_csrf_token'] ?? '';
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';

        return is_string($token)
            && is_string($sessionToken)
            && $token !== ''
            && hash_equals($sessionToken, $token);
    }
}
