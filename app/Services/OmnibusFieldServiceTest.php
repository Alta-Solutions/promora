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

    public function testSkipsLegacyCustomFieldWhenVariantPayloadExceedsBigCommerceLimit(): void {
        $service = (new ReflectionClass(OmnibusFieldService::class))->newInstanceWithoutConstructor();

        $values = [];
        foreach ([15966, 15967, 15968, 15969, 15970, 15971, 15972, 16063, 16064, 16065, 16066] as $variantId) {
            $values[(string)$variantId] = 20.46;
        }
        foreach ([16075, 16078] as $variantId) {
            $values[(string)$variantId] = 23.38;
        }
        foreach ([16081, 16082] as $variantId) {
            $values[(string)$variantId] = 30.35;
        }

        $results = $service->batchSyncLowestPriceFields([[
            'product_id' => 11406,
            'omnibus_reference_price' => [
                'type' => 'variant_prior_prices',
                'currency' => 'EUR',
                'values' => $values,
            ],
        ]], [
            11406 => ['custom_fields' => '[]'],
        ]);

        $this->assertSame(204, $results[0]['status']);
        $this->assertTrue($results[0]['skipped']);
    }
}
