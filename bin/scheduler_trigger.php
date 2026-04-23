<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function respond(int $statusCode, string $message): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo $message;
    exit;
}

function getProvidedKey(): string {
    if (PHP_SAPI === 'cli') {
        global $argv;
        return $argv[1] ?? '';
    }

    return (string)($_GET['key'] ?? $_POST['key'] ?? '');
}

function buildBackgroundCommand(string $phpPath, string $scriptPath, string $logFilePath): string {
    if (DIRECTORY_SEPARATOR === '\\') {
        return 'start /B "" "' . $phpPath . '" -f "' . $scriptPath . '" >> "' . $logFilePath . '" 2>&1';
    }

    return '"' . $phpPath . '" "' . $scriptPath . '" >> "' . $logFilePath . '" 2>&1 &';
}

function triggerInBackground(string $command): bool {
    if (!function_exists('shell_exec')) {
        return false;
    }

    shell_exec($command);
    return true;
}

$providedKey = getProvidedKey();
if ($providedKey === '' || $providedKey !== \Config::$SECRET_CRON_KEY) {
    respond(403, 'Unauthorized access.');
}

$schedulerPath = __DIR__ . '/scheduler.php';
if (!is_file($schedulerPath)) {
    respond(500, 'Scheduler script not found.');
}

$phpPath = \Config::$PHP_CLI_PATH ?: PHP_BINARY;
if ($phpPath === '' || !is_file($phpPath)) {
    respond(500, 'PHP CLI executable not configured correctly.');
}

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}

$logFilePath = $logDir . '/scheduler.log';
$command = buildBackgroundCommand($phpPath, $schedulerPath, $logFilePath);

if (!triggerInBackground($command)) {
    respond(500, 'Unable to trigger scheduler. shell_exec is disabled on this server.');
}

$message = "Scheduler process triggered successfully.\n";
$message .= "Command: {$command}\n";
$message .= "Log: {$logFilePath}\n";

respond(200, $message);
