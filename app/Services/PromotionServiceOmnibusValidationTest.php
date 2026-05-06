<?php

use PHPUnit\Framework\TestCase;
use App\Services\PromotionService;

class PromotionServiceOmnibusValidationTest extends TestCase {
    public function testAllowsPromotionWhenOmnibusHistoryIsMissingButBasePriceIsKnown(): void {
        $result = $this->validateAgainstOmnibus([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => null,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ], [
            'product_id' => 123,
            'variant_id' => null,
            'price' => 100.00,
        ], 80.00);

        $this->assertTrue($result['will_apply']);
        $this->assertTrue($result['omnibus_valid']);
        $this->assertSame('valid', $result['omnibus_status']);
        $this->assertSame(100.00, $result['lowest_price_30d']);
        $this->assertSame(100.00, $result['omnibus_reference_price']);
        $this->assertNull($result['omnibus_invalid_reason']);
    }

    public function testBlocksPromotionWhenKnownOmnibusReferenceIsLowerThanPromoPrice(): void {
        $result = $this->validateAgainstOmnibus([
            'candidate_omnibus_reference_price' => 70.00,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => 70.00,
            'is_price_drop_candidate' => true,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => 'not_below_30_day_lowest',
        ], [
            'product_id' => 123,
            'variant_id' => null,
            'price' => 100.00,
        ], 80.00);

        $this->assertFalse($result['will_apply']);
        $this->assertFalse($result['omnibus_valid']);
        $this->assertSame('invalid', $result['omnibus_status']);
        $this->assertSame(70.00, $result['lowest_price_30d']);
        $this->assertSame('not_below_30_day_lowest', $result['omnibus_invalid_reason']);
    }

    private function validateAgainstOmnibus(array $dto, array $item, float $promoPrice): array {
        $serviceClass = new ReflectionClass(PromotionService::class);
        $service = $serviceClass->newInstanceWithoutConstructor();

        $pricingService = new class($dto) {
            private $dto;

            public function __construct(array $dto) {
                $this->dto = $dto;
            }

            public function getDisplayData(
                string $storeHash,
                int $productId,
                ?int $variantId,
                string $currency,
                $currentPrice = null,
                DateTimeImmutable $referenceAt = null,
                array $options = []
            ): array {
                return $this->dto;
            }
        };

        $this->setPrivateProperty($service, 'storeHash', 'test-store');
        $this->setPrivateProperty($service, 'storeConfigCache', [
            'enable_omnibus' => 1,
            'currency' => 'EUR',
        ]);
        $this->setPrivateProperty($service, 'omnibusPricingService', $pricingService);

        $method = $serviceClass->getMethod('validatePromotionPriceAgainstOmnibus');
        $method->setAccessible(true);

        return $method->invoke($service, $item, $promoPrice, '2026-05-05 00:00:00');
    }

    private function setPrivateProperty(object $object, string $property, $value): void {
        $reflectionProperty = new ReflectionProperty($object, $property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);
    }
}
