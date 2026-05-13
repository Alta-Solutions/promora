<?php

use PHPUnit\Framework\TestCase;
use App\Services\OmnibusFieldService;

class OmnibusFieldServiceTest extends TestCase {
    public function testFormatsVariantPriorPricePayloadAsStableJson(): void {
        $service = (new ReflectionClass(OmnibusFieldService::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($service, 'formatFieldValue');
        $method->setAccessible(true);

        $value = $method->invoke($service, [
            'type' => 'variant_prior_prices',
            'currency' => 'EUR',
            'values' => [
                '5648' => 15.6400,
                '5631' => 6.2300,
            ],
        ]);

        $this->assertSame(
            '{"type":"variant_prior_prices","currency":"EUR","values":{"5631":"6.23","5648":"15.64"}}',
            $value
        );
    }
}
