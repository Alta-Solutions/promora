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

/*function getProvidedKey(): string {
    if (PHP_SAPI === 'cli') {
        global $argv;
        return trim((string)($argv[1] ?? ''));
    }

    $headerKey = $_SERVER['HTTP_X_CUSTOM_AUTH'] ?? $_SERVER['X_CUSTOM_AUTH'] ?? '';
    $key = $_GET['key'] ?? $_POST['key'] ?? $headerKey;
    return trim((string)$key);
}*/

function buildBackgroundCommand(string $phpPath, string $scriptPath, string $logFilePath): string {
    if (PHP_OS_FAMILY === 'Windows') {
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

function resolvePhpCliPath(): ?string {
    $candidates = array_filter([
        \Config::$PHP_CLI_PATH ?? null,
        defined('PHP_BINARY') ? PHP_BINARY : null,
        '/usr/bin/php',
        '/usr/local/bin/php',
    ]);

    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate, " \t\n\r\0\x0B\"'");
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}
/*
$providedKey = getProvidedKey();
$expectedKey = trim((string)(\Config::$SECRET_CRON_KEY ?? ''));
if ($providedKey === '' || $expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    //respond(403, 'Unauthorized access.');
}*/

$workerPath = __DIR__ . '/worker.php';
if (!is_file($workerPath)) {
    respond(500, 'Worker script not found.');
}

$phpPath = resolvePhpCliPath();
if ($phpPath === null) {
    $configured = \Config::$PHP_CLI_PATH ?? '';
    $runtime = defined('PHP_BINARY') ? PHP_BINARY : '';
    respond(500, "PHP CLI executable not configured correctly.\nConfigured: {$configured}\nRuntime: {$runtime}");
}

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}

$logFilePath = $logDir . '/worker.log';
$command = buildBackgroundCommand($phpPath, $workerPath, $logFilePath);

if (!triggerInBackground($command)) {
    respond(500, 'Unable to trigger worker. shell_exec is disabled on this server.');
}

$message = "Worker process triggered successfully.\n";
$message .= "Command: {$command}\n";
$message .= "Log: {$logFilePath}\n";

respond(200, $message);
