<?php

use PHPUnit\Framework\TestCase;
use App\Services\PromotionApplicationCorrectionService;
use App\Services\PromotionService;

class PromotionApplicationCorrectionServiceTest extends TestCase {
    public function testApplyVoidCorrectionCreatesAuditAndIgnoresAffectedHistoryRows(): void {
        $db = new class {
            public $queries = [];
            public $lastId = 9001;

            public function setStoreContext($storeHash): void {}

            public function fetchOne($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

                if (strpos($normalizedSql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'exists'];
                }

                if (strpos($normalizedSql, 'SELECT pp.id AS promotion_product_id') !== false) {
                    return [
                        'promotion_product_id' => 77,
                        'promotion_id' => 123,
                        'product_id' => 456,
                        'variant_id' => null,
                        'first_applied_at' => '2026-06-10 10:00:00',
                        'omnibus_reference_at' => '2026-06-10 10:00:00',
                        'synced_at' => '2026-06-10 10:00:05',
                        'promotion_name' => 'Wrong promo',
                        'discount_percent' => '20.00',
                        'product_name' => 'Test product',
                        'sku' => 'SKU-1',
                        'type' => 'product',
                        'price' => '100.00',
                        'promo_price' => '80.00',
                        'cached_at' => '2026-06-10 10:00:05',
                    ];
                }

                if (strpos($normalizedSql, 'SELECT id FROM promotion_application_corrections') !== false) {
                    return false;
                }

                if (strpos($normalizedSql, 'SELECT currency FROM bigcommerce_stores') !== false) {
                    return ['currency' => 'EUR'];
                }

                if (strpos($normalizedSql, 'SELECT enable_omnibus FROM bigcommerce_stores') !== false) {
                    return ['enable_omnibus' => 0];
                }

                if (strpos($normalizedSql, 'SELECT price FROM product_price_history') !== false) {
                    return false;
                }

                return false;
            }

            public function fetchAll($sql, $params = []) {
                $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

                if (strpos($normalizedSql, 'FROM product_price_history') !== false) {
                    return [
                        ['id' => 501, 'product_id' => 456, 'variant_id' => null, 'price' => '80.0000', 'currency' => 'EUR', 'recorded_at' => '2026-06-10 10:00:05'],
                        ['id' => 502, 'product_id' => 456, 'variant_id' => null, 'price' => '80.0000', 'currency' => 'EUR', 'recorded_at' => '2026-06-10 10:01:00'],
                    ];
                }

                return [];
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];

                return new class {
                    public function rowCount(): int {
                        return 2;
                    }
                };
            }

            public function lastInsertId() {
                return $this->lastId;
            }
        };

        $promotionService = new class extends PromotionService {
            public function __construct() {}

            public function voidPromotionProductAndReconcile(
                int $productId,
                ?int $variantId,
                int $promotionId,
                ?int $correctionId = null,
                string $reason = 'voided_error'
            ): array {
                return [
                    'status' => 'restored',
                    'replacement_promotion_id' => null,
                    'replacement' => null,
                    'omnibus_product_ids' => [$productId],
                ];
            }
        };

        $service = $this->createServiceWithoutConstructor($db, $promotionService);
        $result = $service->applyVoidCorrection(
            'store-a',
            456,
            null,
            123,
            'Product was added to the wrong promotion.',
            [
                'actor_source' => 'bigcommerce',
                'actor_user_id' => 'user-1',
                'actor_email' => 'user@example.com',
                'actor_is_owner' => false,
            ],
            true,
            'preview-token'
        );

        $this->assertSame('applied', $result['status']);
        $this->assertSame(9001, $result['correction_id']);
        $this->assertSame(2, $result['ignored_history_rows']);

        $sqlList = array_column($db->queries, 'sql');
        $this->assertNotFalse(array_search(true, array_map(static function (string $sql): bool {
            return strpos($sql, 'INSERT INTO promotion_application_corrections') === 0;
        }, $sqlList), true));
        $this->assertNotFalse(array_search(true, array_map(static function (string $sql): bool {
            return strpos($sql, 'UPDATE product_price_history SET ignored_at = NOW()') === 0;
        }, $sqlList), true));
        $this->assertNotFalse(array_search(true, array_map(static function (string $sql): bool {
            return strpos($sql, "UPDATE promotion_application_corrections SET status = 'applied'") === 0;
        }, $sqlList), true));
    }

    public function testApplyVoidCorrectionRequiresVisibilityConfirmation(): void {
        $service = $this->createServiceWithoutConstructor(new class {});

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Confirm the Omnibus responsibility statement');

        $service->applyVoidCorrection('store-a', 1, null, 2, 'Reason', [], false, 'token');
    }

    private function createServiceWithoutConstructor($db, $promotionService = null): PromotionApplicationCorrectionService {
        $reflection = new ReflectionClass(PromotionApplicationCorrectionService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getProperty('db');
        $property->setAccessible(true);
        $property->setValue($service, $db);

        $promotionServiceProperty = $reflection->getProperty('promotionService');
        $promotionServiceProperty->setAccessible(true);
        $promotionServiceProperty->setValue($service, $promotionService);

        return $service;
    }
}
