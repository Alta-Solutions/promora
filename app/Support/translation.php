<?php

use App\Support\Translator;

if (!function_exists('trans')) {
    function trans(string $key, array $replace = []): string {
        return Translator::get($key, $replace);
    }
}

if (!function_exists('trans_e')) {
    function trans_e(string $key, array $replace = []): string {
        return htmlspecialchars(trans($key, $replace), ENT_QUOTES, 'UTF-8');
    }
}
