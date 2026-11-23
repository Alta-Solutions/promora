<?php
namespace App\Support;

final class CronAuth {
    public static function enforce(): void {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $expected = (string)($_ENV['CRON_AUTH_TOKEN'] ?? getenv('CRON_AUTH_TOKEN'));
        $provided = $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
        if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}
