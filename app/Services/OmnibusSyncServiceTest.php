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

    private function createService($priceLogger): OmnibusSyncService {
        $reflection = new ReflectionClass(OmnibusSyncService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $this->setPrivateProperty($service, 'storeHash', 'test-store');
        $this->setPrivateProperty($service, 'priceLogger', $priceLogger);

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
