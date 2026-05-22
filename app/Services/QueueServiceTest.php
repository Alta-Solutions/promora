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
