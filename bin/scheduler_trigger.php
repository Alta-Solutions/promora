<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
\App\Support\CronAuth::enforce();

function respond(int $statusCode, string $message): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo $message;
    exit;
}

function buildBackgroundCommand(string $phpPath, string $scriptPath): string {
    if (PHP_OS_FAMILY === 'Windows') {
        return 'start /B "" "' . $phpPath . '" -f "' . $scriptPath . '" -- --background > NUL 2>&1';
    }
    return escapeshellarg($phpPath) . ' ' . escapeshellarg($scriptPath) . ' --background > /dev/null 2>&1 &';
}

function triggerInBackground(string $command): bool {
    if (!function_exists('shell_exec')) {
        return false;
    }

    shell_exec($command);
    return true;
}

$schedulerPath = __DIR__ . '/scheduler.php';
if (!is_file($schedulerPath)) {
    respond(500, 'Scheduler script not found.');
}

$phpPath = \Config::$PHP_CLI_PATH ?: PHP_BINARY;
if ($phpPath === '' || !is_file($phpPath)) {
    respond(500, 'PHP CLI executable not configured correctly.');
}

$command = buildBackgroundCommand($phpPath, $schedulerPath);

if (!triggerInBackground($command)) {
    respond(500, 'Unable to trigger scheduler. shell_exec is disabled on this server.');
}

respond(200, 'Scheduler process triggered.');
