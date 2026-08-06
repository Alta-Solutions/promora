<?php
namespace App\Support;

class LogRotator {
    private const DEFAULT_MAX_BYTES = 52428800; // 50 MB
    private const DEFAULT_MAX_FILES = 5;

    public static function append(string $path, string $contents): void {
        self::rotateIfNeeded($path, strlen($contents));
        file_put_contents($path, $contents, FILE_APPEND);
    }

    public static function rotateIfNeeded(string $path, int $incomingBytes = 0): void {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        if (!is_file($path)) {
            return;
        }

        $maxBytes = self::maxBytes();
        if ($maxBytes <= 0) {
            return;
        }

        $currentSize = filesize($path);
        if ($currentSize === false || ($currentSize + max(0, $incomingBytes)) < $maxBytes) {
            return;
        }

        $rotatedPath = self::rotatedPath($path);
        @rename($path, $rotatedPath);
        self::prune($path);
    }

    private static function rotatedPath(string $path): string {
        $directory = dirname($path);
        $filename = basename($path);
        $timestamp = date('Ymd-His');
        $candidate = $directory . DIRECTORY_SEPARATOR . $filename . '.' . $timestamp;
        $suffix = 1;

        while (file_exists($candidate)) {
            $candidate = $directory . DIRECTORY_SEPARATOR . $filename . '.' . $timestamp . '.' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private static function prune(string $path): void {
        $maxFiles = self::maxFiles();
        if ($maxFiles < 1) {
            return;
        }

        $directory = dirname($path);
        $filename = basename($path);
        $files = glob($directory . DIRECTORY_SEPARATOR . $filename . '.*') ?: [];
        usort($files, static function (string $a, string $b): int {
            return (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0);
        });

        foreach (array_slice($files, $maxFiles) as $oldFile) {
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
    }

    private static function maxBytes(): int {
        $value = getenv('LOG_ROTATION_MAX_BYTES');
        if ($value === false || $value === '') {
            $value = $_ENV['LOG_ROTATION_MAX_BYTES'] ?? null;
        }

        return is_numeric($value) ? max(0, (int)$value) : self::DEFAULT_MAX_BYTES;
    }

    private static function maxFiles(): int {
        $value = getenv('LOG_ROTATION_MAX_FILES');
        if ($value === false || $value === '') {
            $value = $_ENV['LOG_ROTATION_MAX_FILES'] ?? null;
        }

        return is_numeric($value) ? max(0, (int)$value) : self::DEFAULT_MAX_FILES;
    }
}
