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

    public function testUsesRollingLowestWhenCandidateReferenceIsMissing(): void {
        $result = $this->validateAgainstOmnibus([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => 5.00,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ], [
            'product_id' => 3145,
            'variant_id' => null,
            'price' => 10.00,
        ], 9.00);

        $this->assertFalse($result['will_apply']);
        $this->assertFalse($result['omnibus_valid']);
        $this->assertSame('invalid', $result['omnibus_status']);
        $this->assertSame(5.00, $result['lowest_price_30d']);
        $this->assertSame(5.00, $result['rolling_lowest_price_30d']);
        $this->assertSame(5.00, $result['omnibus_reference_price']);
        $this->assertSame('not_below_30_day_lowest', $result['omnibus_invalid_reason']);
    }

    public function testPreviewReferenceDateCannotBeBackdatedBeforeNow(): void {
        $pricingService = $this->createPricingService([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => null,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ]);
        $service = $this->createPromotionService($pricingService);
        $method = (new ReflectionClass(PromotionService::class))->getMethod('buildPromotionPreviewRow');
        $method->setAccessible(true);

        $before = new DateTimeImmutable('now');
        $method->invoke($service, $this->productItem(), 10.00, '2000-01-01 00:00:00');
        $after = new DateTimeImmutable('now');

        $this->assertInstanceOf(DateTimeImmutable::class, $pricingService->referenceAt);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $pricingService->referenceAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $pricingService->referenceAt->getTimestamp());
    }

    public function testSavedPromotionReferenceDateUsesLatestLifecycleTimestamp(): void {
        $pricingService = $this->createPricingService([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => null,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ]);
        $service = $this->createPromotionService($pricingService);
        $method = (new ReflectionClass(PromotionService::class))->getMethod('buildPromotionCandidate');
        $method->setAccessible(true);

        $method->invoke($service, $this->productItem(), [
            'id' => 10,
            'name' => 'Backdated promotion',
            'custom_field_value' => 'Backdated promotion',
            'discount_percent' => 10.00,
            'start_date' => '2026-05-05 10:23:00',
            'created_at' => '2026-05-05 10:00:00',
            'updated_at' => '2026-05-11 18:15:00',
            'priority' => 1,
        ]);

        $this->assertInstanceOf(DateTimeImmutable::class, $pricingService->referenceAt);
        $this->assertSame('2026-05-11 18:15:00', $pricingService->referenceAt->format('Y-m-d H:i:s'));
    }

    private function validateAgainstOmnibus(array $dto, array $item, float $promoPrice): array {
        $serviceClass = new ReflectionClass(PromotionService::class);
        $service = $this->createPromotionService($this->createPricingService($dto));

        $method = $serviceClass->getMethod('validatePromotionPriceAgainstOmnibus');
        $method->setAccessible(true);

        return $method->invoke($service, $item, $promoPrice, '2026-05-05 00:00:00');
    }

    private function createPromotionService(object $pricingService): PromotionService {
        $serviceClass = new ReflectionClass(PromotionService::class);
        $service = $serviceClass->newInstanceWithoutConstructor();

        $this->setPrivateProperty($service, 'storeHash', 'test-store');
        $this->setPrivateProperty($service, 'storeConfigCache', [
            'enable_omnibus' => 1,
            'currency' => 'EUR',
        ]);
        $this->setPrivateProperty($service, 'omnibusPricingService', $pricingService);

        return $service;
    }

    private function createPricingService(array $dto): object {
        return new class($dto) {
            private $dto;
            public ?DateTimeImmutable $referenceAt = null;

            public function __construct(array $dto) {
                $this->dto = $dto;
            }

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
                return $this->dto;
            }
        };
    }

    private function productItem(): array {
        return [
            'id' => 'test-store_123_base',
            'product_id' => 123,
            'variant_id' => null,
            'name' => 'Test product',
            'sku' => 'TEST-123',
            'price' => 100.00,
            'inventory_level' => 10,
            'brand_name' => 'Test brand',
            'is_visible' => 1,
        ];
    }

    private function setPrivateProperty(object $object, string $property, $value): void {
        $reflectionProperty = new ReflectionProperty($object, $property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);
    }
}
