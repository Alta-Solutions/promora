<?php

use PHPUnit\Framework\TestCase;
use App\Services\QueueService;

class QueueServiceTest extends TestCase {
    public function testCreateOmnibusSyncJobUsesOnlyCurrentStoreHash(): void {
        $db = new class {
            public $queries = [];
            public $fetches = [];

            public function fetchOne($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));
                $this->fetches[] = [
                    'sql' => $normalizedSql,
                    'params' => $params,
                ];

                if (strpos($normalizedSql, 'GET_LOCK') !== false) {
                    return ['acquired' => 1];
                }

                if (strpos($normalizedSql, 'RELEASE_LOCK') !== false) {
                    return ['released' => 1];
                }

                return false;
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }

            public function lastInsertId() {
                return 77;
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $result = $service->createOmnibusSyncJob(12);

        $this->assertTrue($result['created']);
        $this->assertSame(77, $result['job_id']);
        $this->assertSame(['omnibus_sync:store-a', 5], $db->fetches[0]['params']);
        $this->assertSame(['store-a', 'omnibus_sync'], $db->fetches[1]['params']);
        $this->assertSame(['store-a', 'omnibus_sync', null, 12], $db->queries[0]['params']);
    }

    public function testCreateOmnibusSyncJobRequiresStoreHash(): void {
        $service = $this->createQueueService(new class {}, null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Store context required to create Omnibus sync job.');

        $service->createOmnibusSyncJob(1);
    }

    public function testCreateTargetedOmnibusSyncJobCreatesPayloadJob(): void {
        $db = new class {
            public $queries = [];
            public $fetches = [];

            public function fetchOne($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));
                $this->fetches[] = [
                    'sql' => $normalizedSql,
                    'params' => $params,
                ];

                if (strpos($normalizedSql, 'GET_LOCK') !== false) {
                    return ['acquired' => 1];
                }

                if (strpos($normalizedSql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'payload'];
                }

                if (strpos($normalizedSql, 'RELEASE_LOCK') !== false) {
                    return ['released' => 1];
                }

                return false;
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }

            public function lastInsertId() {
                return 88;
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $result = $service->createTargetedOmnibusSyncJob([3, '2', 2, 0, 'bad'], [
            'source' => 'promotion_sync',
            'promotion_id' => 113,
            'source_job_id' => 9775,
            'day_start' => '2026-06-16 00:00:00',
            'cache_dirty_count' => 2,
            'history_dirty_count' => 1,
            'ignored_history_dirty_count' => 0,
            'total_dirty_count' => 2,
        ]);

        $this->assertTrue($result['created']);
        $this->assertSame(88, $result['job_id']);
        $this->assertSame([2, 3], $result['product_ids']);
        $this->assertStringContainsString('INSERT INTO sync_jobs', $db->queries[0]['sql']);
        $this->assertSame('store-a', $db->queries[0]['params'][0]);
        $this->assertSame(113, $db->queries[0]['params'][1]);
        $this->assertSame(2, $db->queries[0]['params'][3]);

        $payload = json_decode($db->queries[0]['params'][2], true);
        $this->assertSame([2, 3], $payload['product_ids']);
        $this->assertSame('promotion_sync', $payload['source']);
        $this->assertSame(113, $payload['promotion_id']);
        $this->assertSame(9775, $payload['source_job_id']);
        $this->assertSame('2026-06-16 00:00:00', $payload['day_start']);
        $this->assertSame(2, $payload['cache_dirty_count']);
        $this->assertSame(1, $payload['history_dirty_count']);
        $this->assertSame(0, $payload['ignored_history_dirty_count']);
        $this->assertSame(2, $payload['total_dirty_count']);
    }

    public function testCreateTargetedOmnibusSyncJobMergesPendingJob(): void {
        $db = new class {
            public $queries = [];

            public function fetchOne($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

                if (strpos($normalizedSql, 'GET_LOCK') !== false) {
                    return ['acquired' => 1];
                }

                if (strpos($normalizedSql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'payload'];
                }

                if (strpos($normalizedSql, 'RELEASE_LOCK') !== false) {
                    return ['released' => 1];
                }

                if (($params[1] ?? null) === 'omnibus_sync_products' && ($params[2] ?? null) === 'pending') {
                    return [
                        'id' => 55,
                        'payload' => json_encode(['product_ids' => [1, 3]]),
                    ];
                }

                return false;
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $result = $service->createTargetedOmnibusSyncJob([2, 3], ['source' => 'cleanup']);

        $this->assertFalse($result['created']);
        $this->assertSame(55, $result['job_id']);
        $this->assertSame('merged', $result['reason']);
        $this->assertSame([1, 2, 3], $result['product_ids']);
        $this->assertStringContainsString('UPDATE sync_jobs', $db->queries[0]['sql']);

        $payload = json_decode($db->queries[0]['params'][0], true);
        $this->assertSame([1, 2, 3], $payload['product_ids']);
        $this->assertSame('cleanup', $payload['source']);
        $this->assertSame(55, $payload['merged_from_job_id']);
        $this->assertSame(3, $db->queries[0]['params'][1]);
    }

    public function testCreateTargetedOmnibusSyncJobCreatesNewJobWhenOnlyProcessingTargetedExists(): void {
        $db = new class {
            public $queries = [];

            public function fetchOne($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

                if (strpos($normalizedSql, 'GET_LOCK') !== false) {
                    return ['acquired' => 1];
                }

                if (strpos($normalizedSql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'payload'];
                }

                if (strpos($normalizedSql, 'RELEASE_LOCK') !== false) {
                    return ['released' => 1];
                }

                if (($params[1] ?? null) === 'omnibus_sync_products' && ($params[2] ?? null) === 'processing') {
                    return ['id' => 99];
                }

                return false;
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }

            public function lastInsertId() {
                return 101;
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $result = $service->createTargetedOmnibusSyncJob([9]);

        $this->assertTrue($result['created']);
        $this->assertSame(101, $result['job_id']);
        $this->assertStringContainsString('INSERT INTO sync_jobs', $db->queries[0]['sql']);
    }

    public function testCreateTargetedOmnibusSyncJobSkipsWhenPendingFullOmnibusExists(): void {
        $db = new class {
            public $queries = [];

            public function fetchOne($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

                if (strpos($normalizedSql, 'GET_LOCK') !== false) {
                    return ['acquired' => 1];
                }

                if (strpos($normalizedSql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'payload'];
                }

                if (strpos($normalizedSql, 'RELEASE_LOCK') !== false) {
                    return ['released' => 1];
                }

                if (($params[1] ?? null) === 'omnibus_sync') {
                    return ['id' => 44, 'status' => 'pending'];
                }

                return false;
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $result = $service->createTargetedOmnibusSyncJob([1, 2]);

        $this->assertFalse($result['created']);
        $this->assertSame(44, $result['job_id']);
        $this->assertSame('covered_by_full_sync', $result['reason']);
        $this->assertSame([], $db->queries);
    }

    public function testCreateTargetedOmnibusSyncJobSkipsWhenProcessingFullOmnibusExists(): void {
        $db = new class {
            public $queries = [];

            public function fetchOne($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

                if (strpos($normalizedSql, 'GET_LOCK') !== false) {
                    return ['acquired' => 1];
                }

                if (strpos($normalizedSql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'payload'];
                }

                if (strpos($normalizedSql, 'RELEASE_LOCK') !== false) {
                    return ['released' => 1];
                }

                if (($params[1] ?? null) === 'omnibus_sync') {
                    return ['id' => 45, 'status' => 'processing'];
                }

                return false;
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $result = $service->createTargetedOmnibusSyncJob([1, 2]);

        $this->assertFalse($result['created']);
        $this->assertSame(45, $result['job_id']);
        $this->assertSame('covered_by_full_sync', $result['reason']);
        $this->assertSame([], $db->queries);
    }

    public function testExtractProductIdsFromPayloadNormalizesIds(): void {
        $service = $this->createQueueService(new class {}, 'store-a');

        $this->assertSame(
            [2, 7],
            $service->extractProductIdsFromPayload('{"product_ids":["7",2,2,0,"bad"]}')
        );
    }

    public function testCreateWebhookEventJobCreatesPayloadJob(): void {
        $db = new class {
            public $queries = [];

            public function fetchOne($sql, $params = []) {
                if (strpos($sql, 'GET_LOCK') !== false) {
                    return ['acquired' => 1];
                }

                if (strpos($sql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'payload'];
                }

                if (strpos($sql, 'RELEASE_LOCK') !== false) {
                    return ['released' => 1];
                }

                return false;
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }

            public function lastInsertId() {
                return 1234;
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $result = $service->createWebhookEventJob(456);

        $this->assertTrue($result['created']);
        $this->assertSame(1234, $result['job_id']);
        $this->assertSame(456, $result['event_id']);
        $this->assertSame([456], $result['event_ids']);
        $this->assertStringContainsString('webhook_event', $db->queries[0]['sql']);
        $this->assertSame('store-a', $db->queries[0]['params'][0]);

        $payload = json_decode($db->queries[0]['params'][1], true);
        $this->assertSame(456, $payload['webhook_event_id']);
        $this->assertSame([456], $payload['webhook_event_ids']);
    }

    public function testCreateWebhookEventJobMergesPendingPayloadJob(): void {
        $db = new class {
            public $queries = [];

            public function fetchOne($sql, $params = []) {
                if (strpos($sql, 'GET_LOCK') !== false) {
                    return ['acquired' => 1];
                }

                if (strpos($sql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'payload'];
                }

                if (strpos($sql, 'RELEASE_LOCK') !== false) {
                    return ['released' => 1];
                }

                if (($params[1] ?? null) === 'webhook_event' && ($params[2] ?? null) === 'pending') {
                    return [
                        'id' => 77,
                        'payload' => json_encode([
                            'webhook_event_id' => 100,
                            'webhook_event_ids' => [100, 101],
                        ]),
                    ];
                }

                return false;
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $result = $service->createWebhookEventJob(102);

        $this->assertFalse($result['created']);
        $this->assertSame(77, $result['job_id']);
        $this->assertSame('merged', $result['reason']);
        $this->assertSame([100, 101, 102], $result['event_ids']);
        $this->assertStringContainsString('UPDATE sync_jobs', $db->queries[0]['sql']);

        $payload = json_decode($db->queries[0]['params'][0], true);
        $this->assertSame(100, $payload['webhook_event_id']);
        $this->assertSame([100, 101, 102], $payload['webhook_event_ids']);
        $this->assertSame(3, $db->queries[0]['params'][1]);
    }

    public function testExtractWebhookEventIdFromPayloadNormalizesId(): void {
        $service = $this->createQueueService(new class {}, 'store-a');

        $this->assertSame(456, $service->extractWebhookEventIdFromPayload('{"webhook_event_id":"456"}'));
        $this->assertNull($service->extractWebhookEventIdFromPayload('{"webhook_event_id":"bad"}'));
        $this->assertSame(
            [456, 789],
            $service->extractWebhookEventIdsFromPayload('{"webhook_event_id":"456","webhook_event_ids":["789",456,"bad",0]}')
        );
    }

    public function testDeferWebhookEventJobRequeuesRemainingEvents(): void {
        $db = new class {
            public $queries = [];

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $service->deferWebhookEventJob(77, 'store-a', [102, '101', 101, 0, 'bad']);

        $this->assertStringContainsString('UPDATE sync_jobs', $db->queries[0]['sql']);
        $this->assertStringContainsString('next_run_at = NULL', $db->queries[0]['sql']);

        $payload = json_decode($db->queries[0]['params'][0], true);
        $this->assertSame(101, $payload['webhook_event_id']);
        $this->assertSame([101, 102], $payload['webhook_event_ids']);
        $this->assertSame(2, $db->queries[0]['params'][1]);
        $this->assertSame(77, $db->queries[0]['params'][2]);
        $this->assertSame('store-a', $db->queries[0]['params'][3]);
    }

    public function testNextPendingJobPrioritizesPromotionWorkBeforeOmnibusWork(): void {
        $db = new class {
            public $sql = null;

            public function fetchOne($sql, $params = []) {
                $this->sql = preg_replace('/\s+/', ' ', trim($sql));
                return false;
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $service->getNextPendingJob();

        $this->assertStringContainsString("WHEN 'sync_promotion' THEN 10", $db->sql);
        $this->assertStringContainsString("WHEN 'single_sync' THEN 10", $db->sql);
        $this->assertStringContainsString("WHEN 'cleanup_single' THEN 20", $db->sql);
        $this->assertStringContainsString("WHEN 'omnibus_sync_products' THEN 25", $db->sql);
        $this->assertStringContainsString("WHEN 'webhook_event' THEN 30", $db->sql);
        $this->assertStringContainsString("WHEN 'omnibus_sync' THEN 40", $db->sql);
        $this->assertStringContainsString('created_at ASC, id ASC', $db->sql);
    }

    public function testActiveJobDisplayUsesSameJobTypePriority(): void {
        $db = new class {
            public $sql = null;
            public $params = null;

            public function fetchOne($sql, $params = []) {
                $this->sql = preg_replace('/\s+/', ' ', trim($sql));
                $this->params = $params;
                return false;
            }
        };

        $service = $this->createQueueService($db, 'store-a');
        $service->getActiveJob();

        $this->assertSame(['store-a'], $db->params);
        $this->assertStringContainsString("WHEN 'sync_promotion' THEN 10", $db->sql);
        $this->assertStringContainsString("WHEN 'single_sync' THEN 10", $db->sql);
        $this->assertStringContainsString("WHEN 'omnibus_sync_products' THEN 25", $db->sql);
        $this->assertStringContainsString("WHEN 'webhook_event' THEN 30", $db->sql);
        $this->assertStringContainsString("WHEN 'omnibus_sync' THEN 40", $db->sql);
    }

    private function createQueueService($db, ?string $storeHash): QueueService {
        $reflection = new ReflectionClass(QueueService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $dbProperty = $reflection->getProperty('db');
        $dbProperty->setAccessible(true);
        $dbProperty->setValue($service, $db);

        $storeHashProperty = $reflection->getProperty('storeHash');
        $storeHashProperty->setAccessible(true);
        $storeHashProperty->setValue($service, $storeHash);

        return $service;
    }
}
