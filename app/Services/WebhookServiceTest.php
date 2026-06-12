<?php

use PHPUnit\Framework\TestCase;
use App\Services\WebhookService;
use App\Models\Database;
use App\Services\BigCommerceAPI;
use App\Services\ProductCacheService;
use App\Services\QueueService;
use App\Services\WebhookSuppressionService;

// Definišemo konstantu da znamo da smo u test modu (za izbegavanje exit-a u servisu)
if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}

// Mock Config klase ako je potrebno
if (!class_exists('Config')) {
    class Config {
        public static $SECRET_CRON_KEY = 'test_secret_key';
        public static $APP_URL = 'http://localhost';
    }
}

class WebhookServiceTest extends TestCase {
    
    private $dbMock;
    private $apiMock;
    
    protected function setUp(): void {
        // Kreiramo mock objekte za zavisnosti
        \Config::$SECRET_CRON_KEY = 'test_secret_key';

        $this->dbMock = $this->createMock(Database::class);
        $this->apiMock = $this->createMock(BigCommerceAPI::class);
    }

    public function testProcessWebhookRejectsInvalidAuth() {
        $service = new WebhookService($this->dbMock, $this->apiMock);
        
        // Pozivamo sa pogrešnim ključem
        $headers = ['HTTP_X_CUSTOM_AUTH' => 'invalid_secret_key'];
        $payload = ['scope' => 'test'];
        
        $result = $service->processWebhook($payload, $headers);
        
        $this->assertFalse($result, 'Webhook should return false for invalid auth header');
    }

    public function testProcessWebhookValidatesPayload() {
        $service = new WebhookService($this->dbMock, $this->apiMock);
        
        $headers = ['HTTP_X_CUSTOM_AUTH' => 'test_secret_key'];
        // Payload bez store_id i product_id
        $payload = ['scope' => 'store/product/updated'];
        
        $result = $service->processWebhook($payload, $headers);
        
        $this->assertFalse($result, 'Webhook should return false for missing payload data');
    }

    public function testAcceptWebhookQueuesEventAndReturnsAcceptedStatus() {
        $queueMock = $this->getMockBuilder(QueueService::class)
                          ->disableOriginalConstructor()
                          ->onlyMethods(['createWebhookEventJob'])
                          ->getMock();
        $queueMock->expects($this->once())
                  ->method('createWebhookEventJob')
                  ->with(1)
                  ->willReturn(['created' => true, 'job_id' => 10, 'event_id' => 1]);

        $service = $this->getMockBuilder(WebhookService::class)
                        ->setConstructorArgs([$this->dbMock, $this->apiMock])
                        ->onlyMethods(['createQueueService'])
                        ->getMock();
        $service->method('createQueueService')->with('test_hash')->willReturn($queueMock);

        $this->dbMock->method('fetchOne')
                     ->willReturn(['access_token' => 'fake_token']);
        $this->dbMock->expects($this->once())
                     ->method('setStoreContext')
                     ->with('test_hash');
        $this->dbMock->method('lastInsertId')->willReturn(1);

        $result = $service->acceptWebhook([
            'scope' => 'store/product/updated',
            'store_id' => 'test_hash',
            'data' => ['id' => 123],
        ], ['HTTP_X_CUSTOM_AUTH' => 'test_secret_key']);

        $this->assertTrue($result);
        $this->assertSame(202, $service->getLastStatusCode());
    }

    public function testProcessWebhookHandlesProductUpdate() {
        // Koristimo Partial Mock za WebhookService da bismo presreli protected metode
        // Ovako testiramo samo logiku rutiranja, a ne samu sinhronizaciju (updateProductCache)
        $service = $this->getMockBuilder(WebhookService::class)
                        ->setConstructorArgs([$this->dbMock, $this->apiMock])
                        ->onlyMethods(['updateProductCache', 'createBigCommerceAPI', 'isSuppressedProductUpdate']) // Metode koje mokujemo
                        ->getMock();

        // Očekujemo da će updateProductCache biti pozvan jednom sa ID-jem 123
        $service->expects($this->once())
                ->method('updateProductCache')
                ->with(123);
        $service->method('createBigCommerceAPI')->willReturn($this->apiMock);
        $service->method('isSuppressedProductUpdate')->willReturn(false);

        // Podešavanje ponašanja DB Mock-a
        // 1. fetchOne za access token
        $this->dbMock->expects($this->once())
                     ->method('fetchOne')
                     ->willReturn(['access_token' => 'fake_token']);
        
        // 2. setStoreContext (ako postoji)
        // 3. query za insert u webhook_events
        // 4. lastInsertId
        $this->dbMock->method('lastInsertId')->willReturn(1);
        // 5. query za update statusa (processed = TRUE)
        
        // Payload
        $headers = ['HTTP_X_CUSTOM_AUTH' => 'test_secret_key'];
        $payload = [
            'scope' => 'store/product/updated',
            'store_id' => 'test_hash',
            'data' => ['id' => 123]
        ];

        $result = $service->processWebhook($payload, $headers);
        
        $this->assertTrue($result, 'Webhook should return true for successful processing');
    }

    public function testProcessQueuedWebhookEventProcessesStoredPayload() {
        $payload = [
            'scope' => 'store/product/updated',
            'store_id' => 'test_hash',
            'data' => ['id' => 123],
        ];

        $service = $this->getMockBuilder(WebhookService::class)
                        ->setConstructorArgs([$this->dbMock, $this->apiMock])
                        ->onlyMethods(['updateProductCache', 'createBigCommerceAPI', 'isSuppressedProductUpdate'])
                        ->getMock();

        $service->expects($this->once())
                ->method('updateProductCache')
                ->with(123);
        $service->method('createBigCommerceAPI')->willReturn($this->apiMock);
        $service->method('isSuppressedProductUpdate')->willReturn(false);

        $this->dbMock->expects($this->exactly(2))
                     ->method('fetchOne')
                     ->willReturnOnConsecutiveCalls(
                         [
                             'id' => 1,
                             'store_hash' => 'test_hash',
                             'payload' => json_encode($payload),
                             'processed' => 0,
                         ],
                         ['access_token' => 'fake_token']
                     );
        $this->dbMock->expects($this->once())
                     ->method('setStoreContext')
                     ->with('test_hash');
        $this->dbMock->expects($this->once())
                     ->method('query')
                     ->with($this->stringContains('UPDATE webhook_events SET processed'));

        $result = $service->processQueuedWebhookEvent(1);

        $this->assertSame(1, $result['processed']);
        $this->assertSame('store/product/updated', $result['scope']);
        $this->assertSame(123, $result['product_id']);
    }

    public function testProcessWebhookHandlesInventoryUpdate() {
        $service = $this->getMockBuilder(WebhookService::class)
                        ->setConstructorArgs([$this->dbMock, $this->apiMock])
                        ->onlyMethods(['updateProductInventory', 'createBigCommerceAPI'])
                        ->getMock();

        // Očekujemo poziv updateProductInventory sa ID 123 i vrednošću 50
        $service->expects($this->once())
                ->method('updateProductInventory')
                ->with(123, 50);
        $service->method('createBigCommerceAPI')->willReturn($this->apiMock);

        $this->dbMock->method('fetchOne')
                     ->willReturn(['access_token' => 'fake_token']);
        
        $this->dbMock->method('lastInsertId')->willReturn(1);

        $headers = ['HTTP_X_CUSTOM_AUTH' => 'test_secret_key'];
        $payload = [
            'scope' => 'store/product/inventory/updated',
            'store_id' => 'test_hash',
            'data' => [
                'id' => 123,
                'inventory' => ['value' => 50]
            ]
        ];

        $result = $service->processWebhook($payload, $headers);
        
        $this->assertTrue($result);
    }

    public function testProcessWebhookRefreshesCacheForSuppressedProductUpdate() {
        $suppressionMock = $this->createMock(WebhookSuppressionService::class);
        $suppressionMock->expects($this->once())
                        ->method('consumeProductUpdate')
                        ->with('test_hash', 123, 'store/product/updated')
                        ->willReturn(true);

        $service = $this->getMockBuilder(WebhookService::class)
                        ->setConstructorArgs([$this->dbMock, $this->apiMock, $suppressionMock])
                        ->onlyMethods(['updateProductCache', 'createBigCommerceAPI'])
                        ->getMock();

        $service->expects($this->once())
                ->method('updateProductCache')
                ->with(123, false, true);
        $service->method('createBigCommerceAPI')->willReturn($this->apiMock);

        $this->dbMock->method('fetchOne')
                     ->willReturn(['access_token' => 'fake_token']);
        $this->dbMock->method('lastInsertId')->willReturn(1);

        $result = $service->processWebhook([
            'scope' => 'store/product/updated',
            'store_id' => 'test_hash',
            'data' => ['id' => 123],
        ], ['HTTP_X_CUSTOM_AUTH' => 'test_secret_key']);

        $this->assertTrue($result);
        $this->assertSame(202, $service->getLastStatusCode());
    }

    public function testSuppressedProductRefreshReEvaluatesWhenCategoriesChange() {
        $product = [
            'id' => 123,
            'name' => 'Updated product',
            'sku' => 'SKU-123',
            'brand_id' => 7,
            'categories' => [20],
            'is_visible' => true,
            'is_featured' => false,
            'availability' => 'available',
            'condition' => 'new',
        ];

        $this->dbMock->method('getStoreContext')->willReturn('test_hash');
        $this->dbMock->method('fetchOne')->willReturn([
            'sku' => 'SKU-123',
            'brand_id' => 7,
            'categories' => json_encode([10]),
            'is_visible' => true,
            'is_featured' => false,
            'availability' => 'available',
            'condition' => 'new',
        ]);

        $this->apiMock->expects($this->once())
                      ->method('call')
                      ->with('GET', 'catalog/products/123?include=variants,images,custom_fields')
                      ->willReturn(['body' => ['data' => $product]]);

        $cacheServiceMock = $this->getMockBuilder(ProductCacheService::class)
                                 ->disableOriginalConstructor()
                                 ->onlyMethods(['batchCacheProducts'])
                                 ->getMock();
        $cacheServiceMock->expects($this->once())
                         ->method('batchCacheProducts')
                         ->with([$product]);

        $service = $this->getMockBuilder(WebhookService::class)
                        ->setConstructorArgs([$this->dbMock, $this->apiMock])
                        ->onlyMethods(['createProductCacheService', 'reEvaluatePromotionsForProduct'])
                        ->getMock();
        $service->method('createProductCacheService')->willReturn($cacheServiceMock);
        $service->expects($this->once())
                ->method('reEvaluatePromotionsForProduct')
                ->with(123);

        $method = new \ReflectionMethod(WebhookService::class, 'updateProductCache');
        $method->setAccessible(true);
        $method->invoke($service, 123, false, true);
    }
}
