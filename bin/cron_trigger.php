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

$command = buildBackgroundCommand($phpPath, $workerPath);

if (!triggerInBackground($command)) {
    respond(500, 'Unable to trigger worker. shell_exec is disabled on this server.');
}

respond(200, 'Worker process triggered.');
