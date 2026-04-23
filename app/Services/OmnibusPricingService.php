<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Database;

class OmnibusPricingService {
    private $db;
    private $timeZone;

    public function __construct(Database $db = null) {
        $this->db = $db ?? Database::getInstance();
        $timezoneName = date_default_timezone_get() ?: 'UTC';
        $this->timeZone = new \DateTimeZone($timezoneName);
    }

    public function getDisplayData(
        string $storeHash,
        int $productId,
        ?int $variantId,
        string $currency,
        $currentPrice = null,
        \DateTimeImmutable $referenceAt = null,
        array $options = []
    ): array {
        $referenceAt = $this->normalizeDateTime($referenceAt ?? new \DateTimeImmutable('now', $this->timeZone));
        $historyContext = $this->fetchHistoryContext(
            $storeHash,
            $productId,
            $variantId,
            $currency,
            $referenceAt->format('Y-m-d H:i:s')
        );
        $historyRows = $historyContext['rows'];

        $currentPriceValue = $currentPrice !== null
            ? $this->normalizePrice($currentPrice)
            : $this->getLastKnownPrice($historyRows);

        $historyLatestRecordedAt = !empty($historyContext['last_recorded_at'])
            ? new \DateTimeImmutable($historyContext['last_recorded_at'], $this->timeZone)
            : null;
        $currentPriceObservedAt = $this->normalizeOptionalDateTime($options['current_price_observed_at'] ?? null);

        if (
            $currentPriceValue !== null &&
            $currentPriceObservedAt !== null &&
            $historyLatestRecordedAt !== null &&
            $currentPriceObservedAt < $historyLatestRecordedAt
        ) {
            $historyCurrentPrice = $this->getLastKnownPrice($historyRows);
            if ($historyCurrentPrice !== null) {
                $currentPriceValue = $historyCurrentPrice;
            }
        }

        if ($currentPriceValue === null) {
            return [
                'current_price' => null,
                'rolling_lowest_price_last_30_days' => null,
                'lowest_price_last_30_days' => null,
                'is_discounted_now' => false,
                'omnibus_reference_price' => null,
                'has_full_30_day_history' => false,
                'discount_started_at' => null,
                'omnibus_window_start' => null,
                'reference_at' => $referenceAt->format('Y-m-d H:i:s'),
                'currency' => $currency,
                'effective_currency' => $historyContext['effective_currency'] ?? $currency,
                'currency_fallback_used' => $historyContext['currency_fallback_used'] ?? false,
                'last_history_recorded_at' => $historyContext['last_recorded_at'] ?? null,
                'product_id' => $productId,
                'variant_id' => $variantId,
            ];
        }

        $dto = $this->calculateDisplayData($historyRows, $currentPriceValue, $referenceAt, $options);

        return $dto + [
            'currency' => $currency,
            'effective_currency' => $historyContext['effective_currency'] ?? $currency,
            'currency_fallback_used' => $historyContext['currency_fallback_used'] ?? false,
            'last_history_recorded_at' => $historyContext['last_recorded_at'] ?? null,
            'product_id' => $productId,
            'variant_id' => $variantId,
        ];
    }

    public function calculateDisplayData(
        array $historyRows,
        string $currentPrice,
        \DateTimeImmutable $referenceAt,
        array $options = []
    ): array {
        $referenceAt = $this->normalizeDateTime($referenceAt);
        $normalizedRows = $this->normalizeHistoryRows($historyRows, $referenceAt);
        $states = $this->buildPriceStates($normalizedRows);
        $currentPrice = $this->normalizePrice($currentPrice);
        $requireFullWindowHistory = !empty($options['require_full_30_days_history']);

        if (empty($states)) {
            $states[] = [
                'price' => $currentPrice,
                'recorded_at' => $referenceAt->format('Y-m-d H:i:s'),
            ];
        } elseif ($states[count($states) - 1]['price'] !== $currentPrice) {
            $states[] = [
                'price' => $currentPrice,
                'recorded_at' => $referenceAt->format('Y-m-d H:i:s'),
            ];
        }

        $currentState = $states[count($states) - 1];
        $previousState = count($states) > 1 ? $states[count($states) - 2] : null;
        $previousDistinctPrice = $previousState['price'] ?? null;
        $isDiscountedNow = $previousDistinctPrice !== null
            && $this->comparePrices($currentPrice, $previousDistinctPrice) < 0;

        $rollingWindowStart = $referenceAt->sub(new \DateInterval('P30D'));
        $rollingLowest = $this->calculateWindowMinimum(
            $normalizedRows,
            $rollingWindowStart,
            $referenceAt,
            false
        );
        $hasFull30DayHistory = $this->hasHistoryAtOrBefore($normalizedRows, $rollingWindowStart);
        if ($requireFullWindowHistory && !$hasFull30DayHistory) {
            $rollingLowest = null;
        }

        $omnibusReferencePrice = null;
        $discountStartedAt = null;
        $omnibusWindowStart = null;

        if ($isDiscountedNow) {
            $discountStartedAt = new \DateTimeImmutable($currentState['recorded_at'], $this->timeZone);
            $omnibusWindowStart = $discountStartedAt->sub(new \DateInterval('P30D'));
            $omnibusReferencePrice = $this->calculateWindowMinimum(
                $normalizedRows,
                $omnibusWindowStart,
                $discountStartedAt,
                true
            );

            if ($requireFullWindowHistory && !$this->hasHistoryAtOrBefore($normalizedRows, $omnibusWindowStart)) {
                $omnibusReferencePrice = null;
            }
        }

        return [
            'current_price' => $currentPrice,
            'rolling_lowest_price_last_30_days' => $rollingLowest,
            'lowest_price_last_30_days' => $rollingLowest,
            'is_discounted_now' => $isDiscountedNow,
            'omnibus_reference_price' => $omnibusReferencePrice,
            'has_full_30_day_history' => $hasFull30DayHistory,
            'discount_started_at' => $discountStartedAt ? $discountStartedAt->format('Y-m-d H:i:s') : null,
            'omnibus_window_start' => $omnibusWindowStart ? $omnibusWindowStart->format('Y-m-d H:i:s') : null,
            'reference_at' => $referenceAt->format('Y-m-d H:i:s'),
        ];
    }

    private function fetchHistoryContext(
        string $storeHash,
        int $productId,
        ?int $variantId,
        string $currency,
        string $referenceAt
    ): array {
        $variantSql = $variantId === null ? 'variant_id IS NULL' : 'variant_id = ?';
        $params = [$storeHash, $productId];
        if ($variantId !== null) {
            $params[] = $variantId;
        }
        $params[] = $referenceAt;

        $rows = $this->db->fetchAll(
            "SELECT price, currency, recorded_at
             FROM product_price_history
             WHERE store_hash = ?
               AND product_id = ?
               AND {$variantSql}
               AND recorded_at <= ?
             ORDER BY recorded_at ASC, id ASC",
            $params
        );

        if (empty($rows)) {
            return [
                'rows' => [],
                'effective_currency' => $currency,
                'currency_fallback_used' => false,
                'last_recorded_at' => null,
            ];
        }

        $rowsByCurrency = [];
        foreach ($rows as $row) {
            $rowCurrency = (string)($row['currency'] ?? '');
            $rowsByCurrency[$rowCurrency][] = $row;
        }

        $selectedCurrency = $currency;
        $selectedRows = $rowsByCurrency[$currency] ?? [];
        $currencyFallbackUsed = false;

        if (empty($selectedRows) && count($rowsByCurrency) === 1) {
            $selectedCurrency = array_key_first($rowsByCurrency);
            $selectedRows = $rowsByCurrency[$selectedCurrency];
            $currencyFallbackUsed = $selectedCurrency !== $currency;
        }

        $lastRecordedAt = !empty($selectedRows)
            ? $selectedRows[count($selectedRows) - 1]['recorded_at']
            : null;

        return [
            'rows' => $selectedRows,
            'effective_currency' => $selectedCurrency,
            'currency_fallback_used' => $currencyFallbackUsed,
            'last_recorded_at' => $lastRecordedAt,
        ];
    }

    private function normalizeHistoryRows(array $historyRows, \DateTimeImmutable $referenceAt): array {
        $referenceAt = $this->normalizeDateTime($referenceAt);
        $rows = [];

        foreach ($historyRows as $row) {
            if (!isset($row['price'], $row['recorded_at'])) {
                continue;
            }

            $recordedAt = new \DateTimeImmutable((string)$row['recorded_at'], $this->timeZone);
            if ($recordedAt > $referenceAt) {
                continue;
            }

            $rows[] = [
                'price' => $this->normalizePrice($row['price']),
                'recorded_at' => $recordedAt->format('Y-m-d H:i:s'),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp($left['recorded_at'], $right['recorded_at']);
        });

        return $rows;
    }

    private function buildPriceStates(array $rows): array {
        $states = [];

        foreach ($rows as $row) {
            if (empty($states) || $states[count($states) - 1]['price'] !== $row['price']) {
                $states[] = $row;
            }
        }

        return $states;
    }

    private function calculateWindowMinimum(
        array $rows,
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        bool $excludeEndBoundary
    ): ?string {
        $windowStart = $this->normalizeDateTime($windowStart);
        $windowEnd = $this->normalizeDateTime($windowEnd);

        $carryPrice = null;
        $minimum = null;

        foreach ($rows as $row) {
            $recordedAt = new \DateTimeImmutable($row['recorded_at'], $this->timeZone);

            if ($recordedAt < $windowStart) {
                $carryPrice = $row['price'];
                continue;
            }

            if ($excludeEndBoundary && $recordedAt >= $windowEnd) {
                break;
            }

            if (!$excludeEndBoundary && $recordedAt > $windowEnd) {
                break;
            }

            if ($minimum === null || $this->comparePrices($row['price'], $minimum) < 0) {
                $minimum = $row['price'];
            }
        }

        if ($carryPrice !== null && ($minimum === null || $this->comparePrices($carryPrice, $minimum) < 0)) {
            $minimum = $carryPrice;
        }

        return $minimum;
    }

    private function hasHistoryAtOrBefore(array $rows, \DateTimeImmutable $boundary): bool {
        $boundary = $this->normalizeDateTime($boundary);
        $boundarySql = $boundary->format('Y-m-d H:i:s');

        foreach ($rows as $row) {
            if ($row['recorded_at'] <= $boundarySql) {
                return true;
            }
        }

        return false;
    }

    private function getLastKnownPrice(array $rows): ?string {
        if (empty($rows)) {
            return null;
        }

        $lastRow = $rows[count($rows) - 1];
        return isset($lastRow['price']) ? $this->normalizePrice($lastRow['price']) : null;
    }

    private function normalizeDateTime(\DateTimeImmutable $dateTime): \DateTimeImmutable {
        return $dateTime->setTimezone($this->timeZone);
    }

    private function normalizeOptionalDateTime($dateTime): ?\DateTimeImmutable {
        if ($dateTime === null || $dateTime === '') {
            return null;
        }

        if ($dateTime instanceof \DateTimeImmutable) {
            return $this->normalizeDateTime($dateTime);
        }

        if ($dateTime instanceof \DateTimeInterface) {
            return $this->normalizeDateTime(\DateTimeImmutable::createFromInterface($dateTime));
        }

        return $this->normalizeDateTime(new \DateTimeImmutable((string)$dateTime, $this->timeZone));
    }

    private function normalizePrice($price): ?string {
        if ($price === null || $price === '') {
            return null;
        }

        return number_format((float)$price, 4, '.', '');
    }

    private function comparePrices(string $left, string $right): int {
        $leftValue = round((float)$left, 4);
        $rightValue = round((float)$right, 4);

        if ($leftValue < $rightValue) {
            return -1;
        }

        if ($leftValue > $rightValue) {
            return 1;
        }

        return 0;
    }
}
