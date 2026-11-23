<?php
namespace App\Services;

use App\Models\Database;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class OperationsMariaDbTest extends TestCase {
    private Database $db;

    protected function setUp(): void {
        $name = getenv('PROMORA_TEST_DATABASE') ?: '';
        $port = (int)getenv('PROMORA_TEST_MYSQL_PORT');
        if (!preg_match('/^promora_test_[a-z0-9_]+$/D',$name) || $port < 1024 || $port === 3306) {
            self::markTestSkipped('Requires a dedicated local MariaDB test schema and port.');
        }
        require_once dirname(__DIR__,2) . '/config.php';
        \Config::$DB_HOST = '127.0.0.1;port=' . $port;
        \Config::$DB_NAME = $name;
        \Config::$DB_USER = getenv('PROMORA_TEST_USER') ?: 'root';
        \Config::$DB_PASS = getenv('PROMORA_TEST_PASSWORD') ?: '';
        $this->db = Database::getInstance();
        $this->db->setStoreContext('test_' . bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void {
        if (isset($this->db)) {
            $this->db->query('DELETE FROM sync_jobs WHERE store_hash=?',[$this->db->getStoreContext()]);
        }
    }

    public function testManualPriceRequiresDecisionAndRestoresApprovedPriorPrice(): void {
        $store = $this->db->getStoreContext();
        $this->db->query("INSERT INTO bigcommerce_stores(store_hash,access_token,context,installed_at)
            VALUES (?,'fixture','fixture',NOW())",[$store]);
        $this->db->query("INSERT INTO promotions(store_hash,name,discount_percent,start_date,end_date,filters,status,created_at)
            VALUES (?,'Sample',20,NOW()-INTERVAL 1 HOUR,NOW()+INTERVAL 1 HOUR,'{}','active',NOW())",[$store]);
        $promotion = (int)$this->db->lastInsertId();
        $this->db->query("INSERT INTO products_cache(id,store_hash,product_id,name,price,sale_price,cached_at)
            VALUES (?,?,81,'Sample item',100,73,NOW())", [$store . '_81',$store]);
        $api = new class extends BigCommerceAPI {
            public ?float $sale = 73;
            public int $writes = 0;
            public function __construct() {}
            protected function request($method,$endpoint,$data=null) {
                if ($method !== 'GET' || $endpoint !== 'catalog/products/81') {
                    throw new \LogicException('Unexpected fixture request');
                }
                return ['status'=>200,'body'=>['data'=>['id'=>81,'sale_price'=>$this->sale]]];
            }
            public function batchUpdateProducts($updates) {
                $this->writes++;
                $this->sale = $updates[0]['sale_price'] !== null ? (float)$updates[0]['sale_price'] : null;
                return [['success'=>true,'product_id'=>81]];
            }
            public function multiRequest(array $requests): array {
                $results = [];
                foreach ($requests as $key=>$request) {
                    $results[$key] = ['status'=>200,'body'=>['data'=>$request['method'] === 'GET' ? [] : ['id'=>12]]];
                }
                return $results;
            }
        };
        \Config::$CUSTOM_FIELD_NAME = 'Promotion';
        $service = new PromotionService($this->db,$api);
        $service->syncProduct(81);
        self::assertSame(0,$api->writes);
        $conflict = $this->db->fetchOne("SELECT * FROM price_conflicts WHERE store_hash=? AND decision='open'",[$store]);
        self::assertSame('73.0000',$conflict['observed_price']);
        self::assertFalse($service->decidePriceConflict($conflict['id'],$conflict['revision']+1,'apply','tester'));
        self::assertTrue($service->decidePriceConflict($conflict['id'],$conflict['revision'],'apply','tester'));
        $result = $service->syncSinglePromotion($promotion);
        self::assertSame(0,$result['errors'],json_encode($result));
        self::assertSame(80.0,$api->sale);
        $service->syncSinglePromotion($promotion);
        self::assertSame(1,$api->writes);
        self::assertSame('73.0000',$this->db->fetchOne('SELECT previous_sale_price FROM promotion_products WHERE store_hash=?',[$store])['previous_sale_price']);
        // A stale cleanup for an active campaign cannot remove ownership.
        $service->cleanupOwnersPage($promotion,50,0);
        self::assertSame(1,$api->writes);
        $this->db->query("UPDATE promotions SET status='expired',end_date=NOW()-INTERVAL 1 SECOND WHERE store_hash=? AND id=?",[$store,$promotion]);
        $api->sale = 71.0;
        $service->cleanupOwnersPage($promotion,50,0);
        self::assertSame(71.0,$api->sale);
        $conflict = $this->db->fetchOne("SELECT * FROM price_conflicts WHERE store_hash=? AND decision='open'",[$store]);
        self::assertSame('71.0000',$conflict['observed_price']);
        self::assertTrue($service->decidePriceConflict($conflict['id'],$conflict['revision'],'apply','tester'));
        $service->cleanupOwnersPage($promotion,50,0);
        self::assertSame(73.0,$api->sale);
        self::assertFalse($this->db->fetchOne('SELECT id FROM promotion_products WHERE store_hash=?',[$store]));
    }

    public function testCorrectionResumesAfterLostResponseAndLocalCommitFailure(): void {
        $db = $this->db;
        $store = $db->getStoreContext();
        $db->query("INSERT INTO bigcommerce_stores(store_hash,access_token,context,installed_at)
            VALUES (?,'fixture','fixture',NOW())",[$store]);
        $db->query("INSERT INTO promotions(store_hash,name,discount_percent,start_date,end_date,filters,status,created_at)
            VALUES (?,'Correction fixture',20,NOW()-INTERVAL 1 HOUR,NOW()+INTERVAL 1 HOUR,'{}','active',NOW())",[$store]);
        $id = (int)$db->lastInsertId();
        $db->query("INSERT INTO products_cache(id,store_hash,product_id,name,sku,price,sale_price,cached_at)
            VALUES (?,?,71,'Fixture','TEST',100,80,NOW())",[$store . '_71',$store]);
        $db->query("INSERT INTO promotion_products(store_hash,promotion_id,product_id,product_name,original_price,promo_price,
            previous_sale_price,prior_price_known,first_applied_at,omnibus_reference_at)
            VALUES (?,?,71,'Fixture',100,80,65,1,NOW(),NOW())",[$store,$id]);
        $api = new class extends BigCommerceAPI {
            public float $sale = 80.0;
            public int $writes = 0;
            public function __construct() {}
            protected function request($method,$endpoint,$data=null) {
                if ($method !== 'GET' || $endpoint !== 'catalog/products/71') {
                    throw new \LogicException('Unexpected fixture request');
                }
                return ['status'=>200,'body'=>['data'=>['id'=>71,'sale_price'=>$this->sale]]];
            }
            public function batchUpdateProducts($updates) {
                $this->writes++;
                $this->sale = (float)$updates[0]['sale_price'];
                throw new \RuntimeException('Fixture lost response');
            }
            public function multiRequest(array $requests): array {
                return array_map(fn() => ['status'=>200,'body'=>['data'=>[]]],$requests);
            }
        };
        $service = new PromotionApplicationCorrectionService($db,new PromotionService($db,$api));
        try {
            $service->applyVoidCorrection($store,71,null,$id,'Fixture review',['actor_user_id'=>'tester'],true,'fixture');
            self::fail('Expected lost response');
        } catch (\RuntimeException $e) {
            self::assertSame('Fixture lost response',$e->getMessage());
        }
        $key = (int)$db->fetchOne("SELECT id FROM promotion_application_corrections WHERE store_hash=?",[$store])['id'];
        $trigger = 'fixture_failure_' . bin2hex(random_bytes(4));
        $db->query("CREATE TRIGGER $trigger BEFORE UPDATE ON promotion_application_corrections FOR EACH ROW
            BEGIN IF @simulate_failure=1 AND NEW.status='applied' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture finalization failure'; END IF; END");
        try {
            $db->query('SET @simulate_failure=1');
            try {
                $service->resume($store,$key);
                self::fail('Expected finalization failure');
            } catch (\Exception $e) {
                self::assertStringContainsString('fixture finalization failure',$e->getMessage());
            }
            $db->query('SET @simulate_failure=0');
            $journal = $db->fetchOne('SELECT status,after_state FROM promotion_application_corrections WHERE store_hash=? AND id=?',[$store,$key]);
            self::assertSame('pending',$journal['status']);
            self::assertSame('restored',json_decode($journal['after_state'],true)['promotion_result']['status']);
            $service->resume($store,$key);
            $service->resume($store,$key);
            self::assertSame(1,$api->writes);
            self::assertSame(65.0,$api->sale);
            self::assertSame('applied',$db->fetchOne('SELECT status FROM promotion_application_corrections WHERE store_hash=? AND id=?',[$store,$key])['status']);
        } finally {
            $db->query("DROP TRIGGER $trigger");
        }
    }

    public function testQuickRefreshAdvancesOnlyAfterCompletePagination(): void {
        $store = $this->db->getStoreContext();
        $this->db->query("INSERT INTO bigcommerce_stores(store_hash,access_token,context,installed_at,catalog_synced_until)
            VALUES (?,'fixture','fixture',NOW(),'2024-04-03 12:00:00')",[$store]);
        $api = new class extends BigCommerceAPI {
            public bool $fail = true;
            public array $filters;
            public function __construct() {}
            public function getTaxSettings(): array { return []; }
            protected function request($method,$endpoint,$data=null) {
                throw new \LogicException('Unexpected fixture request');
            }
            public function eachProductPage(array $filters,callable $callback): void {
                $this->filters = $filters;
                $callback([['id'=>11,'name'=>'Fixture','price'=>100,'variants'=>[]]]);
                if ($this->fail) { throw new \RuntimeException('Fixture interruption'); }
                $callback([['id'=>12,'name'=>'Another fixture','price'=>50,'variants'=>[]]]);
            }
        };
        $cache = new ProductCacheService($this->db,$api);
        try {
            $cache->syncModifiedProducts();
            self::fail('Expected interruption');
        } catch (\RuntimeException $e) {
            self::assertSame('Fixture interruption',$e->getMessage());
        }
        self::assertSame('2024-04-03 12:00:00',$this->db->fetchOne('SELECT catalog_synced_until FROM bigcommerce_stores WHERE store_hash=?',[$store])['catalog_synced_until']);
        $api->fail = false;
        $result = $cache->syncModifiedProducts();
        self::assertSame('2024-04-03T11:50:00+00:00',$api->filters['date_modified:min']);
        self::assertSame(2,$result['updated']);
        self::assertEquals(2,$this->db->fetchOne('SELECT COUNT(*) AS n FROM products_cache WHERE store_hash=?',[$store])['n']);
    }

    public function testGroupedHistoryPreservesCurrencyAndReferenceCutoffs(): void {
        $store = $this->db->getStoreContext();
        foreach ([['2024-01-01',120,'EUR'],['2024-02-05',99,'EUR'],['2024-02-10',80,'EUR'],['2024-02-10',10,'USD']] as [$at,$price,$currency]) {
            $this->db->query('INSERT INTO product_price_history(store_hash,product_id,price,currency,recorded_at)
                VALUES (?,15,?,?,?)',[$store,$price,$currency,$at]);
        }
        $pricing = new OmnibusPricingService($this->db);
        $args = [$store,15,null,'EUR',99,new \DateTimeImmutable('2024-02-06')];
        $before = $pricing->getDisplayData(...$args);
        $pricing->prefetchHistory($store,[15,16]);
        self::assertSame($before,$pricing->getDisplayData(...$args));
    }

    public function testClaimRecoveryFencesOldWorkerAcrossConnections(): void {
        // The fixture schema belongs exclusively to this test run.
        $store = $this->db->getStoreContext();
        $first = new QueueService($store,$this->db);
        $id = $first->createJob('sync_promotion',null,2);
        $job = $first->claim();
        self::assertEquals($id,$job['id']);
        $other = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(Database::class,'__construct'))->invoke($other);
        $second = new QueueService($store,$other);
        self::assertNull($second->claim());
        $first->checkpoint($job,['processed'=>1,'cursor'=>81]);
        $this->db->query('UPDATE sync_jobs SET lease_until=NOW()-INTERVAL 1 SECOND WHERE store_hash=? AND id=?',[$store,$id]);
        $recovered = $second->claim();
        self::assertNotSame($job['lease_token'],$recovered['lease_token']);
        self::assertSame(81,json_decode($recovered['checkpoint'],true)['cursor']);
        $first->failClaim($job,'expired worker');
        $second->checkpoint($recovered,['processed'=>2],'completed','success');
        $this->expectExceptionMessage('Job reservation expired');
        $first->checkpoint($job,['processed'=>0]);
    }

    public function testWorkerResumesDeferredWebhookBatchAndCommitsOnePartialSummary(): void {
        $db = $this->db;
        $store = $db->getStoreContext();
        $db->query("INSERT INTO bigcommerce_stores(store_hash,access_token,context,installed_at)
            VALUES (?,'fixture','fixture',NOW())",[$store]);
        // Neither fixture event can enter a catalog API path.
        $db->query("INSERT INTO webhook_events(store_hash,scope,resource_id,resource_type,payload,processed)
            VALUES (?,'test',1,'product','{}',1)",[$store]);
        $first = (int)$db->lastInsertId();
        $db->query("INSERT INTO webhook_events(store_hash,scope,resource_id,resource_type,payload)
            VALUES (?,'test',2,'product','{}')",[$store]);
        $second = (int)$db->lastInsertId();
        $db->query("INSERT INTO sync_jobs(store_hash,job_type,status,total_items,payload,attempts,created_at)
            VALUES (?,'webhook_event','pending',2,?,2,NOW())",[$store,json_encode(['webhook_event_ids'=>[$first,$second]])]);
        $job = (int)$db->lastInsertId();
        $worker = <<<'PHP'
require 'config.php';
$n = getenv('PROMORA_TEST_DATABASE');
$port = (int)getenv('PROMORA_TEST_MYSQL_PORT');
if (!preg_match('/^promora_test_[a-z0-9_]+$/D',$n) || $port < 1024 || $port === 3306) exit(2);
Config::$DB_HOST='127.0.0.1;port='.$port;
Config::$DB_NAME=$n;
Config::$DB_USER=getenv('PROMORA_TEST_USER') ?: 'root';
Config::$DB_PASS=getenv('PROMORA_TEST_PASSWORD') ?: '';
$_ENV['PROMORA_WORKER_STORES']=$argv[1];
$_ENV['PROMORA_LOG_DIR']=$argv[2];
putenv('WEBHOOK_EVENT_BATCH_LIMIT=1');
require 'bin/worker.php';
PHP;
        $dir = sys_get_temp_dir() . '/promora-worker-' . bin2hex(random_bytes(6));
        try {
            for ($i=0;$i<2;$i++) {
                $proc = proc_open([PHP_BINARY,'-r',$worker,$store,$dir],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2));
                $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0,proc_close($proc),$output);
                if ($i === 0) {
                    self::assertSame('pending',$db->fetchOne('SELECT status FROM sync_jobs WHERE store_hash=? AND id=?',[$store,$job])['status']);
                    self::assertEquals(0,$db->fetchOne('SELECT COUNT(*) AS n FROM sync_log WHERE store_hash=?',[$store])['n']);
                    $db->query('UPDATE sync_jobs SET next_run_at=NOW() WHERE store_hash=? AND id=?',[$store,$job]);
                }
            }
            $row = $db->fetchOne('SELECT outcome,processed_items FROM sync_jobs WHERE store_hash=? AND id=?',[$store,$job]);
            self::assertSame('partial',$row['outcome']);
            self::assertEquals(2,$row['processed_items']);
            self::assertNotNull($db->fetchOne('SELECT terminal_at FROM webhook_events WHERE store_hash=? AND id=?',[$store,$second])['terminal_at']);
            self::assertEquals(1,$db->fetchOne('SELECT COUNT(*) AS n FROM sync_log WHERE store_hash=? AND errors=1',[$store])['n']);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
            if (is_dir($dir)) { rmdir($dir); }
        }
    }

    public function testRetentionSupportsLegacyTimeColumnAndIsolatesStores(): void {
        $db = $this->db;
        $db->query('CREATE TEMPORARY TABLE webhook_events(id INT PRIMARY KEY,store_hash VARCHAR(20),received_at DATETIME,
            terminal_at DATETIME,processed TINYINT)');
        $db->query("INSERT INTO webhook_events VALUES (1,'a',NOW()-INTERVAL 100 DAY,NULL,1),
            (2,'b',NOW()-INTERVAL 100 DAY,NULL,1),(3,'a',NOW()-INTERVAL 100 DAY,NULL,0),
            (4,'a',NOW()-INTERVAL 100 DAY,NOW()-INTERVAL 95 DAY,0)");
        $maintenance = new OperationalRetention($db);
        $before = $maintenance->run('a',false);
        self::assertEquals(1,$before['processed_webhooks']['remaining']);
        self::assertSame(0,$before['processed_webhooks']['deleted']);
        $after = $maintenance->run('a',true);
        self::assertSame(1,$after['failed_webhooks']['deleted']);
        self::assertSame([2,3],array_column($db->fetchAll('SELECT id FROM webhook_events ORDER BY id'),'id'));
    }
}
