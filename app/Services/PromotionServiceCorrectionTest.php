<?php

use PHPUnit\Framework\TestCase;
use App\Services\PromotionService;

class PromotionServiceCorrectionTest extends TestCase {
    public function testValidActiveCorrectionBuildsAuditedRevisionWithoutChangingLifecycleTerms(): void {
        $service = $this->createService();

        $revision = $this->invokeValidateCorrection($service, $this->activePromotion(), [
            'discount_percent' => 15.00,
            'start_date' => '2026-05-05 10:23:00',
            'end_date' => '2026-06-05 10:23:00',
            'status' => 'active',
        ], $this->bigCommerceCorrectionContext());

        $this->assertSame('active_discount_correction', $revision['change_type']);
        $this->assertSame('Initial discount was entered incorrectly.', $revision['reason']);
        $this->assertSame('bc-user-123', $revision['actor_user_id']);
        $this->assertSame('employee@example.com', $revision['actor_email']);
        $this->assertSame(20.00, $revision['old_discount_percent']);
        $this->assertSame(15.00, $revision['new_discount_percent']);
    }

    public function testActiveCorrectionRequiresVerifiedBigCommerceUser(): void {
        $service = $this->createService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Open the app from the BigCommerce control panel');

        $this->invokeValidateCorrection($service, $this->activePromotion(), [
            'discount_percent' => 15.00,
            'start_date' => '2026-05-05 10:23:00',
            'end_date' => '2026-06-05 10:23:00',
            'status' => 'active',
        ], array_replace($this->bigCommerceCorrectionContext(), [
            'actor_source' => 'local_admin',
            'actor_user_id' => null,
        ]));
    }

    public function testActiveCorrectionCannotBackdatePromotionStart(): void {
        $service = $this->createService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('start date cannot be changed');

        $this->invokeValidateCorrection($service, $this->activePromotion(), [
            'discount_percent' => 15.00,
            'start_date' => '2026-05-01 10:23:00',
            'end_date' => '2026-06-05 10:23:00',
            'status' => 'active',
        ], $this->bigCommerceCorrectionContext());
    }

    public function testCorrectionRevisionIsSavedInSameTransactionAsPromotionUpdate(): void {
        $db = new class {
            public bool $began = false;
            public bool $committed = false;
            public array $queries = [];

            public function beginTransaction(): bool {
                $this->began = true;
                return true;
            }

            public function query($sql, $params = []): void {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }

            public function commit(): void {
                $this->committed = true;
            }

            public function rollback(): void {
                throw new RuntimeException('Rollback was not expected.');
            }
        };
        $promotionModel = new class {
            public array $updates = [];

            public function update($id, array $data): bool {
                $this->updates[] = ['id' => $id, 'data' => $data];
                return true;
            }
        };
        $service = $this->createService();
        $this->setPrivateProperty($service, 'db', $db);
        $this->setPrivateProperty($service, 'promotionModel', $promotionModel);

        $revision = $this->invokeValidateCorrection($service, $this->activePromotion(), [
            'discount_percent' => 15.00,
            'start_date' => '2026-05-05 10:23:00',
            'end_date' => '2026-06-05 10:23:00',
            'status' => 'active',
        ], $this->bigCommerceCorrectionContext());

        $method = new ReflectionMethod($service, 'savePromotionUpdateWithCorrectionRevision');
        $method->setAccessible(true);
        $result = $method->invoke($service, 77, ['discount_percent' => 15.00], $revision);

        $this->assertTrue($result);
        $this->assertTrue($db->began);
        $this->assertTrue($db->committed);
        $this->assertCount(1, $promotionModel->updates);
        $this->assertCount(2, $db->queries);
        $this->assertStringStartsWith('UPDATE promotion_products pp', $db->queries[0]['sql']);
        $this->assertStringContainsString(
            'pc.store_hash COLLATE utf8mb4_unicode_ci = pp.store_hash COLLATE utf8mb4_unicode_ci',
            $db->queries[0]['sql']
        );
        $this->assertStringContainsString(
            'ph.store_hash COLLATE utf8mb4_unicode_ci = pp.store_hash COLLATE utf8mb4_unicode_ci',
            $db->queries[0]['sql']
        );
        $this->assertSame('test-store', $db->queries[0]['params'][3]);
        $this->assertSame(77, $db->queries[0]['params'][4]);
        $this->assertStringStartsWith('INSERT INTO promotion_revisions', $db->queries[1]['sql']);
        $this->assertSame('test-store', $db->queries[1]['params'][0]);
        $this->assertSame(77, $db->queries[1]['params'][1]);
        $this->assertSame('bc-user-123', $db->queries[1]['params'][5]);
    }

    private function createService(): PromotionService {
        $reflection = new ReflectionClass(PromotionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $this->setPrivateProperty($service, 'storeHash', 'test-store');
        $this->setPrivateProperty($service, 'storeConfigCache', [
            'enable_omnibus' => 1,
            'currency' => 'EUR',
        ]);
        $this->setPrivateProperty($service, 'priceHistoryHasVariantId', true);

        return $service;
    }

    private function invokeValidateCorrection(
        PromotionService $service,
        array $existingPromotion,
        array $newData,
        array $context
    ): ?array {
        $method = new ReflectionMethod($service, 'validateActiveDiscountCorrectionRequest');
        $method->setAccessible(true);

        return $method->invoke($service, $existingPromotion, $newData, $context);
    }

    private function activePromotion(): array {
        return [
            'id' => 77,
            'status' => 'active',
            'discount_percent' => 20.00,
            'start_date' => '2026-05-05 10:23:00',
            'end_date' => '2026-06-05 10:23:00',
            'filters' => json_encode(['sku' => 'TEST-123']),
        ];
    }

    private function bigCommerceCorrectionContext(): array {
        return [
            'change_type' => 'active_discount_correction',
            'correction_reason' => 'Initial discount was entered incorrectly.',
            'actor_source' => 'bigcommerce',
            'actor_user_id' => 'bc-user-123',
            'actor_email' => 'employee@example.com',
            'actor_is_owner' => false,
        ];
    }

    private function setPrivateProperty(object $object, string $property, $value): void {
        $reflectionProperty = new ReflectionProperty($object, $property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($object, $value);
    }
}
