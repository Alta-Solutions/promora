<?php

use PHPUnit\Framework\TestCase;
use App\Services\ProductCacheService;

class ProductCacheServiceTest extends TestCase {
    public function testBuildTaxRatesByClassSumsEnabledRates(): void {
        $service = $this->createServiceWithoutConstructor();

        $result = $this->invokePrivate($service, 'buildTaxRatesByClass', [[
            [
                'enabled' => true,
                'class_rates' => [
                    ['tax_class_id' => 0, 'rate' => 17.00],
                    ['tax_class_id' => 1, 'rate' => 7.00],
                ],
            ],
            [
                'enabled' => true,
                'class_rates' => [
                    ['tax_class_id' => 0, 'rate' => 4.00],
                ],
            ],
            [
                'enabled' => false,
                'class_rates' => [
                    ['tax_class_id' => 0, 'rate' => 99.00],
                ],
            ],
        ]]);

        $this->assertSame(21.00, $result[0]);
        $this->assertSame(7.00, $result[1]);
    }

    public function testResolveProductTaxRateFallsBackToZeroWhenTaxClassHasNoRate(): void {
        $service = $this->createServiceWithoutConstructor();

        $result = $this->invokePrivate($service, 'resolveProductTaxRate', [[0 => 21.00], 7]);

        $this->assertSame(0.00, $result);
    }

    private function createServiceWithoutConstructor(): ProductCacheService {
        $reflection = new ReflectionClass(ProductCacheService::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    private function invokePrivate(object $object, string $method, array $args) {
        $reflectionMethod = (new ReflectionClass($object))->getMethod($method);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($object, $args);
    }
}
