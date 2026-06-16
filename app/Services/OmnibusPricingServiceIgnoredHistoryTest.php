<?php

use PHPUnit\Framework\TestCase;
use App\Services\OmnibusPricingService;

class OmnibusPricingServiceIgnoredHistoryTest extends TestCase {
    public function testDisplayDataFetchesOnlyNonIgnoredHistoryRows(): void {
        $db = new class {
            public $historySql = '';

            public function fetchOne($sql, $params = []) {
                if (strpos($sql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'exists'];
                }

                return false;
            }

            public function fetchAll($sql, $params = []) {
                $this->historySql = preg_replace('/\s+/', ' ', trim($sql));

                return [
                    ['price' => '100.0000', 'currency' => 'EUR', 'recorded_at' => '2026-05-01 00:00:00'],
                    ['price' => '80.0000', 'currency' => 'EUR', 'recorded_at' => '2026-06-01 00:00:00'],
                ];
            }

            public function query($sql, $params = []) {}
        };

        $service = $this->createService($db);
        $dto = $service->getDisplayData(
            'store-a',
            100,
            null,
            'EUR',
            70.00,
            new DateTimeImmutable('2026-06-10 00:00:00')
        );

        $this->assertStringContainsString('ignored_at IS NULL', $db->historySql);
        $this->assertSame('80.0000', $dto['candidate_omnibus_reference_price']);
    }

    private function createService($db): OmnibusPricingService {
        $reflection = new ReflectionClass(OmnibusPricingService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $dbProperty = $reflection->getProperty('db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($service, $db);

        $timeZoneProperty = $reflection->getProperty('timeZone');
        $timeZoneProperty->setAccessible(true);
        $timeZoneProperty->setValue($service, new DateTimeZone(date_default_timezone_get() ?: 'UTC'));

        return $service;
    }
}
