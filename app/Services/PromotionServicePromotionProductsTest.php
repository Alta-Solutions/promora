<?php

use PHPUnit\Framework\TestCase;
use App\Services\PromotionService;

class PromotionServicePromotionProductsTest extends TestCase {
    public function testBatchSavePromotionProductsUpdatesOneParentRowAndDeletesNullVariantDuplicates(): void {
        $db = new class {
            public $queries = [];

            public function fetchAll($sql, $params = []) {
                return [
                    ['id' => 12, 'product_id' => 3145, 'variant_id' => null],
                    ['id' => 11, 'product_id' => 3145, 'variant_id' => null],
                    ['id' => 10, 'product_id' => 3145, 'variant_id' => null],
                ];
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };

        $service = $this->createService($db);
        $this->invokeBatchSavePromotionProducts($service, [[
            'promotion_id' => 43,
            'product_id' => 3145,
            'variant_id' => null,
            'custom_field_id' => 30464,
        ]]);

        $this->assertCount(2, $db->queries);
        $this->assertStringStartsWith('UPDATE promotion_products', $db->queries[0]['sql']);
        $this->assertSame([43, 30464, 'i5zrevrrdl', 12], $db->queries[0]['params']);
        $this->assertStringStartsWith('DELETE FROM promotion_products', $db->queries[1]['sql']);
        $this->assertSame(['i5zrevrrdl', 11, 10], $db->queries[1]['params']);
    }

    public function testBatchSavePromotionProductsDeduplicatesInputBeforeInsert(): void {
        $db = new class {
            public $queries = [];

            public function fetchAll($sql, $params = []) {
                return [];
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };

        $service = $this->createService($db);
        $this->invokeBatchSavePromotionProducts($service, [
            [
                'promotion_id' => 43,
                'product_id' => 3145,
                'variant_id' => null,
                'custom_field_id' => 30464,
            ],
            [
                'promotion_id' => 43,
                'product_id' => 3145,
                'variant_id' => null,
                'custom_field_id' => 30464,
            ],
        ]);

        $this->assertCount(1, $db->queries);
        $this->assertStringStartsWith('INSERT INTO promotion_products', $db->queries[0]['sql']);
        $this->assertSame(['i5zrevrrdl', 43, 3145, null, 30464], $db->queries[0]['params']);
    }

    public function testCleanupIsRequiredWhenUpdatedPromotionIsNoLongerActiveAndStillHasProducts(): void {
        $service = $this->createService(new class {});

        $this->assertTrue($this->invokeShouldQueueCleanupAfterPromotionUpdate($service, 'scheduled', 1));
        $this->assertTrue($this->invokeShouldQueueCleanupAfterPromotionUpdate($service, 'expired', 1));
        $this->assertFalse($this->invokeShouldQueueCleanupAfterPromotionUpdate($service, 'active', 1));
        $this->assertFalse($this->invokeShouldQueueCleanupAfterPromotionUpdate($service, 'scheduled', 0));
    }

    public function testFiltersPromotionRowsToConfirmedPriceUpdates(): void {
        $service = $this->createService(new class {});
        $promotions = [
            'v_5631' => [
                'promotion_id' => 55,
                'product_id' => 5472,
                'variant_id' => 5631,
            ],
            'v_5648' => [
                'promotion_id' => 55,
                'product_id' => 5472,
                'variant_id' => 5648,
            ],
            'p_308' => [
                'promotion_id' => 55,
                'product_id' => 308,
                'variant_id' => null,
            ],
        ];

        $filtered = $this->invokeFilterPromotionsWithSuccessfulPriceUpdates($service, $promotions, [
            [
                'success' => false,
                'product_id' => 5472,
                'variant_id' => 5631,
            ],
            [
                'success' => true,
                'product_id' => 5472,
                'variant_id' => 5648,
            ],
            [
                'success' => true,
                'product_id' => 308,
            ],
        ]);

        $this->assertSame(['v_5648', 'p_308'], array_keys($filtered));
    }

    private function invokeShouldQueueCleanupAfterPromotionUpdate(
        PromotionService $service,
        string $status,
        int $appliedProducts
    ): bool {
        $method = new ReflectionMethod($service, 'shouldQueueCleanupAfterPromotionUpdate');
        $method->setAccessible(true);

        return $method->invoke($service, $status, $appliedProducts);
    }

    private function createService($db): PromotionService {
        $reflection = new ReflectionClass(PromotionService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($service, 'db', $db);
        $this->setPrivateProperty($service, 'storeHash', 'i5zrevrrdl');

        return $service;
    }

    private function invokeBatchSavePromotionProducts(PromotionService $service, array $promotions): void {
        $method = new ReflectionMethod($service, 'batchSavePromotionProducts');
        $method->setAccessible(true);
        $method->invoke($service, $promotions);
    }

    private function invokeFilterPromotionsWithSuccessfulPriceUpdates(
        PromotionService $service,
        array $promotions,
        array $priceResults
    ): array {
        $method = new ReflectionMethod($service, 'filterPromotionsWithSuccessfulPriceUpdates');
        $method->setAccessible(true);

        return $method->invoke($service, $promotions, $priceResults);
    }

    private function setPrivateProperty(object $object, string $property, $value): void {
        $reflectionProperty = new ReflectionProperty($object, $property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);
    }
}
