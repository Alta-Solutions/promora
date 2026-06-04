<?php

use PHPUnit\Framework\TestCase;
use App\Services\OmnibusLowestPriceRepairService;

class OmnibusLowestPriceRepairServiceTest extends TestCase {
    public function testMissingReferenceCandidateSeedsBoundaryBaselineOnApply(): void {
        $db = new class {
            public $queries = [];

            public function setStoreContext($storeHash): void {}

            public function fetchOne($sql, $params = []) {
                if (strpos($sql, 'bigcommerce_stores') !== false) {
                    return ['currency' => 'EUR'];
                }

                if (strpos($sql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'exists'];
                }

                return false;
            }

            public function fetchAll($sql, $params = []) {
                return [[
                    'promotion_product_id' => 501,
                    'promotion_id' => 182,
                    'product_id' => 10040,
                    'variant_id' => null,
                    'first_applied_at' => '2026-06-01 14:25:08',
                    'omnibus_reference_at' => '2026-06-01 14:25:08',
                    'price' => '19.66',
                    'sale_price' => '9.83',
                    'cached_at' => '2026-06-01 14:25:10',
                    'start_date' => '2026-06-01 00:00:00',
                    'created_at' => '2026-05-20 10:00:00',
                    'omnibus_terms_updated_at' => null,
                ]];
            }

            public function query($sql, $params = []) {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
            }
        };
        $pricing = new class {
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
                    'invalid_reduction_reason' => 'missing_reference_price',
                    'candidate_omnibus_reference_price' => null,
                    'is_valid_omnibus_reduction' => false,
                    'omnibus_reference_price' => null,
                ];
            }
        };
        $priceLogger = new class {
            public $seeds = [];

            public function seedInitialPriceHistoryBatch(string $storeHash, array $pricesToSeed): int {
                $this->seeds = $pricesToSeed;
                return count($pricesToSeed);
            }
        };

        $service = new OmnibusLowestPriceRepairService($db, $pricing, $priceLogger);
        $result = $service->run('test-store', 182, 10040, true, false);

        $this->assertSame(1, $result['applied_count']);
        $this->assertSame('missing_reference_price', $result['results'][0]['reason']);
        $this->assertSame([[
            'product_id' => 10040,
            'variant_id' => null,
            'price' => 19.66,
            'currency' => 'EUR',
            'recorded_at' => '2026-05-02 14:25:08',
        ]], $priceLogger->seeds);
        $this->assertSame([], $db->queries);
    }

    public function testSaleBeforeReferenceCandidateMovesReferenceWhenRecalculationIsValid(): void {
        $db = new class {
            public $queries = [];

            public function setStoreContext($storeHash): void {}

            public function fetchOne($sql, $params = []) {
                if (strpos($sql, 'bigcommerce_stores') !== false) {
                    return ['currency' => 'EUR'];
                }

                if (strpos($sql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'exists'];
                }

                if (strpos($sql, 'MIN(recorded_at)') !== false) {
                    return ['first_recorded_at' => '2026-06-03 00:02:31'];
                }

                return false;
            }

            public function fetchAll($sql, $params = []) {
                return [[
                    'promotion_product_id' => 777,
                    'promotion_id' => 172,
                    'product_id' => 12126,
                    'variant_id' => null,
                    'first_applied_at' => '2026-06-03 00:02:34',
                    'omnibus_reference_at' => '2026-06-03 00:02:34',
                    'price' => '28.63',
                    'sale_price' => '22.90',
                    'cached_at' => '2026-06-04 15:27:20',
                    'start_date' => '2026-06-01 00:00:00',
                    'created_at' => '2026-05-20 10:00:00',
                    'omnibus_terms_updated_at' => null,
                ]];
            }

            public function query($sql, $params = []) {
                $this->queries[] = [
                    'sql' => preg_replace('/\s+/', ' ', trim($sql)),
                    'params' => $params,
                ];
            }
        };
        $pricing = new class {
            public function getDisplayData(
                string $storeHash,
                int $productId,
                ?int $variantId,
                string $currency,
                $currentPrice = null,
                ?DateTimeImmutable $referenceAt = null,
                array $options = []
            ): array {
                if ($referenceAt && $referenceAt->format('Y-m-d H:i:s') === '2026-06-03 00:02:31') {
                    return [
                        'invalid_reduction_reason' => null,
                        'candidate_omnibus_reference_price' => '28.6300',
                        'is_valid_omnibus_reduction' => true,
                        'omnibus_reference_price' => '28.6300',
                    ];
                }

                return [
                    'invalid_reduction_reason' => 'not_below_30_day_lowest',
                    'candidate_omnibus_reference_price' => '22.9000',
                    'is_valid_omnibus_reduction' => false,
                    'omnibus_reference_price' => null,
                ];
            }
        };

        $service = new OmnibusLowestPriceRepairService($db, $pricing, new class {
            public function seedInitialPriceHistoryBatch(string $storeHash, array $pricesToSeed): int {
                return 0;
            }
        });
        $result = $service->run('test-store', 172, 12126, true, false);

        $this->assertSame(1, $result['applied_count']);
        $this->assertSame('sale_before_reference', $result['results'][0]['reason']);
        $this->assertSame('2026-06-03 00:02:31', $result['results'][0]['new_reference_at']);
        $this->assertCount(1, $db->queries);
        $this->assertStringStartsWith('UPDATE promotion_products', $db->queries[0]['sql']);
        $this->assertSame(
            ['2026-06-03 00:02:31', '2026-06-03 00:02:34', '2026-06-03 00:02:31', 'test-store', 777, '2026-06-03 00:02:34'],
            $db->queries[0]['params']
        );
    }

    public function testDryRunReportsCandidatesWithoutMutatingDatabase(): void {
        $db = new class {
            public $queries = [];

            public function setStoreContext($storeHash): void {}

            public function fetchOne($sql, $params = []) {
                if (strpos($sql, 'bigcommerce_stores') !== false) {
                    return ['currency' => 'EUR'];
                }

                if (strpos($sql, 'SHOW COLUMNS') !== false) {
                    return ['Field' => 'exists'];
                }

                return false;
            }

            public function fetchAll($sql, $params = []) {
                return [[
                    'promotion_product_id' => 501,
                    'promotion_id' => 182,
                    'product_id' => 10040,
                    'variant_id' => null,
                    'first_applied_at' => '2026-06-01 14:25:08',
                    'omnibus_reference_at' => '2026-06-01 14:25:08',
                    'price' => '19.66',
                    'sale_price' => '9.83',
                    'cached_at' => '2026-06-01 14:25:10',
                    'start_date' => '2026-06-01 00:00:00',
                    'created_at' => '2026-05-20 10:00:00',
                    'omnibus_terms_updated_at' => null,
                ]];
            }

            public function query($sql, $params = []) {
                $this->queries[] = ['sql' => $sql, 'params' => $params];
            }
        };
        $pricing = new class {
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
                    'invalid_reduction_reason' => 'missing_reference_price',
                    'candidate_omnibus_reference_price' => null,
                    'is_valid_omnibus_reduction' => false,
                    'omnibus_reference_price' => null,
                ];
            }
        };
        $priceLogger = new class {
            public $seeds = [];

            public function seedInitialPriceHistoryBatch(string $storeHash, array $pricesToSeed): int {
                $this->seeds = $pricesToSeed;
                return count($pricesToSeed);
            }
        };

        $service = new OmnibusLowestPriceRepairService($db, $pricing, $priceLogger);
        $result = $service->run('test-store', 182, 10040, false, false);

        $this->assertSame(1, $result['total_candidates']);
        $this->assertSame('dry_run', $result['results'][0]['status']);
        $this->assertSame([], $db->queries);
        $this->assertSame([], $priceLogger->seeds);
    }
}
