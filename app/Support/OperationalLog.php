<?php
namespace App\Support;

/** Operational files contain bounded metadata. A single lock covers rotation and append. */
final class OperationalLog {
    private const DAYS = ['error' => 30, 'summary' => 14, 'slow' => 7, 'debug' => 2];
    private static array $counts = [];
    private static ?string $context = null;
    private static bool $registered = false;

    public static function directory(): string {
        $root = dirname(__DIR__, 2);
        $dir = $_ENV['PROMORA_LOG_DIR'] ?? dirname($root) . '/promora-private-' . substr(hash('sha256', $root), 0, 12);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create private log directory');
        }
        $actual = realpath($dir);
        $public = realpath(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: $root);
        if (!$actual || ($public && str_starts_with(strtolower($actual . DIRECTORY_SEPARATOR), strtolower($public . DIRECTORY_SEPARATOR)))) {
            throw new \RuntimeException('Configure PROMORA_LOG_DIR outside the web root');
        }
        return $actual;
    }

    public static function legacyFiles(bool $quarantine = false): array {
        $root = dirname(__DIR__,2);
        $patterns = ['app/logs/bc_api_requests.log*','app/logs/filter-query.log*','app/logs/custom_fields.log*',
            'logs/webhook_debug.log*','logs/filter-query.log*','logs/worker.log*','logs/scheduler.log*','bigcommerce-app/error_log'];
        $files = [];
        foreach ($patterns as $pattern) {
            foreach (glob($root . '/' . $pattern) ?: [] as $file) {
                if (!is_file($file) || is_link($file) || is_link(dirname($file))) {
                    continue;
                }
                $key = dirname($pattern) . '/' . basename($file);
                $files[$key] = ['bytes'=>filesize($file),'quarantined'=>false];
                if ($quarantine) {
                    $dir = self::directory() . '/legacy-quarantine';
                    if (!is_dir($dir) && !mkdir($dir,0700) && !is_dir($dir)) {
                        throw new \RuntimeException('Cannot create log quarantine');
                    }
                    $target = $dir . '/' . str_replace('/','-',$key) . '.' . bin2hex(random_bytes(6));
                    if (!rename($file,$target)) {
                        throw new \RuntimeException('Could not move legacy log ' . $key);
                    }
                    $files[$key]['quarantined'] = true;
                }
            }
        }
        return $files;
    }

    public static function begin(string $context): void {
        self::flush();
        self::$context = $context;
    }

    public static function api(string $store, string $method, string $endpoint, int $status, int $bytes, float $ms, int $retry): void {
        if (!self::$registered) {
            register_shutdown_function([self::class, 'flush']);
            self::$registered = true;
        }
        self::$context ??= bin2hex(random_bytes(8));
        $path = explode('?', $endpoint, 2)[0];
        $operation = $method . ' ' . preg_replace('~(?<=/)[0-9]+(?=/|$)~', '{id}', $path);
        $key = $operation . ' ' . $status;
        $row = &self::$counts[$store][$key];
        $row ??= ['calls' => 0, 'retries' => 0, 'bytes' => 0, 'total_ms' => 0, 'max_ms' => 0, 'slow' => 0];
        $row['calls']++;
        $row['retries'] += (int)($retry > 0);
        $row['bytes'] += $bytes;
        $row['total_ms'] += (int)round($ms);
        $row['max_ms'] = max($row['max_ms'], (int)round($ms));
        $row['slow'] += (int)($ms > 2000);
        $failed = $status < 200 || $status >= 300;
        $until = strtotime($_ENV['PROMORA_DEBUG_UNTIL'] ?? '') ?: 0;
        $debug = ($_ENV['PROMORA_DEBUG_STORE'] ?? '') === $store
            && ($_ENV['PROMORA_DEBUG_OPERATION'] ?? '') === $operation
            && $until > time() && $until <= time() + 1800;
        if (($failed && $row['calls'] === 1) || ($ms > 2000 && $row['slow'] === 1) || $debug) {
            self::write($failed ? 'error' : ($debug ? 'debug' : 'slow'), [
                'store_hash' => $store, 'operation' => $operation, 'status' => $status,
                'duration_ms' => round($ms), 'retry' => $retry,
            ]);
        }
    }

    public static function takeSummary(array $previous = []): array {
        foreach (self::$counts as $store => $operations) {
            foreach ($operations as $key => $metrics) {
                foreach ($metrics as $name => $value) {
                    $old = $previous[$store][$key][$name] ?? 0;
                    $previous[$store][$key][$name] = $name === 'max_ms' ? max($old,$value) : $old + $value;
                }
            }
        }
        self::$counts = [];
        return $previous;
    }

    public static function flush(): void {
        foreach (self::takeSummary() as $store => $counts) {
            self::write('summary', ['store_hash' => $store, 'operations' => $counts]);
        }
    }

    public static function write(string $channel, array $fields): void {
        if (!isset(self::DAYS[$channel])) {
            throw new \InvalidArgumentException('Unknown log channel');
        }
        try {
            $dir = self::directory();
            $lock = fopen($dir . '/rotation.lock', 'c');
            if (!$lock) {
                throw new \RuntimeException('Cannot open log lock');
            }
            try {
                if (!flock($lock, LOCK_EX)) {
                    throw new \RuntimeException('Cannot lock log');
                }
                $file = "$dir/$channel.jsonl";
                clearstatcache(true, $file);
                if (is_file($file) && (filesize($file) >= 10 * 1024 * 1024 || date('Ymd', filemtime($file)) !== date('Ymd'))) {
                    $archive = "$file." . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
                    if (!rename($file, $archive)) {
                        throw new \RuntimeException('Cannot rotate log');
                    }
                    self::compress($archive);
                    self::prune($dir);
                }
                $line = json_encode(['at' => gmdate(DATE_ATOM), 'context' => self::$context] + $fields,
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (file_put_contents($file, $line . PHP_EOL, FILE_APPEND) === false) {
                    throw new \RuntimeException('Cannot append log');
                }
            } finally {
                fclose($lock);
            }
        } catch (\Throwable $e) {
            error_log('Promora operational logging unavailable');
        }
    }

    public static function maintain(): void {
        $dir = self::directory();
        $lock = fopen($dir . '/rotation.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot maintain operational logs');
        }
        try {
            foreach (self::DAYS as $name => $days) {
                $file = "$dir/$name.jsonl";
                if (is_file($file) && filemtime($file) < time() - $days * 86400) {
                    unlink($file);
                }
            }
            self::prune($dir);
        } finally {
            fclose($lock);
        }
    }

    private static function compress(string $file): void {
        $in = fopen($file, 'rb');
        $out = fopen('compress.zlib://' . $file . '.gz', 'wb');
        if (!$in || !$out) {
            if ($in) { fclose($in); }
            if ($out) { fclose($out); }
            return;
        }
        $bytes = stream_copy_to_stream($in, $out);
        fclose($in);
        if (fclose($out) && $bytes === filesize($file)) {
            unlink($file);
        }
    }

    private static function prune(string $dir): void {
        $files = [];
        $total = 0;
        foreach (self::DAYS as $name => $days) {
            foreach (glob("$dir/$name.jsonl*") ?: [] as $file) {
                $size = filesize($file);
                $total += $size;
                if (basename($file) !== "$name.jsonl") {
                    $files[] = [$name !== 'debug', filemtime($file), $days, $file, $size];
                }
            }
        }
        sort($files);
        if ($total >= 90 * 1024 * 1024) {
            error_log('Promora operational logs reached 90 MiB');
        }
        foreach ($files as [, $time, $days, $file, $size]) {
            if ($time < time() - $days * 86400 || $total > 100 * 1024 * 1024) {
                if (unlink($file)) {
                    $total -= $size;
                }
            }
        }
    }
}
