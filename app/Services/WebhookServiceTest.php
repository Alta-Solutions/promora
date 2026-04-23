<?php

use PHPUnit\Framework\TestCase;
use App\Services\WebhookService;
use App\Models\Database;
use App\Services\BigCommerceAPI;

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
        $this->dbMock = $this->createMock(Database::class);
        $this->apiMock = $this->createMock(BigCommerceAPI::class);
    }

    public function testProcessWebhookRejectsInvalidAuth() {
        $service = new WebhookService($this->dbMock, $this->apiMock);
        
        // Pozivamo sa pogrešnim ključem
        $headers = ['HTTP_X_CUSTOM_AUTH' => 'd22442c4d1829d2027afa07a3fc9e826'];
        $payload = ['scope' => 'test'];
        
        $result = $service->processWebhook($payload, $headers);
        
        $this->assertFalse($result, 'Webhook should return false for invalid auth header');
    }

    public function testProcessWebhookValidatesPayload() {
        $service = new WebhookService($this->dbMock, $this->apiMock);
        
        $headers = ['HTTP_X_CUSTOM_AUTH' => 'd22442c4d1829d2027afa07a3fc9e826'];
        // Payload bez store_id i product_id
        $payload = ['scope' => 'store/product/updated'];
        
        $result = $service->processWebhook($payload, $headers);
        
        $this->assertFalse($result, 'Webhook should return false for missing payload data');
    }

    public function testProcessWebhookHandlesProductUpdate() {
        // Koristimo Partial Mock za WebhookService da bismo presreli protected metode
        // Ovako testiramo samo logiku rutiranja, a ne samu sinhronizaciju (updateProductCache)
        $service = $this->getMockBuilder(WebhookService::class)
                        ->setConstructorArgs([$this->dbMock, $this->apiMock])
                        ->onlyMethods(['updateProductCache']) // Metode koje mokujemo
                        ->getMock();

        // Očekujemo da će updateProductCache biti pozvan jednom sa ID-jem 123
        $service->expects($this->once())
                ->method('updateProductCache')
                ->with(123);

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
        $headers = ['HTTP_X_CUSTOM_AUTH' => 'd22442c4d1829d2027afa07a3fc9e826'];
        $payload = [
            'scope' => 'store/product/updated',
            'store_id' => 'test_hash',
            'data' => ['id' => 123]
        ];

        $result = $service->processWebhook($payload, $headers);
        
        $this->assertTrue($result, 'Webhook should return true for successful processing');
    }

    public function testProcessWebhookHandlesInventoryUpdate() {
        $service = $this->getMockBuilder(WebhookService::class)
                        ->setConstructorArgs([$this->dbMock, $this->apiMock])
                        ->onlyMethods(['updateProductInventory'])
                        ->getMock();

        // Očekujemo poziv updateProductInventory sa ID 123 i vrednošću 50
        $service->expects($this->once())
                ->method('updateProductInventory')
                ->with(118, 50);

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
}