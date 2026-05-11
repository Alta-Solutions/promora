<?php

use PHPUnit\Framework\TestCase;
use App\Services\PriceLogger;

class PriceLoggerTest extends TestCase {
    public function testSeedInitialPriceHistoryBatchInsertsBaselinePriceAtWindowStart(): void {
        $db = new class {
            public $queries = [];

            public function fetchOne($sql, $params = []) {
                return false;
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => $sql,
                    'params' => $params,
                ];
            }
        };

        $logger = $this->createLogger($db, true);
        $inserted = $logger->seedInitialPriceHistoryBatch('test-store', [[
            'product_id' => 5718,
            'variant_id' => null,
            'price' => 5.00,
            'currency' => 'EUR',
            'recorded_at' => '2026-04-11 16:20:03',
        ]]);

        $this->assertSame(1, $inserted);
        $this->assertCount(1, $db->queries);
        $this->assertSame(
            ['test-store', 5718, null, 5.0, 'EUR', '2026-04-11 16:20:03'],
            $db->queries[0]['params']
        );
    }

    public function testSeedInitialPriceHistoryBatchSkipsWhenBaselineAlreadyExists(): void {
        $db = new class {
            public $queries = [];

            public function fetchOne($sql, $params = []) {
                return ['id' => 100];
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => $sql,
                    'params' => $params,
                ];
            }
        };

        $logger = $this->createLogger($db, true);
        $inserted = $logger->seedInitialPriceHistoryBatch('test-store', [[
            'product_id' => 5718,
            'variant_id' => null,
            'price' => 5.00,
            'currency' => 'EUR',
            'recorded_at' => '2026-04-11 16:20:03',
        ]]);

        $this->assertSame(0, $inserted);
        $this->assertSame([], $db->queries);
    }

    private function createLogger($db, bool $hasVariantId): PriceLogger {
        $reflection = new ReflectionClass(PriceLogger::class);
        $logger = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($logger, 'db', $db);
        $this->setPrivateProperty($logger, 'priceHistoryHasVariantId', $hasVariantId);

        return $logger;
    }

    private function setPrivateProperty(object $object, string $property, $value): void {
        $reflectionProperty = new ReflectionProperty($object, $property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);
    }
}
