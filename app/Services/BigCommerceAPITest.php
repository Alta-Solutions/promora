<?php

use PHPUnit\Framework\TestCase;
use App\Services\BigCommerceAPI;

class TestableBigCommerceAPI extends BigCommerceAPI {
    private $stubResponse;
    public $requests = [];

    public function __construct(array $stubResponse) {
        $this->stubResponse = $stubResponse;
    }

    protected function request($method, $endpoint, $data = null) {
        $this->requests[] = [
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data,
        ];

        return $this->stubResponse;
    }
}

class BigCommerceAPITest extends TestCase {
    public function testDeleteWebhookTreats200AsSuccess() {
        $api = new TestableBigCommerceAPI(['status' => 200]);

        $result = $api->deleteWebhook(123);

        $this->assertTrue($result);
    }

    public function testDeleteWebhookTreats204AsSuccess() {
        $api = new TestableBigCommerceAPI(['status' => 204]);

        $result = $api->deleteWebhook(123);

        $this->assertTrue($result);
    }

    public function testBatchUpdateVariantsUsesCatalogVariantsEndpoint() {
        $api = new TestableBigCommerceAPI([
            'status' => 200,
            'body' => [
                'data' => [
                    [
                        'id' => 5648,
                        'product_id' => 5472,
                    ],
                ],
            ],
        ]);

        $result = $api->batchUpdateVariants([[
            'product_id' => 5472,
            'id' => 5648,
            'price' => 18.65,
            'sale_price' => 14.92,
        ]]);

        $this->assertSame('PUT', $api->requests[0]['method']);
        $this->assertSame('catalog/variants', $api->requests[0]['endpoint']);
        $this->assertSame([[
            'success' => true,
            'product_id' => 5472,
            'variant_id' => 5648,
        ]], $result);
    }
}
