<?php
use App\Models\Database;
use App\Services\BigCommerceAPI;
use App\Services\CustomFieldService;
use App\Services\ProductCacheService;
use App\Services\PromotionService;
use App\Services\QueueService;
use PHPUnit\Framework\TestCase;

// Exercise the real scoped queries against isolated tables. Translate only MySQL-specific syntax.
class VariantRecoveryDatabase extends Database {
    private PDO $connection;

    public function __construct() {
        $this->connection = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->connection->sqliteCreateFunction('NOW', static fn() => '2026-11-23 12:00:00');
        $this->connection->sqliteCreateFunction('GET_LOCK', static fn($name, $timeout) => 1);
        $this->connection->sqliteCreateFunction('RELEASE_LOCK', static fn($name) => 1);
        $this->connection->exec("
            CREATE TABLE products_cache (id TEXT, store_hash TEXT, product_id INT, variant_id INT,
                type TEXT, name TEXT, sku TEXT, price REAL, sale_price REAL);
            CREATE TABLE promotion_products (id INTEGER PRIMARY KEY, store_hash TEXT, promotion_id INT, product_id INT, variant_id INT);
            CREATE TABLE promotion_product_history (id INTEGER PRIMARY KEY, store_hash TEXT, promotion_id INT,
                product_id INT, variant_id INT, applied_at TEXT, removed_at TEXT, removal_reason TEXT, last_seen_at TEXT, updated_at TEXT);
            CREATE TABLE promotion_archives (id INTEGER PRIMARY KEY, store_hash TEXT, promotion_id INT, cleanup_completed_at TEXT, updated_at TEXT);
            CREATE TABLE sync_jobs (id INTEGER PRIMARY KEY, store_hash TEXT, job_type TEXT, payload TEXT, total_items INT, status TEXT, attempts INT, created_at TEXT);
            INSERT INTO products_cache VALUES
                ('gone','store',9626,17409,'variant','Old','C063554',77.19,38.60),
                ('valid','store',5327,17439,'variant','Current','C063554',77.19,38.60),
                ('other','tenant',9626,17409,'variant','Other','C063554',77.19,38.60);
            INSERT INTO promotion_products VALUES (1,'store',458,9626,17409),(2,'store',458,5327,17439),(3,'tenant',458,9626,17409);
            INSERT INTO promotion_product_history (id,store_hash,promotion_id,product_id,variant_id,applied_at)
                VALUES (1,'store',458,9626,17409,'2026-11-23'),(2,'tenant',458,9626,17409,'2026-11-23');
        ");
    }

    public function query($sql, $params = []) {
        if (strpos($sql, 'SHOW COLUMNS FROM sync_jobs') === 0) {
            return $this->connection->query("SELECT 'payload' AS Field");
        }
        if (strpos(ltrim($sql), 'CREATE TABLE IF NOT EXISTS') === 0) {
            return null;
        }
        $sql = str_replace([' FOR UPDATE', '<=>', 'GREATEST('], ['', ' IS ', 'MAX('], $sql);
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
    public function beginTransaction() { return $this->connection->beginTransaction(); }
    public function commit() { return $this->connection->commit(); }
    public function rollback() { return $this->connection->rollBack(); }
}

class VariantRecoveryTest extends TestCase {
    private VariantRecoveryDatabase $db;

    protected function setUp(): void {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Uses isolated in-memory SQLite tables.');
        }
        $this->db = new VariantRecoveryDatabase();
    }

    private function service(string $class, array $properties) {
        $reflection = new ReflectionClass($class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach ($properties + ['db' => $this->db, 'storeHash' => 'store'] as $name => $value) {
            $reflection->getProperty($name)->setValue($service, $value);
        }
        return $service;
    }

    public function testVerificationClosesOnlyTheMissingScopedItemAndIsIdempotent(): void {
        $api = $this->createMock(BigCommerceAPI::class);
        $api->expects(self::once())->method('getCompleteVariantIds')->with(9626)->willReturn([13676, 13677]);
        $cache = $this->service(ProductCacheService::class, ['api' => $api]);
        self::assertSame(1, $cache->verifyProductVariants(9626));
        self::assertSame(0, $cache->verifyProductVariants(9626));
        self::assertSame(['other', 'valid'], array_column($this->db->fetchAll('SELECT id FROM products_cache ORDER BY id'), 'id'));
        self::assertSame([2, 3], array_column($this->db->fetchAll('SELECT id FROM promotion_products ORDER BY id'), 'id'));
        $history = $this->db->fetchAll('SELECT removal_reason FROM promotion_product_history ORDER BY id');
        self::assertSame(['variant_removed_from_catalog', null], array_column($history, 'removal_reason'));
        self::assertSame(['cleanup_promotion_field'], array_column($this->db->fetchAll('SELECT job_type FROM sync_jobs'), 'job_type'));
    }

    public function testReadFailureOrArchiveFailurePreservesOwnershipAndCache(): void {
        $api = $this->createMock(BigCommerceAPI::class);
        $api->method('getCompleteVariantIds')->willReturnOnConsecutiveCalls(
            self::throwException(new RuntimeException('last page unavailable')), []
        );
        $cache = $this->service(ProductCacheService::class, ['api' => $api]);
        $this->db->query("CREATE TRIGGER fail_delete BEFORE DELETE ON products_cache BEGIN SELECT RAISE(ABORT,'write failed'); END");
        foreach (['last page unavailable', 'write failed'] as $message) {
            try {
                $cache->verifyProductVariants(9626);
                self::fail('Expected the verification job to be retried');
            } catch (RuntimeException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
            self::assertEquals(3, $this->db->fetchOne('SELECT COUNT(*) AS n FROM promotion_products')['n']);
            self::assertNull($this->db->fetchOne('SELECT removed_at FROM promotion_product_history WHERE id=1')['removed_at']);
            self::assertEquals(0, $this->db->fetchOne('SELECT COUNT(*) AS n FROM sync_jobs')['n']);
        }
    }

    public function testCacheRefreshQueuesOnlyParentsWithMissingVariants(): void {
        $queue = $this->createMock(QueueService::class);
        $queue->expects(self::once())->method('queueVariantVerification')->with(9626);
        $cache = $this->service(ProductCacheService::class, ['verificationQueue' => $queue]);
        $check = new ReflectionMethod($cache, 'queueMissingVariantChecks');
        $check->invoke($cache, [['id' => 9626]]);
        $check->invoke($cache, [
            ['id' => 9626, 'variants' => []],
            ['id' => 5327, 'variants' => [['id' => 17439]]],
        ]);
    }

    public function testPendingChecksAreDeduplicatedPerStoreAndProduct(): void {
        $queue = $this->service(QueueService::class, []);
        $queue->queueVariantVerification(9626);
        $queue->queueVariantVerification(9626);
        $queue->queueVariantVerification(5327);
        $this->service(QueueService::class, ['storeHash' => 'tenant'])->queueVariantVerification(9626);
        $rows = $this->db->fetchAll('SELECT store_hash, payload FROM sync_jobs ORDER BY id');
        self::assertSame(['store', 'store', 'tenant'], array_column($rows, 'store_hash'));
        self::assertSame(['{"product_ids":[9626]}', '{"product_ids":[5327]}', '{"product_ids":[9626]}'], array_column($rows, 'payload'));
    }

    public function testPromotionSyncAppliesThreePricesAndQueuesOneCheckForTheRejectedVariant(): void {
        $this->db->query("DELETE FROM promotion_products WHERE store_hash = 'store'");
        foreach (['custom_field_id INT', 'first_applied_at TEXT', 'synced_at TEXT', 'omnibus_reference_at TEXT'] as $column) {
            $this->db->query("ALTER TABLE promotion_products ADD COLUMN {$column}");
        }
        $this->db->query('CREATE TABLE promotion_product_exclusions (store_hash TEXT, promotion_id INT, product_id INT, variant_id INT)');
        $items = [];
        foreach ([13664 => 103.59, 13665 => 154.99, 13666 => 198.85, 17409 => 77.19] as $id => $price) {
            $items[] = ['id' => "variant_{$id}", 'variant_id' => $id, 'product_id' => $id === 17409 ? 9626 : 9621,
                'sku' => "sku{$id}", 'name' => 'Perfume', 'price' => $price];
        }
        $promotion = ['id' => 458, 'name' => 'BF', 'priority' => 0, 'discount_percent' => 50,
            'filters' => json_encode(['sku:in' => array_column($items, 'sku')]), 'start_date' => '2026-11-23'];
        $model = $this->createMock(\App\Models\Promotion::class);
        $model->method('findById')->willReturn($promotion);
        $model->method('findActive')->willReturn([$promotion]);
        $api = $this->getMockBuilder(BigCommerceAPI::class)->disableOriginalConstructor()->onlyMethods(['request'])->getMock();
        $api->expects(self::exactly(2))->method('request')->willReturnCallback(static function ($method, $endpoint, $data) {
            self::assertSame('PUT', $method);
            self::assertSame('catalog/variants', $endpoint);
            if (count($data) === 4) {
                throw new RuntimeException('BigCommerce API Error (Status: 422, Endpoint: catalog/variants):  Body: {"errors":{"3.id":"No variants with id 17409"}}');
            }
            self::assertSame([13664 => 51.80, 13665 => 77.50, 13666 => 99.43], array_column($data, 'sale_price', 'id'));
            return ['body' => ['data' => $data]];
        });
        $queue = $this->createMock(QueueService::class);
        $queue->expects(self::once())->method('queueVariantVerification')->with(9626);
        $cache = $this->createMock(ProductCacheService::class);
        $cache->method('getProductsByFilters')->willReturn($items);
        $cache->expects(self::once())->method('updatePriceCacheDirectly')->with(self::callback(
            static fn($rows) => array_column($rows, 'sale_price', 'variant_id') === [13664 => 51.80, 13665 => 77.50, 13666 => 99.43]
        ));
        $fields = $this->createMock(CustomFieldService::class);
        $fields->method('upsertCustomFields')->willReturn([]);
        $service = $this->service(PromotionService::class, ['promotionModel' => $model, 'api' => $api,
            'cacheService' => $cache, 'customFieldService' => $fields, 'verificationQueue' => $queue,
            'storeConfigCache' => ['enable_omnibus' => 0]]);
        $result = $service->syncSinglePromotionBatch(458, 50, 150);
        self::assertSame(3, $result['processed']);
        self::assertSame(1, $result['errors']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([9621], $result['omnibus_product_ids']);
        self::assertSame('sku17409', $result['diagnostics'][0]['sku']);
        $saved = $this->db->fetchAll("SELECT variant_id FROM promotion_products WHERE store_hash = 'store' AND promotion_id=458 ORDER BY variant_id");
        self::assertSame([13664, 13665, 13666], array_column($saved, 'variant_id'));
    }

    public function testCursorContinuesAfterDeletedPreviousPageAndReportsRuleSkips(): void {
        $this->db->query('ALTER TABLE products_cache ADD COLUMN variant_sort_id INT GENERATED ALWAYS AS (coalesce(variant_id,0))');
        $cache = $this->getMockBuilder(ProductCacheService::class)->disableOriginalConstructor()
            ->onlyMethods(['logResponse'])->getMock();
        $reflection = new ReflectionClass(ProductCacheService::class);
        foreach (['db' => $this->db, 'storeHash' => 'store', 'productsCacheHasVariantSortId' => true] as $name => $value) {
            $reflection->getProperty($name)->setValue($cache, $value);
        }
        $model = $this->createMock(\App\Models\Promotion::class);
        $model->method('findById')->willReturn(['id' => 458, 'filters' => '{}']);
        $model->method('findActive')->willReturn([]);
        $service = $this->service(PromotionService::class, ['promotionModel' => $model, 'cacheService' => $cache]);
        $first = $cache->getProductsByFilters([], 1)[0];
        self::assertEquals(17439, $first['variant_id']);
        $cursor = ['product_id' => $first['product_id'], 'variant_sort_id' => $first['variant_id'], 'id' => $first['id']];
        $this->db->query("DELETE FROM products_cache WHERE id = 'valid'");
        $next = $service->syncSinglePromotionBatch(458, 1, 0, $cursor);
        self::assertSame(17409, $next['next_cursor']['variant_sort_id']);
        self::assertSame(1, $next['visited']);
        self::assertSame(1, $next['skipped']);
        self::assertSame(0, $next['errors']);
        $end = $service->syncSinglePromotionBatch(458, 1, 0, $next['next_cursor']);
        self::assertNull($end['next_cursor']);
        self::assertSame(0, $end['visited']);
    }

    public function testPartialCleanupKeepsSiblingOwnershipAndSharedField(): void {
        $this->db->query("INSERT INTO promotion_products VALUES (4,'store',458,9626,13677)");
        $api = $this->createMock(BigCommerceAPI::class);
        $api->method('batchUpdateVariants')->willReturn([
            ['success' => false, 'product_id' => 9626, 'variant_id' => 17409, 'reason' => 'variant_not_found', 'error' => 'No variants with id 17409'],
            ['success' => true, 'product_id' => 9626, 'variant_id' => 13677],
        ]);
        $queue = $this->createMock(QueueService::class);
        $queue->expects(self::once())->method('queueVariantVerification')->with(9626);
        $cache = $this->createMock(ProductCacheService::class);
        $cache->expects(self::once())->method('updatePriceCacheDirectly')->with(self::callback(
            static fn($rows) => array_values($rows) === [['product_id' => 9626, 'variant_id' => 13677, 'sale_price' => null]]
        ));
        $fields = $this->createMock(CustomFieldService::class);
        $fields->expects(self::never())->method('batchRemovePromotionFields');
        $service = $this->service(PromotionService::class, [
            'api' => $api, 'verificationQueue' => $queue, 'customFieldService' => $fields, 'cacheService' => $cache,
        ]);
        $items = $this->db->fetchAll("SELECT * FROM promotion_products WHERE store_hash='store' AND product_id=9626");
        $result = (new ReflectionMethod($service, 'cleanupPromotionProductItems'))->invoke($service, $items);
        self::assertSame(1, $result['processed']);
        self::assertSame(1, $result['errors']);
        self::assertSame('variant_not_found', $result['diagnostics'][0]['type']);
        self::assertSame([9626], $result['omnibus_product_ids']);
        self::assertSame([1, 2, 3], array_column($this->db->fetchAll('SELECT id FROM promotion_products ORDER BY id'), 'id'));
    }
}
