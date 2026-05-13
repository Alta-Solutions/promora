<?php

use PHPUnit\Framework\TestCase;
use App\Services\OmnibusSyncService;

class OmnibusSyncServiceTest extends TestCase {
    public function testInitialHistorySeedUsesRegularPriceWhenSalePriceIsPresent(): void {
        $priceLogger = new class {
            public $storeHash;
            public $seeds = [];

            public function seedInitialPriceHistoryBatch(string $storeHash, array $pricesToSeed): int {
                $this->storeHash = $storeHash;
                $this->seeds = $pricesToSeed;
                return count($pricesToSeed);
            }
        };

        $service = $this->createService($priceLogger);
        $this->invokeSeedInitialPriceHistory($service, [
            123 => [[
                'product_id' => 123,
                'variant_id' => null,
                'type' => 'product',
                'price' => '100.00',
                'sale_price' => '80.00',
                'cached_at' => '2026-05-11 12:00:00',
            ]],
        ]);

        $this->assertSame('test-store', $priceLogger->storeHash);
        $this->assertCount(1, $priceLogger->seeds);
        $this->assertSame(
            [
                'product_id' => 123,
                'variant_id' => null,
                'price' => 100.0,
                'currency' => 'EUR',
                'recorded_at' => '2026-04-11 12:00:00',
            ],
            $priceLogger->seeds[0]
        );
    }

    public function testInitialHistorySeedFallsBackToSalePriceWhenRegularPriceIsMissing(): void {
        $priceLogger = new class {
            public $seeds = [];

            public function seedInitialPriceHistoryBatch(string $storeHash, array $pricesToSeed): int {
                $this->seeds = $pricesToSeed;
                return count($pricesToSeed);
            }
        };

        $service = $this->createService($priceLogger);
        $this->invokeSeedInitialPriceHistory($service, [
            123 => [[
                'product_id' => 123,
                'variant_id' => null,
                'type' => 'product',
                'price' => null,
                'sale_price' => '80.00',
                'cached_at' => '2026-05-11 12:00:00',
            ]],
        ]);

        $this->assertSame(80.0, $priceLogger->seeds[0]['price']);
    }

    public function testActivePromotionReferenceMapUsesPromotionLifecycleDate(): void {
        $service = $this->createService(new class {
            public function seedInitialPriceHistoryBatch(string $storeHash, array $pricesToSeed): int {
                return count($pricesToSeed);
            }
        });
        $this->setPrivateProperty($service, 'promotionsHasOmnibusTermsUpdatedAt', true);
        $this->setPrivateProperty($service, 'db', new class {
            public function fetchAll($sql, $params = []): array {
                return [[
                    'product_id' => 1101,
                    'variant_id' => null,
                    'start_date' => '2026-05-12 15:42:00',
                    'created_at' => '2026-05-12 15:45:43',
                    'omnibus_terms_updated_at' => '2026-05-13 09:10:11',
                ]];
            }
        });

        $method = new ReflectionMethod($service, 'fetchActivePromotionReferenceMap');
        $method->setAccessible(true);
        $map = $method->invoke($service, [1101]);

        $this->assertArrayHasKey('1101:base', $map);
        $this->assertSame('2026-05-13 09:10:11', $map['1101:base']->format('Y-m-d H:i:s'));
    }

    public function testAggregatedUpdateUsesCachedObservationWhenActivationHistoryIsMissing(): void {
        $pricingService = new class {
            public ?DateTimeImmutable $referenceAt = null;
            public array $options = [];

            public function getDisplayData(
                string $storeHash,
                int $productId,
                ?int $variantId,
                string $currency,
                $currentPrice = null,
                ?DateTimeImmutable $referenceAt = null,
                array $options = []
            ): array {
                $this->referenceAt = $referenceAt;
                $this->options = $options;

                return [
                    'current_price' => '7.9400',
                    'rolling_lowest_price_last_30_days' => '7.9400',
                    'lowest_price_last_30_days' => '7.9400',
                    'is_valid_omnibus_reduction' => true,
                    'omnibus_reference_price' => '12.2200',
                    'effective_currency' => $currency,
                ];
            }
        };

        $service = $this->createService(new class {
            public function seedInitialPriceHistoryBatch(string $storeHash, array $pricesToSeed): int {
                return count($pricesToSeed);
            }
        }, $pricingService);

        $method = new ReflectionMethod($service, 'buildAggregatedUpdateForProduct');
        $method->setAccessible(true);
        $update = $method->invoke($service, 1101, [[
            'product_id' => 1101,
            'variant_id' => null,
            'type' => 'product',
            'price' => '12.22',
            'sale_price' => '7.94',
            'cached_at' => '2026-05-13 15:11:03',
        ]], 'EUR', [
            '1101:base' => new DateTimeImmutable('2026-05-12 15:45:43'),
        ]);

        $this->assertSame('2026-05-13 15:11:03', $pricingService->referenceAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-13 15:11:03', $pricingService->options['current_price_observed_at']);
        $this->assertTrue($update['is_discounted_now']);
        $this->assertSame(12.22, $update['omnibus_reference_price']);
    }

    public function testAggregatedUpdatePrefersFirstObservedPromoPriceAfterLifecycleReference(): void {
        $pricingService = new class {
            public ?DateTimeImmutable $referenceAt = null;

            public function getDisplayData(
                string $storeHash,
                int $productId,
                ?int $variantId,
                string $currency,
                $currentPrice = null,
                ?DateTimeImmutable $referenceAt = null,
                array $options = []
            ): array {
                $this->referenceAt = $referenceAt;

                return [
                    'current_price' => '7.9400',
                    'rolling_lowest_price_last_30_days' => '7.9400',
                    'lowest_price_last_30_days' => '7.9400',
                    'is_valid_omnibus_reduction' => true,
                    'omnibus_reference_price' => '12.2200',
                    'effective_currency' => $currency,
                ];
            }
        };

        $service = $this->createService(new class {
            public function seedInitialPriceHistoryBatch(string $storeHash, array $pricesToSeed): int {
                return count($pricesToSeed);
            }
        }, $pricingService);

        $method = new ReflectionMethod($service, 'buildAggregatedUpdateForProduct');
        $method->setAccessible(true);
        $method->invoke($service, 1101, [[
            'product_id' => 1101,
            'variant_id' => null,
            'type' => 'product',
            'price' => '12.22',
            'sale_price' => '7.94',
            'cached_at' => '2026-05-13 15:11:03',
        ]], 'EUR', [
            '1101:base' => new DateTimeImmutable('2026-05-12 15:45:43'),
        ], [
            '1101:base' => new DateTimeImmutable('2026-05-12 16:33:03'),
        ]);

        $this->assertSame('2026-05-12 16:33:03', $pricingService->referenceAt->format('Y-m-d H:i:s'));
    }

    public function testAggregatedUpdateBuildsVariantReferencePayloadForVariantProducts(): void {
        $pricingService = new class {
            public function getDisplayData(
                string $storeHash,
                int $productId,
                ?int $variantId,
                string $currency,
                $currentPrice = null,
                ?DateTimeImmutable $referenceAt = null,
                array $options = []
            ): array {
                return [
                    'current_price' => $currentPrice,
                    'rolling_lowest_price_last_30_days' => $currentPrice,
                    'lowest_price_last_30_days' => $currentPrice,
                    'is_valid_omnibus_reduction' => true,
                    'omnibus_reference_price' => $variantId === 5631 ? '6.23' : '15.64',
                    'effective_currency' => $currency,
                ];
            }
        };

        $service = $this->createService(new class {
            public function seedInitialPriceHistoryBatch(string $storeHash, array $pricesToSeed): int {
                return count($pricesToSeed);
            }
        }, $pricingService);

        $method = new ReflectionMethod($service, 'buildAggregatedUpdateForProduct');
        $method->setAccessible(true);
        $update = $method->invoke($service, 5472, [
            [
                'product_id' => 5472,
                'variant_id' => 5631,
                'type' => 'variant',
                'price' => '7.79',
                'sale_price' => '4.98',
                'cached_at' => '2026-05-13 15:11:03',
            ],
            [
                'product_id' => 5472,
                'variant_id' => 5648,
                'type' => 'variant',
                'price' => '18.65',
                'sale_price' => '14.92',
                'cached_at' => '2026-05-13 15:11:03',
            ],
        ], 'EUR');

        $this->assertTrue($update['is_discounted_now']);
        $this->assertSame([
            'type' => 'variant_prior_prices',
            'currency' => 'EUR',
            'values' => [
                '5631' => 6.23,
                '5648' => 15.64,
            ],
        ], $update['omnibus_reference_price']);
    }

    private function createService($priceLogger, $pricingService = null): OmnibusSyncService {
        $reflection = new ReflectionClass(OmnibusSyncService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($service, 'storeHash', 'test-store');
        $this->setPrivateProperty($service, 'priceLogger', $priceLogger);
        if ($pricingService !== null) {
            $this->setPrivateProperty($service, 'omnibusPricingService', $pricingService);
        }

        return $service;
    }

    private function invokeSeedInitialPriceHistory(OmnibusSyncService $service, array $productsById): void {
        $method = new ReflectionMethod($service, 'seedInitialPriceHistory');
        $method->setAccessible(true);
        $method->invoke($service, $productsById, 'EUR');
    }

    private function setPrivateProperty(object $object, string $property, $value): void {
        $reflectionProperty = new ReflectionProperty($object, $property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);
    }
}
