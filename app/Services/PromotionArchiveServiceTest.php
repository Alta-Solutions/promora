<?php

use PHPUnit\Framework\TestCase;
use App\Services\PromotionArchiveService;

class PromotionArchiveServiceTest extends TestCase {
    public function testRecordAppliedItemsIsIdempotentForOpenInterval(): void {
        $db = new PromotionArchiveServiceFakeDb();
        $service = new PromotionArchiveService($db, 'store_123');

        $db->now = '2026-06-01 10:00:00';
        $service->recordAppliedItems(
            ['id' => 7, 'name' => 'Summer', 'discount_percent' => 15],
            [[
                'product_id' => 101,
                'variant_id' => null,
                'product_name' => 'Hat',
                'sku' => 'HAT-1',
                'original_price' => 100,
                'promo_price' => 85,
            ]]
        );

        $db->now = '2026-06-01 11:00:00';
        $service->recordAppliedItems(
            ['id' => 7, 'name' => 'Summer', 'discount_percent' => 15],
            [[
                'product_id' => 101,
                'variant_id' => null,
                'product_name' => 'Hat',
                'sku' => 'HAT-1',
                'original_price' => 100,
                'promo_price' => 85,
            ]]
        );

        $this->assertCount(1, $db->history);
        $row = array_values($db->history)[0];
        $this->assertSame('2026-06-01 10:00:00', $row['applied_at']);
        $this->assertSame('2026-06-01 11:00:00', $row['last_seen_at']);
        $this->assertNull($row['removed_at']);
    }

    public function testRemovedItemCanBeReappliedAsNewInterval(): void {
        $db = new PromotionArchiveServiceFakeDb();
        $service = new PromotionArchiveService($db, 'store_123');

        $db->now = '2026-06-01 10:00:00';
        $service->recordAppliedItems(['id' => 7, 'discount_percent' => 20], [[
            'product_id' => 101,
            'variant_id' => 55,
            'product_name' => 'Hat Blue',
            'sku' => 'HAT-BLUE',
            'original_price' => 100,
        ]]);

        $service->recordRemovedItems(7, [[
            'product_id' => 101,
            'variant_id' => 55,
        ]], 'no_longer_applicable', '2026-06-02 09:00:00');

        $db->now = '2026-06-03 08:00:00';
        $service->recordAppliedItems(['id' => 7, 'discount_percent' => 20], [[
            'product_id' => 101,
            'variant_id' => 55,
            'product_name' => 'Hat Blue',
            'sku' => 'HAT-BLUE',
            'original_price' => 100,
        ]]);

        $this->assertCount(2, $db->history);
        $rows = array_values($db->history);
        $this->assertSame('2026-06-02 09:00:00', $rows[0]['removed_at']);
        $this->assertSame('no_longer_applicable', $rows[0]['removal_reason']);
        $this->assertNull($rows[1]['removed_at']);
        $this->assertSame('2026-06-03 08:00:00', $rows[1]['applied_at']);
    }

    public function testFinalizeArchiveBackfillsCurrentPromotionProducts(): void {
        $db = new PromotionArchiveServiceFakeDb();
        $db->promotions[7] = [
            'id' => 7,
            'name' => 'Summer',
            'custom_field_value' => 'Summer Sale',
            'discount_percent' => '15.00',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-08',
            'priority' => 5,
            'filters' => json_encode(['sku:in' => ['HAT-1']]),
            'status' => 'expired',
            'color' => '#3b82f6',
            'description' => 'Seasonal sale',
        ];
        $db->currentItems[7] = [[
            'promotion_id' => 7,
            'product_id' => 101,
            'variant_id' => null,
            'product_name' => 'Hat',
            'sku' => 'HAT-1',
            'original_price' => 100,
            'promo_price' => 85,
            'first_applied_at' => '2026-06-01 10:00:00',
            'synced_at' => '2026-06-01 10:00:00',
        ]];

        $service = new PromotionArchiveService($db, 'store_123');
        $archiveId = $service->finalizeArchive(7, 'expired_cleanup', '2026-06-09 12:00:00');

        $this->assertSame(1, $archiveId);
        $this->assertSame(1, $db->archives[1]['product_count']);
        $this->assertSame('complete', $db->archives[1]['backfill_status']);
        $this->assertStringContainsString('SKUs: HAT-1', $db->archives[1]['filters_text']);
        $this->assertSame(1, array_values($db->history)[0]['archive_id']);
    }

    public function testBackfillMarksOldArchivePartialWhenProductsAreGone(): void {
        $db = new PromotionArchiveServiceFakeDb();
        $db->promotions[9] = [
            'id' => 9,
            'name' => 'Old Sale',
            'custom_field_value' => 'Old Sale',
            'discount_percent' => '10.00',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-08',
            'priority' => 1,
            'filters' => '{}',
            'status' => 'expired',
            'color' => '#3b82f6',
            'description' => '',
        ];
        $db->backfillRows = [['id' => 9]];

        $service = new PromotionArchiveService($db, 'store_123');

        $this->assertSame(1, $service->backfillExistingExpiredPromotions());
        $this->assertSame(0, $db->archives[1]['product_count']);
        $this->assertSame('partial', $db->archives[1]['backfill_status']);
    }
}

class PromotionArchiveServiceFakeDb {
    public string $now = '2026-06-01 00:00:00';
    public array $queries = [];
    public array $history = [];
    public array $archives = [];
    public array $promotions = [];
    public array $currentItems = [];
    public array $backfillRows = [];
    private int $nextHistoryId = 1;
    private int $nextArchiveId = 1;

    public function getStoreContext(): string {
        return 'store_123';
    }

    public function query($sql, $params = []) {
        $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));
        $this->queries[] = ['sql' => $normalizedSql, 'params' => $params];

        if (strpos($normalizedSql, 'CREATE TABLE IF NOT EXISTS') === 0) {
            return true;
        }

        if (strpos($normalizedSql, 'INSERT INTO promotion_product_history') === 0) {
            $this->insertHistory($params);
            return true;
        }

        if (strpos($normalizedSql, 'UPDATE promotion_product_history SET archive_id = COALESCE') === 0) {
            $this->updateOpenHistory($params);
            return true;
        }

        if (strpos($normalizedSql, 'UPDATE promotion_product_history SET removed_at') === 0) {
            $id = (int)$params[4];
            $this->history[$id]['removed_at'] = $params[0];
            $this->history[$id]['removal_reason'] = $params[1];
            $this->history[$id]['last_seen_at'] = max($this->history[$id]['last_seen_at'], $params[2]);
            return true;
        }

        if (strpos($normalizedSql, 'INSERT INTO promotion_archives') === 0) {
            $this->upsertArchive($params);
            return true;
        }

        if (strpos($normalizedSql, 'UPDATE promotion_product_history SET archive_id = ?') === 0) {
            foreach ($this->history as &$row) {
                if ($row['store_hash'] === $params[1] && $row['promotion_id'] === (int)$params[2] && $row['archive_id'] === null) {
                    $row['archive_id'] = (int)$params[0];
                }
            }
            unset($row);
            return true;
        }

        if (strpos($normalizedSql, 'UPDATE promotion_archives SET product_count') === 0) {
            $id = (int)$params[3];
            $this->archives[$id]['product_count'] = (int)$params[0];
            $this->archives[$id]['backfill_status'] = $params[1];
            return true;
        }

        if (strpos($normalizedSql, 'UPDATE promotion_archives SET cleanup_completed_at') === 0) {
            $id = (int)$params[2];
            $this->archives[$id]['cleanup_completed_at'] = $this->archives[$id]['cleanup_completed_at'] ?? $params[0];
            return true;
        }

        return true;
    }

    public function fetchOne($sql, $params = []) {
        $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

        if (strpos($normalizedSql, 'SELECT NOW() AS current_time') === 0) {
            return ['current_time' => $this->now];
        }

        if (strpos($normalizedSql, 'SELECT id FROM promotion_product_history') === 0) {
            return $this->findOpenHistoryRow((int)$params[1], (int)$params[2], $params[3]);
        }

        if (strpos($normalizedSql, 'SELECT * FROM promotions') === 0) {
            return $this->promotions[(int)$params[1]] ?? false;
        }

        if (strpos($normalizedSql, 'SELECT id FROM promotion_archives') === 0) {
            foreach ($this->archives as $archive) {
                if ($archive['store_hash'] === $params[0] && $archive['promotion_id'] === (int)$params[1]) {
                    return ['id' => $archive['id']];
                }
            }
            return false;
        }

        if (strpos($normalizedSql, 'SELECT COUNT(DISTINCT') === 0) {
            $seen = [];
            foreach ($this->history as $row) {
                if ($row['store_hash'] === $params[0] && $row['promotion_id'] === (int)$params[1]) {
                    $seen[$row['product_id'] . ':' . ($row['variant_id'] ?? 'parent')] = true;
                }
            }
            return ['cnt' => count($seen)];
        }

        if (strpos($normalizedSql, 'SELECT 1 FROM promotion_product_history') === 0) {
            foreach ($this->history as $row) {
                if ($row['store_hash'] === $params[0] && $row['promotion_id'] === (int)$params[1]) {
                    return ['1' => 1];
                }
            }
            return false;
        }

        return false;
    }

    public function fetchAll($sql, $params = []): array {
        $normalizedSql = preg_replace('/\s+/', ' ', trim($sql));

        if (strpos($normalizedSql, 'SELECT pp.id') === 0) {
            return $this->currentItems[(int)$params[1]] ?? [];
        }

        if (strpos($normalizedSql, 'SELECT p.id FROM promotions p') === 0) {
            return $this->backfillRows;
        }

        return [];
    }

    private function insertHistory(array $params): void {
        $id = $this->nextHistoryId++;
        $this->history[$id] = [
            'id' => $id,
            'store_hash' => $params[0],
            'promotion_id' => (int)$params[1],
            'archive_id' => $params[2] !== null ? (int)$params[2] : null,
            'product_id' => (int)$params[3],
            'variant_id' => $params[4] !== null ? (int)$params[4] : null,
            'cache_id' => $params[5],
            'product_name' => $params[6],
            'sku' => $params[7],
            'type' => $params[8],
            'original_price' => $params[9],
            'promo_price' => $params[10],
            'discount_percent' => $params[11],
            'custom_field_id' => $params[12],
            'first_applied_at' => $params[13],
            'omnibus_reference_at' => $params[14],
            'applied_at' => $params[15],
            'last_seen_at' => $params[16],
            'removed_at' => $params[17],
            'removal_reason' => $params[18],
        ];
    }

    private function updateOpenHistory(array $params): void {
        $id = (int)$params[13];
        $row = &$this->history[$id];
        $row['archive_id'] = $row['archive_id'] ?? ($params[0] !== null ? (int)$params[0] : null);
        $row['cache_id'] = $params[1] ?? $row['cache_id'];
        $row['product_name'] = $params[2] ?? $row['product_name'];
        $row['sku'] = $params[3] ?? $row['sku'];
        $row['type'] = $params[4] ?? $row['type'];
        $row['original_price'] = $params[5] ?? $row['original_price'];
        $row['promo_price'] = $params[6] ?? $row['promo_price'];
        $row['discount_percent'] = $params[7] ?? $row['discount_percent'];
        $row['custom_field_id'] = $params[8] ?? $row['custom_field_id'];
        $row['first_applied_at'] = $row['first_applied_at'] ?? $params[9];
        $row['omnibus_reference_at'] = $row['omnibus_reference_at'] ?? $params[10];
        $row['last_seen_at'] = max($row['last_seen_at'], $params[11]);
        unset($row);
    }

    private function upsertArchive(array $params): void {
        foreach ($this->archives as &$archive) {
            if ($archive['store_hash'] === $params[0] && $archive['promotion_id'] === (int)$params[1]) {
                $archive['name'] = $params[2];
                $archive['filters_text'] = $params[9];
                return;
            }
        }
        unset($archive);

        $id = $this->nextArchiveId++;
        $this->archives[$id] = [
            'id' => $id,
            'store_hash' => $params[0],
            'promotion_id' => (int)$params[1],
            'name' => $params[2],
            'custom_field_value' => $params[3],
            'discount_percent' => $params[4],
            'start_date' => $params[5],
            'end_date' => $params[6],
            'priority' => $params[7],
            'filters' => $params[8],
            'filters_text' => $params[9],
            'status_at_archive' => $params[10],
            'color' => $params[11],
            'description' => $params[12],
            'archive_reason' => $params[13],
            'archived_at' => $params[14],
            'cleanup_completed_at' => null,
            'product_count' => 0,
            'backfill_status' => 'complete',
        ];
    }

    private function findOpenHistoryRow(int $promotionId, int $productId, $variantId) {
        $variantId = $variantId !== null && $variantId !== '' ? (int)$variantId : null;
        foreach ($this->history as $row) {
            if (
                $row['promotion_id'] === $promotionId
                && $row['product_id'] === $productId
                && $row['variant_id'] === $variantId
                && $row['removed_at'] === null
            ) {
                return ['id' => $row['id']];
            }
        }

        return false;
    }
}
