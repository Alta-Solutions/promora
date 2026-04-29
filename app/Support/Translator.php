<?php
namespace App\Support;

class Translator {
    public const DEFAULT_LOCALE = 'en';

    private static $locale = self::DEFAULT_LOCALE;
    private static $translations = [];
    private static $fallbackTranslations = [];
    private static $availableLocales = null;

    public static function initialize(?string $locale = null): void {
        self::setLocale($locale ?: self::DEFAULT_LOCALE);
    }

    public static function setLocale(?string $locale): void {
        $normalizedLocale = self::normalizeLocale($locale);

        self::$fallbackTranslations = self::loadLocale(self::DEFAULT_LOCALE);
        self::$translations = self::loadLocale($normalizedLocale);
        self::$locale = $normalizedLocale;
    }

    public static function locale(): string {
        return self::$locale;
    }

    public static function browserLocale(): string {
        return self::get('_meta.browser_locale') ?: self::$locale;
    }

    public static function availableLocales(): array {
        if (self::$availableLocales !== null) {
            return self::$availableLocales;
        }

        $locales = [];
        foreach (glob(self::languagePath() . '*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            $messages = self::loadLocaleFile($file);

            if ($code === '' || empty($messages)) {
                continue;
            }

            $locales[$code] = [
                'code' => $code,
                'name' => self::arrayGet($messages, '_meta.name') ?: strtoupper($code),
                'native_name' => self::arrayGet($messages, '_meta.native_name') ?: self::arrayGet($messages, '_meta.name') ?: strtoupper($code),
            ];
        }

        if (empty($locales)) {
            $locales[self::DEFAULT_LOCALE] = [
                'code' => self::DEFAULT_LOCALE,
                'name' => 'English',
                'native_name' => 'English',
            ];
        }

        ksort($locales, SORT_NATURAL | SORT_FLAG_CASE);
        self::$availableLocales = $locales;
        return self::$availableLocales;
    }

    public static function get(string $key, array $replace = []): string {
        $value = self::arrayGet(self::$translations, $key);

        if ($value === null) {
            $value = self::arrayGet(self::$fallbackTranslations, $key);
        }

        if ($value === null || is_array($value)) {
            return $key;
        }

        $text = (string)$value;
        foreach ($replace as $name => $replacement) {
            $text = str_replace('{' . $name . '}', (string)$replacement, $text);
        }

        return $text;
    }

    public static function export(): array {
        return self::flatten(self::stripMeta(self::$translations));
    }

    public static function jsonExport(): string {
        return json_encode(
            self::export(),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );
    }

    public static function normalizeLocale(?string $locale): string {
        $locale = strtolower(trim((string)$locale));
        $locale = str_replace('_', '-', $locale);
        $locale = preg_replace('/[^a-z0-9-]/', '', $locale) ?: self::DEFAULT_LOCALE;

        $availableLocales = self::availableLocales();
        if (isset($availableLocales[$locale])) {
            return $locale;
        }

        $baseLocale = explode('-', $locale)[0] ?? self::DEFAULT_LOCALE;
        if (isset($availableLocales[$baseLocale])) {
            return $baseLocale;
        }

        return isset($availableLocales[self::DEFAULT_LOCALE])
            ? self::DEFAULT_LOCALE
            : array_key_first($availableLocales);
    }

    private static function loadLocale(string $locale): array {
        $path = self::languagePath() . $locale . '.php';
        return self::loadLocaleFile($path);
    }

    private static function loadLocaleFile(string $path): array {
        if (!is_file($path)) {
            return [];
        }

        $messages = require $path;
        return is_array($messages) ? $messages : [];
    }

    private static function languagePath(): string {
        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
        return rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Language' . DIRECTORY_SEPARATOR;
    }

    private static function arrayGet(array $messages, string $key) {
        $current = $messages;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private static function stripMeta(array $messages): array {
        unset($messages['_meta']);
        return $messages;
    }

    private static function flatten(array $messages, string $prefix = ''): array {
        $flat = [];

        foreach ($messages as $key => $value) {
            $fullKey = $prefix === '' ? (string)$key : $prefix . '.' . $key;

            if (is_array($value)) {
                $flat += self::flatten($value, $fullKey);
                continue;
            }

            $flat[$fullKey] = (string)$value;
        }

        return $flat;
    }
}
