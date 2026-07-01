<?php

use PHPUnit\Framework\TestCase;
use App\Services\OmnibusSyncSchedulerService;

class OmnibusSyncSchedulerServiceTest extends TestCase {
    public function testBeforeFullSyncHourCreatesTargetedJobForDirtyProducts(): void {
        $db = new class {
            public $fetchAllCalls = [];

            public function fetchAll($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));
                $this->fetchAllCalls[] = ['sql' => $normalizedSql, 'params' => $params];

                if (strpos($normalizedSql, 'FROM products_cache') !== false) {
                    return [['product_id' => '3'], ['product_id' => '2']];
                }

                if (strpos($normalizedSql, 'recorded_at >=') !== false) {
                    return [['product_id' => '3'], ['product_id' => '4']];
                }

                if (strpos($normalizedSql, 'ignored_at >=') !== false) {
                    return [['product_id' => '5']];
                }

                return [];
            }

            public function fetchOne($sql, $params = []) {
                if (strpos($sql, "SHOW COLUMNS FROM product_price_history LIKE 'ignored_at'") !== false) {
                    return ['Field' => 'ignored_at'];
                }

                return false;
            }
        };

        $queues = [];
        $service = new OmnibusSyncSchedulerService($db, function ($storeHash) use (&$queues) {
            return $queues[$storeHash] = new class {
                public $targetedIds = [];
                public $targetedMeta = [];

                public function createTargetedOmnibusSyncJob(array $productIds, array $meta = []): array {
                    $this->targetedIds = $productIds;
                    $this->targetedMeta = $meta;
                    return [
                        'created' => true,
                        'job_id' => 101,
                        'reason' => 'created',
                        'product_ids' => $productIds,
                    ];
                }

                public function createOmnibusSyncJob(int $totalItems): array {
                    throw new RuntimeException('Full sync should not be scheduled.');
                }
            };
        }, 2);

        $result = $service->scheduleStore('store-a', new DateTimeImmutable('2026-06-16 01:30:00'));

        $this->assertSame('incremental', $result['mode']);
        $this->assertTrue($result['created']);
        $this->assertSame([2, 3, 4, 5], $queues['store-a']->targetedIds);
        $this->assertSame('scheduler_incremental', $queues['store-a']->targetedMeta['source']);
        $this->assertSame('2026-06-16 00:00:00', $queues['store-a']->targetedMeta['day_start']);
        $this->assertSame(2, $queues['store-a']->targetedMeta['cache_dirty_count']);
        $this->assertSame(2, $queues['store-a']->targetedMeta['history_dirty_count']);
        $this->assertSame(1, $queues['store-a']->targetedMeta['ignored_history_dirty_count']);
        $this->assertSame(4, $queues['store-a']->targetedMeta['total_dirty_count']);

        foreach ($db->fetchAllCalls as $call) {
            $this->assertSame('store-a', $call['params'][0]);
        }
    }

    public function testAfterFullSyncHourCreatesDailyFullJobWhenNotAlreadyCreatedToday(): void {
        $db = new class {
            public $fetchAllCalls = [];

            public function fetchOne($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

                if (strpos($normalizedSql, 'FROM sync_jobs') !== false) {
                    return false;
                }

                if (strpos($normalizedSql, "SHOW COLUMNS FROM products_cache LIKE 'type'") !== false) {
                    return ['Field' => 'type'];
                }

                if (strpos($normalizedSql, 'COUNT(DISTINCT product_id)') !== false) {
                    return ['total' => 12];
                }

                return false;
            }

            public function fetchAll($sql, $params = []) {
                $this->fetchAllCalls[] = $sql;
                return [];
            }
        };

        $queues = [];
        $service = new OmnibusSyncSchedulerService($db, function ($storeHash) use (&$queues) {
            return $queues[$storeHash] = new class {
                public $fullTotalItems = null;

                public function createOmnibusSyncJob(int $totalItems): array {
                    $this->fullTotalItems = $totalItems;
                    return ['created' => true, 'job_id' => 202, 'reason' => 'created'];
                }

                public function createTargetedOmnibusSyncJob(array $productIds, array $meta = []): array {
                    throw new RuntimeException('Targeted sync should not be scheduled.');
                }
            };
        }, 2);

        $result = $service->scheduleStore('store-a', new DateTimeImmutable('2026-06-16 02:01:00'));

        $this->assertSame('full', $result['mode']);
        $this->assertTrue($result['created']);
        $this->assertSame(12, $queues['store-a']->fullTotalItems);
        $this->assertSame([], $db->fetchAllCalls);
    }

    public function testAfterFullSyncAlreadyRanTodaySchedulesIncrementalDirtyProducts(): void {
        $db = new class {
            public function fetchOne($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

                if (strpos($normalizedSql, 'FROM sync_jobs') !== false) {
                    return ['id' => 77, 'status' => 'completed'];
                }

                return false;
            }

            public function fetchAll($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

                if (strpos($normalizedSql, 'FROM products_cache') !== false) {
                    return [['product_id' => 7]];
                }

                return [];
            }
        };

        $queues = [];
        $service = new OmnibusSyncSchedulerService($db, function ($storeHash) use (&$queues) {
            return $queues[$storeHash] = new class {
                public $targetedIds = [];

                public function createTargetedOmnibusSyncJob(array $productIds, array $meta = []): array {
                    $this->targetedIds = $productIds;
                    return ['created' => true, 'job_id' => 303, 'reason' => 'created'];
                }

                public function createOmnibusSyncJob(int $totalItems): array {
                    throw new RuntimeException('Second full sync should not be scheduled.');
                }
            };
        }, 2);

        $result = $service->scheduleStore('store-a', new DateTimeImmutable('2026-06-16 14:00:00'));

        $this->assertSame('incremental', $result['mode']);
        $this->assertTrue($result['created']);
        $this->assertSame([7], $queues['store-a']->targetedIds);
    }

    public function testNoDirtyProductsDoesNotCreateIncrementalJob(): void {
        $db = new class {
            public function fetchOne($sql, $params = []) {
                return false;
            }

            public function fetchAll($sql, $params = []) {
                return [];
            }
        };

        $queueFactoryCalled = false;
        $service = new OmnibusSyncSchedulerService($db, function () use (&$queueFactoryCalled) {
            $queueFactoryCalled = true;
            throw new RuntimeException('No queue should be created.');
        }, 2);

        $result = $service->scheduleStore('store-a', new DateTimeImmutable('2026-06-16 01:30:00'));

        $this->assertFalse($result['created']);
        $this->assertSame('no_dirty_products', $result['reason']);
        $this->assertFalse($queueFactoryCalled);
    }

    public function testScheduleAllStoresUsesEnabledStoresWithStoreContext(): void {
        $db = new class {
            public $contexts = [];

            public function fetchAll($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

                if (strpos($normalizedSql, 'FROM bigcommerce_stores') !== false) {
                    return [['store_hash' => 'store-a'], ['store_hash' => 'store-b']];
                }

                if (strpos($normalizedSql, 'FROM products_cache') !== false) {
                    return [['product_id' => $params[0] === 'store-a' ? 1 : 2]];
                }

                return [];
            }

            public function fetchOne($sql, $params = []) {
                return false;
            }

            public function setStoreContext($storeHash): void {
                $this->contexts[] = $storeHash;
            }
        };

        $service = new OmnibusSyncSchedulerService($db, function () {
            return new class {
                public function createTargetedOmnibusSyncJob(array $productIds, array $meta = []): array {
                    return ['created' => true, 'job_id' => 404, 'reason' => 'created'];
                }
            };
        }, 2);

        $results = $service->scheduleAllStores(new DateTimeImmutable('2026-06-16 01:30:00'));

        $this->assertCount(2, $results);
        $this->assertSame(['store-a', 'store-b'], $db->contexts);
        $this->assertSame('store-a', $results[0]['store_hash']);
        $this->assertSame('store-b', $results[1]['store_hash']);
    }
}
