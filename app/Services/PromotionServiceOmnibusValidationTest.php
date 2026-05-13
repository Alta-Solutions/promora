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

    public function testSavedPromotionReferenceDateUsesLatestOmnibusTermsTimestamp(): void {
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
            'omnibus_terms_updated_at' => '2026-05-11 18:15:00',
            'updated_at' => '2026-05-12 18:15:00',
            'priority' => 1,
        ]);

        $this->assertInstanceOf(DateTimeImmutable::class, $pricingService->referenceAt);
        $this->assertSame('2026-05-11 18:15:00', $pricingService->referenceAt->format('Y-m-d H:i:s'));
    }

    public function testEditPreviewWithOnlyMetadataChangesReusesExistingPromotionReferenceDate(): void {
        $service = $this->createPromotionService($this->createPricingService([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => null,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ]));
        $this->setPrivateProperty($service, 'promotionModel', $this->promotionModelReturning([
            'id' => 43,
            'discount_percent' => 10.00,
            'start_date' => '2026-05-05 10:23:00',
            'created_at' => '2026-05-06 08:33:26',
            'omnibus_terms_updated_at' => null,
            'updated_at' => '2026-05-13 12:00:00',
            'filters' => '{"brand_id":["444"]}',
        ]));

        $method = (new ReflectionClass(PromotionService::class))->getMethod('resolvePreviewOmnibusReferenceAt');
        $method->setAccessible(true);
        $referenceAt = $method->invoke($service, '2026-05-05T10:23', [
            'promotion_id' => 43,
            'discount_percent' => 10.00,
            'filters' => ['brand_id' => ['444']],
            'start_date' => '2026-05-05T10:23',
        ]);

        $this->assertSame('2026-05-06 08:33:26', $referenceAt->format('Y-m-d H:i:s'));
    }

    public function testEditPreviewWithDiscountChangeUsesCurrentReferenceDateForBackdatedPromotion(): void {
        $service = $this->createPromotionService($this->createPricingService([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => null,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ]));
        $this->setPrivateProperty($service, 'promotionModel', $this->promotionModelReturning([
            'id' => 43,
            'discount_percent' => 10.00,
            'start_date' => '2026-05-05 10:23:00',
            'created_at' => '2026-05-06 08:33:26',
            'omnibus_terms_updated_at' => null,
            'filters' => '{"brand_id":["444"]}',
        ]));

        $method = (new ReflectionClass(PromotionService::class))->getMethod('resolvePreviewOmnibusReferenceAt');
        $method->setAccessible(true);
        $before = new DateTimeImmutable('now');
        $referenceAt = $method->invoke($service, '2026-05-05T10:23', [
            'promotion_id' => 43,
            'discount_percent' => 15.00,
            'filters' => ['brand_id' => ['444']],
            'start_date' => '2026-05-05T10:23',
        ]);
        $after = new DateTimeImmutable('now');

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $referenceAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $referenceAt->getTimestamp());
    }

    public function testExistingPromotionProductSkipsRevalidationWhenTermsDidNotChange(): void {
        $pricingService = $this->createPricingService([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => 5.00,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ]);
        $service = $this->createPromotionService($pricingService);
        $this->setPrivateProperty($service, 'db', $this->dbReturningSyncedPromotionProduct('2026-05-11 20:55:52'));

        $method = (new ReflectionClass(PromotionService::class))->getMethod('buildPromotionCandidate');
        $method->setAccessible(true);
        $candidate = $method->invoke($service, $this->productItem(), [
            'id' => 43,
            'name' => 'Existing promotion',
            'custom_field_value' => 'Existing promotion',
            'discount_percent' => 10.00,
            'start_date' => '2026-05-05 10:23:00',
            'created_at' => '2026-05-06 08:33:26',
            'omnibus_terms_updated_at' => null,
            'priority' => 1,
        ]);

        $this->assertTrue($candidate['will_apply']);
        $this->assertTrue($candidate['omnibus_valid']);
        $this->assertTrue($candidate['omnibus_revalidation_skipped']);
        $this->assertNull($candidate['omnibus_invalid_reason']);
    }

    public function testExistingPromotionProductIsRevalidatedAfterTermsChange(): void {
        $pricingService = $this->createPricingService([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => 5.00,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ]);
        $service = $this->createPromotionService($pricingService);
        $this->setPrivateProperty($service, 'db', $this->dbReturningSyncedPromotionProduct('2026-05-11 20:55:52'));

        $method = (new ReflectionClass(PromotionService::class))->getMethod('buildPromotionCandidate');
        $method->setAccessible(true);
        $candidate = $method->invoke($service, $this->productItem(), [
            'id' => 43,
            'name' => 'Changed promotion',
            'custom_field_value' => 'Changed promotion',
            'discount_percent' => 10.00,
            'start_date' => '2026-05-05 10:23:00',
            'created_at' => '2026-05-06 08:33:26',
            'omnibus_terms_updated_at' => '2026-05-12 12:00:00',
            'priority' => 1,
        ]);

        $this->assertFalse($candidate['will_apply']);
        $this->assertFalse($candidate['omnibus_valid']);
        $this->assertSame('not_below_30_day_lowest', $candidate['omnibus_invalid_reason']);
    }

    public function testExistingOmnibusFieldAllowsPartialSyncRepairWhenPromoPriceIsBelowDisplayedReference(): void {
        $pricingService = $this->createPricingService([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => 70.00,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ]);
        $service = $this->createPromotionService($pricingService);

        $method = (new ReflectionClass(PromotionService::class))->getMethod('buildPromotionCandidate');
        $method->setAccessible(true);
        $candidate = $method->invoke($service, $this->productItem([
            'custom_fields' => json_encode([
                ['id' => 987, 'name' => 'lowest_price_30d', 'value' => '100'],
            ]),
        ]), [
            'id' => 43,
            'name' => 'Partially synced promotion',
            'custom_field_value' => 'Partially synced promotion',
            'discount_percent' => 10.00,
            'start_date' => '2026-05-05 10:23:00',
            'created_at' => '2026-05-06 08:33:26',
            'omnibus_terms_updated_at' => '2026-05-06 08:33:26',
            'priority' => 1,
        ]);

        $this->assertTrue($candidate['will_apply']);
        $this->assertTrue($candidate['omnibus_valid']);
        $this->assertTrue($candidate['omnibus_existing_field_repair_allowed']);
        $this->assertSame(100.00, $candidate['omnibus_reference_price']);
        $this->assertNull($candidate['omnibus_invalid_reason']);
    }

    public function testExistingOmnibusFieldDoesNotRepairWhenPromoPriceIsNotBelowDisplayedReference(): void {
        $pricingService = $this->createPricingService([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => 70.00,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ]);
        $service = $this->createPromotionService($pricingService);

        $method = (new ReflectionClass(PromotionService::class))->getMethod('buildPromotionCandidate');
        $method->setAccessible(true);
        $candidate = $method->invoke($service, $this->productItem([
            'custom_fields' => json_encode([
                ['id' => 987, 'name' => 'lowest_price_30d', 'value' => '90'],
            ]),
        ]), [
            'id' => 43,
            'name' => 'Invalid promotion',
            'custom_field_value' => 'Invalid promotion',
            'discount_percent' => 10.00,
            'start_date' => '2026-05-05 10:23:00',
            'created_at' => '2026-05-06 08:33:26',
            'omnibus_terms_updated_at' => '2026-05-06 08:33:26',
            'priority' => 1,
        ]);

        $this->assertFalse($candidate['will_apply']);
        $this->assertFalse($candidate['omnibus_valid']);
        $this->assertArrayNotHasKey('omnibus_existing_field_repair_allowed', $candidate);
        $this->assertSame('not_below_30_day_lowest', $candidate['omnibus_invalid_reason']);
    }

    public function testExistingVariantOmnibusFieldAllowsRepairForMatchingVariantReference(): void {
        $pricingService = $this->createPricingService([
            'candidate_omnibus_reference_price' => null,
            'omnibus_reference_price' => null,
            'rolling_lowest_price_last_30_days' => 10.00,
            'is_price_drop_candidate' => false,
            'is_valid_omnibus_reduction' => false,
            'invalid_reduction_reason' => null,
        ]);
        $service = $this->createPromotionService($pricingService);

        $method = (new ReflectionClass(PromotionService::class))->getMethod('buildPromotionCandidate');
        $method->setAccessible(true);
        $candidate = $method->invoke($service, $this->productItem([
            'product_id' => 5472,
            'variant_id' => 5648,
            'price' => 18.65,
            'custom_fields' => json_encode([
                [
                    'id' => 987,
                    'name' => 'lowest_price_30d',
                    'value' => '{"type":"variant_prior_prices","currency":"EUR","values":{"5631":"6.23","5648":"15.64"}}',
                ],
            ]),
        ]), [
            'id' => 55,
            'name' => 'Variant repair promotion',
            'custom_field_value' => 'Na popustu',
            'discount_percent' => 20.00,
            'start_date' => '2026-05-12 11:03:00',
            'created_at' => '2026-05-12 11:11:28',
            'omnibus_terms_updated_at' => '2026-05-12 11:11:28',
            'priority' => 1,
        ]);

        $this->assertTrue($candidate['will_apply']);
        $this->assertTrue($candidate['omnibus_existing_field_repair_allowed']);
        $this->assertSame(15.64, $candidate['omnibus_reference_price']);
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

    private function productItem(array $overrides = []): array {
        return $overrides + [
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

    private function promotionModelReturning(array $promotion): object {
        return new class($promotion) {
            private $promotion;

            public function __construct(array $promotion) {
                $this->promotion = $promotion;
            }

            public function findById($id): array {
                return $this->promotion;
            }
        };
    }

    private function dbReturningSyncedPromotionProduct(string $syncedAt): object {
        return new class($syncedAt) {
            private $syncedAt;

            public function __construct(string $syncedAt) {
                $this->syncedAt = $syncedAt;
            }

            public function fetchOne($sql, $params = []): array {
                return ['synced_at' => $this->syncedAt];
            }
        };
    }

    private function setPrivateProperty(object $object, string $property, $value): void {
        $reflectionProperty = new ReflectionProperty($object, $property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);
    }
}
