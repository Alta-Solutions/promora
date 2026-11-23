<?php
namespace App\Services;

use App\Models\Database;

final class CachedCustomField {
    /** Update the single field and its filter entry together, preserving simultaneous changes to other fields. */
    public static function save(Database $db, int $product, string $name, ?array $field): void {
        $store = $db->getStoreContext();
        if (!$store || $name === '') {
            return;
        }
        $db->beginTransaction();
        try {
            $row = $db->fetchOne('SELECT custom_fields FROM products_cache WHERE store_hash=? AND product_id=?
                AND variant_id IS NULL FOR UPDATE',[$store,$product]);
            if ($row) {
                $fields = json_decode($row['custom_fields'] ?? '[]',true,512,JSON_THROW_ON_ERROR);
                $fields = array_values(array_filter($fields ?? [],static fn($entry) => ($entry['name'] ?? '') !== $name));
                if ($field !== null) {
                    $fields[] = ['id'=>(int)$field['id'],'name'=>$name,'value'=>(string)$field['value']];
                }
                $db->query('UPDATE products_cache SET custom_fields=? WHERE store_hash=? AND product_id=? AND variant_id IS NULL',
                    [json_encode($fields,JSON_THROW_ON_ERROR),$store,$product]);
                $db->query('DELETE FROM product_custom_field_index WHERE store_hash=? AND product_id=? AND field_name=?',
                    [$store,$product,$name]);
                if ($field !== null && $field['value'] !== '') {
                    $db->query('INSERT INTO product_custom_field_index(store_hash,product_id,field_name,field_value)
                        VALUES (?,?,?,?)', [$store,$product,$name,$field['value']]);
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
