<?php
namespace App\Services;

use App\Models\Database;

final class PriceOwnershipService {
    public function __construct(private Database $db, private BigCommerceAPI $api, private string $store) {}

    public function locked(array $items, callable $work) {
        $ids = array_unique(array_column($items, 'product_id'));
        sort($ids, SORT_NUMERIC);
        $locks = [];
        try {
            foreach ($ids as $id) {
                $key = 'price:' . sha1($this->store . ':' . $id);
                if (empty($this->db->fetchOne('SELECT GET_LOCK(?,5) AS acquired', [$key])['acquired'])) {
                    throw new \RuntimeException("Product $id is busy");
                }
                $locks[] = $key;
            }
            return $work();
        } finally {
            foreach (array_reverse($locks) as $key) {
                $this->db->fetchOne('SELECT RELEASE_LOCK(?)', [$key]);
            }
        }
    }

    /** Check the current remote state before a price write, including recovery after a lost response. */
    public function prepare(array $item, bool $restore, bool $expiredOnly = false, bool $correcting = false): array {
        $pid = (int)$item['product_id'];
        $vid = (int)($item['variant_id'] ?? 0);
        $promotion = (int)$item['promotion_id'];
        if (!$restore && (!is_numeric($item['promo_price'] ?? null) || (float)$item['promo_price'] <= 0)) {
            throw new \InvalidArgumentException('A promotion must have a positive sale price.');
        }
        if (!$correcting && $this->db->fetchOne("SELECT id FROM promotion_application_corrections
            WHERE store_hash=? AND product_id=? AND variant_id <=> ? AND status='pending' LIMIT 1",
            [$this->store,$pid,$vid ?: null])) {
            return ['action' => 'skip'];
        }
        $owners = $this->db->fetchAll("SELECT * FROM promotion_products WHERE store_hash=? AND product_id=?
            AND variant_id <=> ? ORDER BY id DESC LIMIT 2", [$this->store, $pid, $vid ?: null]);
        $owner = $owners[0] ?? null;
        if ($restore && (!$owner || (int)$owner['promotion_id'] !== $promotion || (int)$owner['id'] !== (int)$item['id'])) {
            return ['action' => 'skip'];
        }
        $campaign = $this->db->fetchOne("SELECT created_at, (status='active' AND start_date<=NOW() AND end_date>=NOW()) AS active_now FROM promotions
            WHERE store_hash=? AND id=?", [$this->store, $promotion]);
        if (($expiredOnly && !empty($campaign['active_now'])) || (!$restore && empty($campaign['active_now']))) {
            return ['action' => 'skip'];
        }
        $keys = [$this->store, $pid, $vid, $promotion, (string)($campaign['created_at'] ?? 'legacy')];
        $decision = $this->db->fetchOne("SELECT * FROM price_conflicts WHERE store_hash=? AND product_id=?
            AND variant_key=? AND promotion_id=? AND lifecycle=?", $keys);
        if (($decision['decision'] ?? '') === 'keep') {
            return ['action' => 'conflict'];
        }

        $stable = !$restore && $owner && $owner['prior_price_known'] && (int)$owner['promotion_id'] === $promotion
            && self::price($owner['promo_price']) === self::price($item['promo_price'])
            && (!$decision || $decision['decision'] === 'resolved');
        if ($stable && !empty($owner['verified_at']) && array_key_exists('cached_sale_price',$item)
            && self::price($item['cached_sale_price']) === self::price($item['promo_price'])
            && strtotime($owner['verified_at'] . ' UTC') > time() - 900) {
            return ['action'=>'unchanged','previous_sale_price'=>$owner['previous_sale_price'],'target'=>$owner['promo_price'],'owner'=>$owner];
        }
        $resource = "catalog/products/$pid" . ($vid ? "/variants/$vid" : '');
        try {
            $remote = $this->api->call('GET', $resource)['body']['data'] ?? null;
        } catch (\Throwable $e) {
            if ($vid && $e->getCode() === 404) {
                (new QueueService($this->store))->queueVariantVerification($pid);
                return ['action' => 'skip'];
            }
            throw $e;
        }
        if (!is_array($remote) || !array_key_exists('sale_price', $remote)
            || ($remote['sale_price'] !== null && !is_numeric($remote['sale_price']))
            || ($vid && isset($remote['product_id']) && (int)$remote['product_id'] !== $pid)
            || (int)($remote['id'] ?? 0) !== ($vid ?: $pid)) {
            throw new \RuntimeException("Cannot verify current price for product $pid");
        }
        $seen = self::price($remote['sale_price']);
        $expected = self::price($owner['promo_price'] ?? null);
        $prior = self::price($owner['previous_sale_price'] ?? null);
        $wanted = $restore ? $prior : self::price($item['promo_price']);
        $approved = ($decision['decision'] ?? '') === 'apply' && self::price($decision['observed_price']) === $seen;
        $intent = $this->db->fetchOne('SELECT * FROM price_write_intents WHERE store_hash=? AND product_id=? AND variant_key=?',
            [$this->store, $pid, $vid]);
        $recovering = $intent && (int)$intent['promotion_id'] === $promotion
            && (bool)$intent['restoring'] === $restore && self::price($intent['target_price']) === $wanted;
        if ($recovering && $seen === $wanted && count($owners) <= 1) {
            return ['action' => 'unchanged', 'previous_sale_price' => $intent['prior_price'], 'target' => $wanted];
        }
        $unverified = $owner && (!$owner['prior_price_known'] || $owner['promo_price'] === null);
        $different = $owner ? $unverified || $seen !== $expected : $seen !== null;
        if (count($owners) > 1 || ($restore && $unverified) || ($different && !$approved)
            || (($decision['decision'] ?? '') === 'open' && !$approved)) {
            $this->db->query("INSERT INTO price_conflicts
                (store_hash,product_id,variant_key,promotion_id,lifecycle,expected_price,observed_price,allow_apply,updated_at)
                VALUES (?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE
                revision=revision+IF(NOT(observed_price <=> VALUES(observed_price)) OR decision='apply',1,0),
                decision='open', observed_price=VALUES(observed_price), expected_price=VALUES(expected_price), allow_apply=VALUES(allow_apply), updated_at=NOW()",
                [...$keys, $expected, $seen, (int)(count($owners) <= 1 && !($restore && $unverified))]);
            return ['action' => 'conflict'];
        }
        if ($stable && !$intent && $seen === $wanted) {
            $this->db->query('UPDATE promotion_products SET verified_at=UTC_TIMESTAMP(), synced_at=synced_at WHERE store_hash=? AND id=?',
                [$this->store,$owner['id']]);
            return ['action'=>'unchanged','previous_sale_price'=>$prior,'target'=>$wanted,'owner'=>$owner];
        }
        if ($approved || !$owner) {
            $prior = $seen;
        }
        $this->db->query("INSERT INTO price_write_intents
            (store_hash,product_id,variant_key,promotion_id,restoring,target_price,prior_price)
            VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE promotion_id=VALUES(promotion_id),
            restoring=VALUES(restoring), target_price=VALUES(target_price), prior_price=VALUES(prior_price)",
            [$this->store,$pid,$vid,$promotion,(int)$restore,$wanted,$prior]);
        return ['action' => $seen === $wanted ? 'unchanged' : 'write', 'previous_sale_price' => $prior, 'target' => $wanted];
    }

    public function confirmed(array $item): void {
        $ids = [$this->store, $item['product_id'], (int)($item['variant_id'] ?? 0), $item['promotion_id']];
        $this->db->query('UPDATE promotion_products SET verified_at=UTC_TIMESTAMP(), synced_at=synced_at WHERE store_hash=?
            AND product_id=? AND variant_id <=> ? AND promotion_id=?', [$this->store,$item['product_id'],$item['variant_id'] ?? null,$item['promotion_id']]);
        $this->db->query('DELETE FROM price_write_intents WHERE store_hash=? AND product_id=? AND variant_key=? AND promotion_id=?', $ids);
        $this->db->query("UPDATE price_conflicts SET decision='resolved' WHERE store_hash=? AND product_id=?
            AND variant_key=? AND promotion_id=? AND decision='apply'", $ids);
    }

    private static function price($value): ?string {
        return is_numeric($value) && (float)$value > 0 ? number_format((float)$value, 4, '.', '') : null;
    }
}
