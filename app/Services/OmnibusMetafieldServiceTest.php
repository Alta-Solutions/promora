<?php

use PHPUnit\Framework\TestCase;
use App\Services\BigCommerceAPI;
use App\Services\OmnibusMetafieldService;

class OmnibusMetafieldServiceTest extends TestCase {
    public function testCreatesProductMetafieldForSimpleProduct(): void {
        $api = new class extends BigCommerceAPI {
            public array $requests = [];

            public function __construct() {}

            public function getProductMetafields($productId, ?string $namespace = null, ?string $key = null): array {
                return [];
            }

            public function multiRequest(array $requests): array {
                $this->requests = $requests;
                return array_map(static function (array $request): array {
                    return [
                        'status' => 201,
                        'body' => ['data' => ['id' => 9001, 'value' => $request['data']['value'] ?? null]],
                        'headers' => [],
                        'error' => '',
                    ];
                }, $requests);
            }
        };

        $service = new OmnibusMetafieldService($api);
        $results = $service->syncLowestPriceMetafields([[
            'product_id' => 123,
            'has_variants' => false,
            'product_reference_price' => 15.6300,
        ]]);

        $this->assertSame(200, $results[0]['status']);
        $this->assertArrayHasKey('product:123', $api->requests);
        $this->assertSame('POST', $api->requests['product:123']['method']);
        $this->assertSame('catalog/products/123/metafields', $api->requests['product:123']['endpoint']);
        $this->assertSame([
            'namespace' => 'promora',
            'key' => 'lowest_price_30d',
            'value' => '15.63',
            'permission_set' => 'read_and_sf_access',
            'description' => 'Omnibus lowest prior price',
        ], $api->requests['product:123']['data']);
    }

    public function testSyncsVariantMetafieldsAndDeletesStaleVariantValue(): void {
        $api = new class extends BigCommerceAPI {
            public array $requests = [];

            public function __construct() {}

            public function getVariantMetafields($productId, $variantId, ?string $namespace = null, ?string $key = null): array {
                if ((int)$variantId === 10) {
                    return [[
                        'id' => 501,
                        'namespace' => 'promora',
                        'key' => 'lowest_price_30d',
                        'value' => '7',
                        'permission_set' => 'read_and_sf_access',
                    ]];
                }
                if ((int)$variantId === 11) {
                    return [[
                        'id' => 502,
                        'namespace' => 'promora',
                        'key' => 'lowest_price_30d',
                        'value' => '9',
                        'permission_set' => 'read_and_sf_access',
                    ]];
                }

                return [];
            }

            public function multiRequest(array $requests): array {
                $this->requests = $requests;
                return array_map(static function (array $request): array {
                    return [
                        'status' => $request['method'] === 'DELETE' ? 204 : 201,
                        'body' => [],
                        'headers' => [],
                        'error' => '',
                    ];
                }, $requests);
            }
        };

        $service = new OmnibusMetafieldService($api);
        $results = $service->syncLowestPriceMetafields([[
            'product_id' => 100,
            'has_variants' => true,
            'variant_ids' => [10, 11, 12],
            'variant_reference_prices' => [
                '10' => 7.0,
                '12' => 8.5,
            ],
        ]]);

        $this->assertSame(200, $results[0]['status']);
        $this->assertArrayNotHasKey('variant:100:10', $api->requests);
        $this->assertSame('DELETE', $api->requests['variant:100:11']['method']);
        $this->assertSame('catalog/products/100/variants/11/metafields/502', $api->requests['variant:100:11']['endpoint']);
        $this->assertSame('POST', $api->requests['variant:100:12']['method']);
        $this->assertSame('8.5', $api->requests['variant:100:12']['data']['value']);
    }

    public function testUpdatesExistingMetafieldWhenStorefrontPermissionIsMissing(): void {
        $api = new class extends BigCommerceAPI {
            public array $requests = [];

            public function __construct() {}

            public function getProductMetafields($productId, ?string $namespace = null, ?string $key = null): array {
                return [[
                    'id' => 701,
                    'namespace' => 'promora',
                    'key' => 'lowest_price_30d',
                    'value' => '15.63',
                    'permission_set' => 'app_only',
                ]];
            }

            public function multiRequest(array $requests): array {
                $this->requests = $requests;
                return array_map(static function (): array {
                    return [
                        'status' => 200,
                        'body' => [],
                        'headers' => [],
                        'error' => '',
                    ];
                }, $requests);
            }
        };

        $service = new OmnibusMetafieldService($api);
        $service->syncLowestPriceMetafields([[
            'product_id' => 123,
            'has_variants' => false,
            'product_reference_price' => 15.63,
        ]]);

        $this->assertSame('PUT', $api->requests['product:123']['method']);
        $this->assertSame('read_and_sf_access', $api->requests['product:123']['data']['permission_set']);
    }

    public function testReportsFailedVariantMetafieldWriteAtProductLevel(): void {
        $api = new class extends BigCommerceAPI {
            public function __construct() {}

            public function getVariantMetafields($productId, $variantId, ?string $namespace = null, ?string $key = null): array {
                return [];
            }

            public function multiRequest(array $requests): array {
                return array_map(static function (): array {
                    return [
                        'status' => 422,
                        'body' => ['title' => 'Invalid metafield'],
                        'headers' => [],
                        'error' => '',
                    ];
                }, $requests);
            }
        };

        $service = new class($api) extends OmnibusMetafieldService {
            protected function logWriteFailure(array $meta, array $result): void {}
        };
        $results = $service->syncLowestPriceMetafields([[
            'product_id' => 100,
            'has_variants' => true,
            'variant_ids' => [10],
            'variant_reference_prices' => ['10' => 7.0],
        ]]);

        $this->assertSame(500, $results[0]['status']);
        $this->assertSame(1, $results[0]['error_count']);
    }
}
