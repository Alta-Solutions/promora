<?php
declare(strict_types=1);

/**
 * Migrates BigCommerce Inventory Locations from one store to another.
 *
 * Required credentials can be supplied as CLI options or environment variables:
 *   --source-store, SOURCE_STORE_HASH
 *   --source-token, SOURCE_AUTH_TOKEN
 *   --target-store, TARGET_STORE_HASH
 *   --target-token, TARGET_AUTH_TOKEN
 */

const API_BASE_URL = 'https://api.bigcommerce.com/stores/%s/v3';
const LOCATIONS_PATH = '/inventory/locations';
const DEFAULT_BATCH_SIZE = 50;
const DEFAULT_PAGE_LIMIT = 250;

try {
    main($argv);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

function main(array $argv): void
{
    if (PHP_SAPI !== 'cli') {
        fail('This script must be run from the command line.');
    }

    $options = parseOptions();

    if ($options['help']) {
        printUsage();
        exit(0);
    }

    if (!function_exists('curl_init')) {
        fail('PHP cURL extension is required.');
    }

    $sourceStore = requiredOption($options, 'source_store', 'SOURCE_STORE_HASH');
    $sourceToken = requiredOption($options, 'source_token', 'SOURCE_AUTH_TOKEN');
    $targetStore = requiredOption($options, 'target_store', 'TARGET_STORE_HASH');
    $targetToken = requiredOption($options, 'target_token', 'TARGET_AUTH_TOKEN');

    $batchSize = normalizePositiveInt($options['batch_size'], DEFAULT_BATCH_SIZE, 'batch-size');
    $pageLimit = normalizePositiveInt($options['page_limit'], DEFAULT_PAGE_LIMIT, 'page-limit');
    $dryRun = $options['dry_run'];
    $skipExisting = $options['skip_existing'];
    $continueOnError = $options['continue_on_error'];
    $validate = $options['validate'];
    $fallbacks = buildFallbackValues($options);

    logLine('Fetching source locations...');
    $sourceLocations = fetchAllLocations($sourceStore, $sourceToken, $pageLimit);
    logLine(sprintf('Fetched %d source location(s).', count($sourceLocations)));

    $targetCodes = [];
    $targetLocations = null;
    if ($skipExisting) {
        logLine('Fetching target locations for duplicate-code check...');
        $targetLocations = fetchAllLocations($targetStore, $targetToken, $pageLimit);
        $targetCodes = indexLocationCodes($targetLocations);
        logLine(sprintf('Fetched %d target location(s).', count($targetLocations)));
    }

    $skipped = [];
    $payload = [];

    foreach ($sourceLocations as $location) {
        $code = isset($location['code']) ? (string)$location['code'] : '';

        if ($skipExisting && $code !== '' && isset($targetCodes[$code])) {
            $skipped[] = $code;
            continue;
        }

        $payload[] = normalizeLocationForCreate($location);
    }

    [$payload, $fallbackStats] = applyFallbackValues($payload, $fallbacks);
    logFallbackStats($fallbackStats);

    if ($validate) {
        validatePayload($payload);
    }

    logLine(sprintf('Prepared %d location(s) for creation.', count($payload)));
    warnIfActiveLocationLimitLooksRisky($payload, $targetLocations);

    if ($skipped !== []) {
        logLine(sprintf('Skipped %d existing location(s): %s', count($skipped), implode(', ', $skipped)));
    }

    if ($payload === []) {
        logLine('Nothing to create.');
        exit(0);
    }

    if ($dryRun) {
        logLine('Dry run enabled. No locations were created.');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $created = 0;
    $failures = [];
    $batches = array_chunk($payload, $batchSize);

    foreach ($batches as $index => $batch) {
        $batchNumber = $index + 1;
        logLine(sprintf('Creating batch %d/%d (%d location(s))...', $batchNumber, count($batches), count($batch)));

        try {
            $response = apiRequest('POST', $targetStore, $targetToken, LOCATIONS_PATH, [], $batch);
            $created += count($batch);
            $transactionId = $response['json']['transaction_id'] ?? null;
            logLine(sprintf(
                'Batch %d created.%s',
                $batchNumber,
                $transactionId ? ' Transaction ID: ' . $transactionId : ''
            ));
        } catch (RuntimeException $exception) {
            if (!$continueOnError || count($batch) === 1) {
                throw $exception;
            }

            logLine(sprintf('Batch %d failed. Retrying locations one by one...', $batchNumber));
            foreach ($batch as $location) {
                $code = isset($location['code']) ? (string)$location['code'] : '[missing code]';

                try {
                    $response = apiRequest('POST', $targetStore, $targetToken, LOCATIONS_PATH, [], [$location]);
                    $created++;
                    $transactionId = $response['json']['transaction_id'] ?? null;
                    logLine(sprintf(
                        'Created %s.%s',
                        $code,
                        $transactionId ? ' Transaction ID: ' . $transactionId : ''
                    ));
                } catch (RuntimeException $itemException) {
                    $failures[] = $code . ': ' . $itemException->getMessage();
                    logLine('Failed ' . $code . ': ' . $itemException->getMessage());
                }
            }
        }
    }

    logLine(sprintf('Created %d location(s).', $created));

    if ($failures !== []) {
        logLine('Failures:');
        foreach ($failures as $failure) {
            logLine(' - ' . $failure);
        }
        exit(1);
    }
}

function parseOptions(): array
{
    $raw = getopt('', [
        'source-store:',
        'source-token:',
        'target-store:',
        'target-token:',
        'batch-size:',
        'page-limit:',
        'default-label:',
        'default-type-id:',
        'default-address1:',
        'default-city:',
        'default-state:',
        'default-zip:',
        'default-email:',
        'default-country-code:',
        'default-latitude:',
        'default-longitude:',
        'dry-run',
        'skip-existing',
        'no-skip-existing',
        'continue-on-error',
        'no-validate',
        'help',
    ]);

    if ($raw === false) {
        fail('Failed to parse command line options.');
    }

    return [
        'source_store' => $raw['source-store'] ?? null,
        'source_token' => $raw['source-token'] ?? null,
        'target_store' => $raw['target-store'] ?? null,
        'target_token' => $raw['target-token'] ?? null,
        'batch_size' => $raw['batch-size'] ?? null,
        'page_limit' => $raw['page-limit'] ?? null,
        'default_label' => $raw['default-label'] ?? null,
        'default_type_id' => $raw['default-type-id'] ?? null,
        'default_address1' => $raw['default-address1'] ?? null,
        'default_city' => $raw['default-city'] ?? null,
        'default_state' => $raw['default-state'] ?? null,
        'default_zip' => $raw['default-zip'] ?? null,
        'default_email' => $raw['default-email'] ?? null,
        'default_country_code' => $raw['default-country-code'] ?? null,
        'default_latitude' => $raw['default-latitude'] ?? null,
        'default_longitude' => $raw['default-longitude'] ?? null,
        'dry_run' => array_key_exists('dry-run', $raw),
        'skip_existing' => !array_key_exists('no-skip-existing', $raw),
        'continue_on_error' => array_key_exists('continue-on-error', $raw),
        'validate' => !array_key_exists('no-validate', $raw),
        'help' => array_key_exists('help', $raw),
    ];
}

function printUsage(): void
{
    echo <<<TEXT
Usage:
  php migrate-locations.php --source-store=SOURCE_HASH --source-token=SOURCE_TOKEN --target-store=TARGET_HASH --target-token=TARGET_TOKEN [options]

Environment variable alternatives:
  SOURCE_STORE_HASH, SOURCE_AUTH_TOKEN, TARGET_STORE_HASH, TARGET_AUTH_TOKEN
  DEFAULT_LOCATION_LABEL, DEFAULT_LOCATION_TYPE_ID
  DEFAULT_LOCATION_ADDRESS1, DEFAULT_LOCATION_CITY, DEFAULT_LOCATION_STATE
  DEFAULT_LOCATION_ZIP, DEFAULT_LOCATION_EMAIL, DEFAULT_LOCATION_COUNTRY_CODE
  DEFAULT_LOCATION_LATITUDE, DEFAULT_LOCATION_LONGITUDE

Options:
  --dry-run             Fetch and transform locations, then print the create payload without POSTing.
  --batch-size=50       Number of locations per create request.
  --page-limit=250      Number of locations per GET page.
  --default-label=TEXT  Fallback label if a source location is missing label.
  --default-type-id=ID  Fallback type_id if missing. Valid values: PHYSICAL, VIRTUAL.
  --default-address1=TEXT
  --default-city=TEXT
  --default-state=TEXT
  --default-zip=TEXT
  --default-email=TEXT
  --default-country-code=TEXT
  --default-latitude=NUMBER
  --default-longitude=NUMBER
                        Fallback address values for PHYSICAL locations with missing required fields.
  --no-skip-existing    Do not fetch target locations and do not skip duplicate location codes.
                        By default, duplicate target location codes are skipped.
  --continue-on-error   If a batch fails, retry each location individually and continue.
  --no-validate         Disable local payload validation before POSTing.
  --help                Show this help.

TEXT;
}

function requiredOption(array $options, string $optionKey, string $envKey): string
{
    $value = $options[$optionKey] ?: getenv($envKey);

    if ($value === false || trim((string)$value) === '') {
        fail(sprintf('Missing required option. Provide --%s or %s.', str_replace('_', '-', $optionKey), $envKey));
    }

    return trim((string)$value);
}

function normalizePositiveInt($value, int $default, string $name): int
{
    if ($value === null || $value === false || $value === '') {
        return $default;
    }

    if (!ctype_digit((string)$value) || (int)$value < 1) {
        fail(sprintf('--%s must be a positive integer.', $name));
    }

    return (int)$value;
}

function buildFallbackValues(array $options): array
{
    $fallbacks = [
        'location' => [
            'label' => fallbackOption($options, 'default_label', 'DEFAULT_LOCATION_LABEL'),
            'type_id' => fallbackOption($options, 'default_type_id', 'DEFAULT_LOCATION_TYPE_ID'),
        ],
        'address' => [
            'address1' => fallbackOption($options, 'default_address1', 'DEFAULT_LOCATION_ADDRESS1'),
            'city' => fallbackOption($options, 'default_city', 'DEFAULT_LOCATION_CITY'),
            'state' => fallbackOption($options, 'default_state', 'DEFAULT_LOCATION_STATE'),
            'zip' => fallbackOption($options, 'default_zip', 'DEFAULT_LOCATION_ZIP'),
            'email' => fallbackOption($options, 'default_email', 'DEFAULT_LOCATION_EMAIL'),
            'country_code' => fallbackOption($options, 'default_country_code', 'DEFAULT_LOCATION_COUNTRY_CODE'),
        ],
        'geo_coordinates' => [
            'latitude' => fallbackOption($options, 'default_latitude', 'DEFAULT_LOCATION_LATITUDE'),
            'longitude' => fallbackOption($options, 'default_longitude', 'DEFAULT_LOCATION_LONGITUDE'),
        ],
    ];

    if ($fallbacks['location']['type_id'] !== null) {
        $fallbacks['location']['type_id'] = strtoupper($fallbacks['location']['type_id']);
        if (!in_array($fallbacks['location']['type_id'], ['PHYSICAL', 'VIRTUAL'], true)) {
            fail('--default-type-id must be PHYSICAL or VIRTUAL.');
        }
    }

    foreach (['latitude', 'longitude'] as $coordinate) {
        $value = $fallbacks['geo_coordinates'][$coordinate];
        if ($value === null) {
            continue;
        }

        if (!is_numeric($value)) {
            fail(sprintf('--default-%s must be numeric.', $coordinate));
        }

        $fallbacks['geo_coordinates'][$coordinate] = (float)$value;
    }

    return $fallbacks;
}

function fallbackOption(array $options, string $optionKey, string $envKey): ?string
{
    $value = $options[$optionKey] ?? null;

    if ($value === null || $value === false || (is_string($value) && trim($value) === '')) {
        $value = getenv($envKey);
    }

    if ($value === false || $value === null || trim((string)$value) === '') {
        return null;
    }

    return trim((string)$value);
}

function applyFallbackValues(array $payload, array $fallbacks): array
{
    $stats = [];

    foreach ($payload as $index => $location) {
        foreach ($fallbacks['location'] as $field => $value) {
            if ($value !== null && (!array_key_exists($field, $location) || isMissingValue($location[$field]))) {
                $payload[$index][$field] = $value;
                incrementStat($stats, $field);
            }
        }

        $typeId = isset($payload[$index]['type_id']) ? strtoupper((string)$payload[$index]['type_id']) : '';
        if ($typeId !== 'PHYSICAL') {
            continue;
        }

        if (!isset($payload[$index]['address']) || !is_array($payload[$index]['address'])) {
            $payload[$index]['address'] = [];
        }

        foreach ($fallbacks['address'] as $field => $value) {
            if ($value !== null && (!array_key_exists($field, $payload[$index]['address']) || isMissingValue($payload[$index]['address'][$field]))) {
                $payload[$index]['address'][$field] = $value;
                incrementStat($stats, 'address.' . $field);
            }
        }

        $hasGeoFallback = $fallbacks['geo_coordinates']['latitude'] !== null || $fallbacks['geo_coordinates']['longitude'] !== null;
        if ($hasGeoFallback && (!isset($payload[$index]['address']['geo_coordinates']) || !is_array($payload[$index]['address']['geo_coordinates']))) {
            $payload[$index]['address']['geo_coordinates'] = [];
        }

        if (!isset($payload[$index]['address']['geo_coordinates']) || !is_array($payload[$index]['address']['geo_coordinates'])) {
            continue;
        }

        foreach ($fallbacks['geo_coordinates'] as $field => $value) {
            if ($value !== null && (!array_key_exists($field, $payload[$index]['address']['geo_coordinates']) || isMissingValue($payload[$index]['address']['geo_coordinates'][$field]))) {
                $payload[$index]['address']['geo_coordinates'][$field] = $value;
                incrementStat($stats, 'address.geo_coordinates.' . $field);
            }
        }
    }

    return [$payload, $stats];
}

function isMissingValue($value): bool
{
    return $value === null || (is_string($value) && trim($value) === '');
}

function incrementStat(array &$stats, string $field): void
{
    if (!isset($stats[$field])) {
        $stats[$field] = 0;
    }

    $stats[$field]++;
}

function logFallbackStats(array $stats): void
{
    if ($stats === []) {
        return;
    }

    ksort($stats);
    $messages = [];
    foreach ($stats as $field => $count) {
        $messages[] = $field . '=' . $count;
    }

    logLine('Applied fallback values for missing fields: ' . implode(', ', $messages));
}

function fetchAllLocations(string $storeHash, string $token, int $pageLimit): array
{
    $locations = [];
    $page = 1;
    $totalPages = null;

    do {
        $response = apiRequest('GET', $storeHash, $token, LOCATIONS_PATH, [
            'page' => $page,
            'limit' => $pageLimit,
        ]);

        $body = $response['json'];
        $pageData = $body['data'] ?? [];

        if (!is_array($pageData)) {
            fail('Unexpected API response: locations data is not an array.');
        }

        foreach ($pageData as $location) {
            if (is_array($location)) {
                $locations[] = $location;
            }
        }

        $pagination = $body['meta']['pagination'] ?? null;
        if (is_array($pagination) && isset($pagination['total_pages'])) {
            $totalPages = (int)$pagination['total_pages'];
        } elseif (count($pageData) < $pageLimit) {
            $totalPages = $page;
        }

        $page++;
    } while ($totalPages === null || $page <= $totalPages);

    return $locations;
}

function indexLocationCodes(array $locations): array
{
    $codes = [];

    foreach ($locations as $location) {
        if (isset($location['code']) && (string)$location['code'] !== '') {
            $codes[(string)$location['code']] = true;
        }
    }

    return $codes;
}

function normalizeLocationForCreate(array $location): array
{
    $allowedFields = [
        'code',
        'label',
        'description',
        'managed_by_external_source',
        'type_id',
        'enabled',
        'operating_hours',
        'time_zone',
        'address',
        'storefront_visibility',
        'special_hours',
    ];

    $payload = [];
    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $location)) {
            $payload[$field] = removeNullValues($location[$field]);
        }
    }

    if (isset($payload['address']) && is_array($payload['address']) && $payload['address'] === []) {
        unset($payload['address']);
    }

    if (isset($payload['operating_hours']) && is_array($payload['operating_hours']) && $payload['operating_hours'] === []) {
        unset($payload['operating_hours']);
    }

    if (isset($payload['address']['geo_coordinates']) && is_array($payload['address']['geo_coordinates'])) {
        foreach (['latitude', 'longitude'] as $coordinate) {
            if (isset($payload['address']['geo_coordinates'][$coordinate]) && is_numeric($payload['address']['geo_coordinates'][$coordinate])) {
                $payload['address']['geo_coordinates'][$coordinate] = (float)$payload['address']['geo_coordinates'][$coordinate];
            }
        }
    }

    return $payload;
}

function removeNullValues($value)
{
    if (!is_array($value)) {
        return $value;
    }

    $clean = [];
    $isList = isListArray($value);
    foreach ($value as $key => $item) {
        if ($item === null) {
            continue;
        }

        $clean[$key] = removeNullValues($item);
    }

    return $isList ? array_values($clean) : $clean;
}

function isListArray(array $value): bool
{
    $expectedKey = 0;

    foreach (array_keys($value) as $key) {
        if ($key !== $expectedKey) {
            return false;
        }

        $expectedKey++;
    }

    return true;
}

function validatePayload(array $payload): void
{
    $errors = [];

    foreach ($payload as $index => $location) {
        $prefix = sprintf('Location #%d', $index + 1);
        $code = isset($location['code']) && (string)$location['code'] !== '' ? (string)$location['code'] : null;

        if ($code !== null) {
            $prefix .= ' (' . $code . ')';
        }

        foreach (['code', 'label', 'type_id'] as $requiredField) {
            if (!isset($location[$requiredField]) || trim((string)$location[$requiredField]) === '') {
                $errors[] = $prefix . ' missing required field: ' . $requiredField;
            }
        }

        $typeId = isset($location['type_id']) ? strtoupper((string)$location['type_id']) : '';
        if ($typeId !== '' && !in_array($typeId, ['PHYSICAL', 'VIRTUAL'], true)) {
            $errors[] = $prefix . ' has invalid type_id: ' . $location['type_id'];
        }

        if ($typeId === 'PHYSICAL') {
            if (!isset($location['address']) || !is_array($location['address'])) {
                $errors[] = $prefix . ' is PHYSICAL and missing address.';
                continue;
            }

            foreach (['address1', 'city', 'state', 'zip', 'email', 'country_code'] as $requiredAddressField) {
                if (!isset($location['address'][$requiredAddressField]) || trim((string)$location['address'][$requiredAddressField]) === '') {
                    $errors[] = $prefix . ' missing required address field: ' . $requiredAddressField;
                }
            }

            $geo = $location['address']['geo_coordinates'] ?? null;
            if (!is_array($geo)) {
                $errors[] = $prefix . ' missing required address field: geo_coordinates';
            } else {
                foreach (['latitude', 'longitude'] as $coordinate) {
                    if (!isset($geo[$coordinate]) || !is_numeric($geo[$coordinate])) {
                        $errors[] = $prefix . ' missing required geo_coordinates field: ' . $coordinate;
                    }
                }
            }
        }
    }

    if ($errors !== []) {
        fail("Payload validation failed:\n" . implode("\n", array_map(static function (string $error): string {
            return ' - ' . $error;
        }, $errors)));
    }
}

function warnIfActiveLocationLimitLooksRisky(array $payload, ?array $targetLocations): void
{
    $activeToCreate = countActiveLocations($payload);

    if ($activeToCreate > 100) {
        logLine(sprintf(
            'Warning: BigCommerce documents a limit of 100 active locations; this payload contains %d active location(s).',
            $activeToCreate
        ));
    }

    if ($targetLocations === null) {
        return;
    }

    $targetActive = countActiveLocations($targetLocations);
    if ($targetActive + $activeToCreate > 100) {
        logLine(sprintf(
            'Warning: target has %d active location(s) and payload has %d active location(s), which may exceed BigCommerce active-location limits.',
            $targetActive,
            $activeToCreate
        ));
    }
}

function countActiveLocations(array $locations): int
{
    $active = 0;

    foreach ($locations as $location) {
        if (!is_array($location)) {
            continue;
        }

        if (!array_key_exists('enabled', $location) || $location['enabled'] !== false) {
            $active++;
        }
    }

    return $active;
}

function apiRequest(
    string $method,
    string $storeHash,
    string $token,
    string $path,
    array $query = [],
    ?array $body = null
): array {
    $url = sprintf(API_BASE_URL, rawurlencode($storeHash)) . $path;

    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $attempt = 0;
    $maxAttempts = 4;
    $lastError = null;

    while ($attempt < $maxAttempts) {
        $attempt++;
        $headers = [];
        $responseHeaders = [];
        $requestHeaders = [
            'Accept: application/json',
            'X-Auth-Token: ' . $token,
        ];

        $curl = curl_init($url);
        if ($curl === false) {
            fail('Failed to initialize cURL.');
        }

        if ($body !== null) {
            $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($encodedBody === false) {
                throw new RuntimeException('Failed to encode request body as JSON.');
            }

            $requestHeaders[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, $encodedBody);
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$headers, &$responseHeaders): int {
                $length = strlen($headerLine);
                $headerLine = trim($headerLine);

                if ($headerLine === '' || strpos($headerLine, ':') === false) {
                    return $length;
                }

                [$name, $value] = explode(':', $headerLine, 2);
                $name = strtolower(trim($name));
                $value = trim($value);
                $headers[$name] = $value;
                $responseHeaders[$name][] = $value;

                return $length;
            },
        ]);

        $rawBody = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($rawBody === false) {
            $lastError = new RuntimeException('cURL error: ' . $curlError);
        } elseif ($status >= 200 && $status < 300) {
            $decoded = decodeJsonResponse((string)$rawBody);

            return [
                'status' => $status,
                'json' => $decoded,
                'headers' => $responseHeaders,
            ];
        } else {
            $lastError = new RuntimeException(sprintf(
                'BigCommerce API error %d: %s',
                $status,
                summarizeApiError((string)$rawBody)
            ));
        }

        if (!shouldRetry($status, $attempt, $maxAttempts)) {
            break;
        }

        $retryAfter = isset($headers['retry-after']) && ctype_digit($headers['retry-after'])
            ? (int)$headers['retry-after']
            : min(8, 2 ** $attempt);

        sleep($retryAfter);
    }

    throw $lastError ?: new RuntimeException('Unknown API request failure.');
}

function decodeJsonResponse(string $rawBody): array
{
    if (trim($rawBody) === '') {
        return [];
    }

    try {
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Invalid JSON response: ' . $exception->getMessage());
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('Unexpected JSON response type.');
    }

    return $decoded;
}

function summarizeApiError(string $rawBody): string
{
    if (trim($rawBody) === '') {
        return '[empty response body]';
    }

    try {
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return substr($rawBody, 0, 1000);
    }

    if (!is_array($decoded)) {
        return substr($rawBody, 0, 1000);
    }

    return json_encode($decoded, JSON_UNESCAPED_SLASHES);
}

function shouldRetry(int $status, int $attempt, int $maxAttempts): bool
{
    if ($attempt >= $maxAttempts) {
        return false;
    }

    return in_array($status, [429, 500, 502, 503, 504], true);
}

function logLine(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
