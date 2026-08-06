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

class SequencedBigCommerceAPI extends BigCommerceAPI {
    private $responses;
    public $requests = [];

    public function __construct(array $responses) {
        $this->responses = $responses;
    }

    protected function request($method, $endpoint, $data = null) {
        $this->requests[] = [
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data,
        ];

        $response = array_shift($this->responses);
        if ($response instanceof \Exception) {
            throw $response;
        }

        return $response;
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
            'id' => 5648,
            'sale_price' => 14.92,
            'product_id' => 5472,
        ]], $api->requests[0]['data']);
        $this->assertSame([[
            'success' => true,
            'product_id' => 5472,
            'variant_id' => 5648,
        ]], $result);
    }

    public function testBatchUpdateProductsDoesNotSendCatalogPrice() {
        $api = new TestableBigCommerceAPI([
            'status' => 200,
            'body' => [
                'data' => [
                    ['id' => 5472],
                ],
            ],
        ]);

        $api->batchUpdateProducts([[
            'product_id' => 5472,
            'price' => 18.65,
            'sale_price' => 14.92,
        ]]);

        $this->assertSame('PUT', $api->requests[0]['method']);
        $this->assertSame('catalog/products', $api->requests[0]['endpoint']);
        $this->assertSame([[
            'id' => 5472,
            'sale_price' => 14.92,
        ]], $api->requests[0]['data']);
    }

    public function testBatchUpdateProductsRetriesWithoutIndexedBulkFailures() {
        $api = new SequencedBigCommerceAPI([
            new \Exception(
                'BigCommerce API Error (Status: 422, Endpoint: catalog/products):  Body: ' .
                '{"status":422,"code":20200,"title":"Bulk operation has failed",' .
                '"errors":{"2":"A product with the id of 10291 was not found"}}'
            ),
            [
                'status' => 200,
                'body' => [
                    'data' => [
                        ['id' => 100],
                        ['id' => 101],
                        ['id' => 103],
                    ],
                ],
            ],
        ]);

        $result = $api->batchUpdateProducts([
            ['product_id' => 100, 'sale_price' => 8.27],
            ['product_id' => 101, 'sale_price' => 68.52],
            ['product_id' => 10291, 'sale_price' => 5.22],
            ['product_id' => 103, 'sale_price' => 12.36],
        ]);

        $this->assertCount(2, $api->requests);
        $this->assertSame([
            ['id' => 100, 'sale_price' => 8.27],
            ['id' => 101, 'sale_price' => 68.52],
            ['id' => 10291, 'sale_price' => 5.22],
            ['id' => 103, 'sale_price' => 12.36],
        ], $api->requests[0]['data']);
        $this->assertSame([
            ['id' => 100, 'sale_price' => 8.27],
            ['id' => 101, 'sale_price' => 68.52],
            ['id' => 103, 'sale_price' => 12.36],
        ], $api->requests[1]['data']);

        $this->assertSame([
            [
                'success' => false,
                'product_id' => 10291,
                'error' => 'A product with the id of 10291 was not found',
            ],
            [
                'success' => true,
                'product_id' => 100,
            ],
            [
                'success' => true,
                'product_id' => 101,
            ],
            [
                'success' => true,
                'product_id' => 103,
            ],
        ], $result);
    }

    public function testGetTaxSettingsUsesTaxSettingsEndpoint() {
        $api = new TestableBigCommerceAPI([
            'status' => 200,
            'body' => [
                'data' => [
                    'store_tax_zone_id' => 3,
                ],
            ],
        ]);

        $settings = $api->getTaxSettings();

        $this->assertSame('GET', $api->requests[0]['method']);
        $this->assertSame('tax/settings', $api->requests[0]['endpoint']);
        $this->assertSame(['store_tax_zone_id' => 3], $settings);
    }

    public function testGetTaxRatesUsesTaxZoneFilter() {
        $api = new TestableBigCommerceAPI([
            'status' => 200,
            'body' => [
                'data' => [
                    [
                        'tax_zone_id' => 3,
                        'class_rates' => [
                            ['tax_class_id' => 0, 'rate' => 21.00],
                        ],
                    ],
                ],
            ],
        ]);

        $rates = $api->getTaxRates(['tax_zone_id:in' => 3]);

        $this->assertSame('GET', $api->requests[0]['method']);
        $this->assertSame('tax/rates?tax_zone_id%3Ain=3', $api->requests[0]['endpoint']);
        $this->assertSame(21.00, $rates[0]['class_rates'][0]['rate']);
    }
}
