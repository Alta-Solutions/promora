<?php

use PHPUnit\Framework\TestCase;
use App\Services\PriceLogger;

class PriceLoggerTest extends TestCase {
    public function testLogPriceChangeUsesExplicitRecordedAtWhenProvided(): void {
        $db = new class {
            public $queries = [];

            public function fetchOne($sql, $params = []) {
                return false;
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };

        $logger = $this->createLogger($db, true);
        $logged = $logger->logPriceChange(
            'test-store',
            5718,
            4.50,
            'EUR',
            null,
            '2026-06-03 00:02:31'
        );

        $this->assertTrue($logged);
        $this->assertCount(1, $db->queries);
        $this->assertStringContainsString('VALUES (?, ?, ?, ?, ?, ?)', $db->queries[0]['sql']);
        $this->assertStringNotContainsString('NOW()', $db->queries[0]['sql']);
        $this->assertSame(
            ['test-store', 5718, null, 4.50, 'EUR', '2026-06-03 00:02:31'],
            $db->queries[0]['params']
        );
    }

    public function testLogPricesBatchUsesExplicitRecordedAtWhenProvided(): void {
        $db = new class {
            public $queries = [];

            public function fetchAll($sql, $params = []) {
                return [];
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };

        $logger = $this->createLogger($db, true);
        $inserted = $logger->logPricesBatch('test-store', [[
            'product_id' => 5718,
            'variant_id' => null,
            'price' => 4.50,
            'currency' => 'EUR',
            'recorded_at' => '2026-06-03 00:02:31',
        ]]);

        $this->assertSame(1, $inserted);
        $this->assertCount(1, $db->queries);
        $this->assertStringContainsString('VALUES (?, ?, ?, ?, ?, ?)', $db->queries[0]['sql']);
        $this->assertStringNotContainsString('NOW()', $db->queries[0]['sql']);
        $this->assertSame(
            ['test-store', 5718, null, 4.50, 'EUR', '2026-06-03 00:02:31'],
            $db->queries[0]['params']
        );
    }

    public function testLogPricesBatchKeepsNowWhenRecordedAtIsNotProvided(): void {
        $db = new class {
            public $queries = [];

            public function fetchAll($sql, $params = []) {
                return [];
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };

        $logger = $this->createLogger($db, true);
        $inserted = $logger->logPricesBatch('test-store', [[
            'product_id' => 5718,
            'variant_id' => null,
            'price' => 4.50,
            'currency' => 'EUR',
        ]]);

        $this->assertSame(1, $inserted);
        $this->assertStringContainsString('NOW()', $db->queries[0]['sql']);
        $this->assertSame(['test-store', 5718, null, 4.50, 'EUR'], $db->queries[0]['params']);
    }

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
