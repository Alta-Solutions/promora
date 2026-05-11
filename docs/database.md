# Database Notes

The schema is bootstrapped in `app/install/install.php`. Some services also run
schema guards for backwards compatibility.

## Tenant Rule

Most tables are tenant-scoped by `store_hash`. New queries should include
`store_hash` unless the table is global or the query is intentionally cross-store.

## Core Tables

### `bigcommerce_stores`

Stores BigCommerce credentials and app settings.

Key fields:

- `store_hash`
- `access_token`
- `context`
- `enable_omnibus`
- `currency`
- `settings`

### `products_cache`

Local product and variant cache used by filtering, previews, sync, and Omnibus.

Key fields:

- `store_hash`
- `type`: `product` or `variant`
- `product_id`
- `variant_id`
- `price`
- `sale_price`
- `custom_fields`
- `images`
- `cached_at`

Use `variant_id IS NULL` for parent product rows. Use `variant_id <=> ?` in MySQL
when matching nullable variant IDs.

### `product_custom_field_index`

Search/filter index derived from cached custom fields. Keep it synchronized when
changing cache writes.

### `promotions`

Promotion definitions.

Key fields:

- `store_hash`
- `name`
- `custom_field_value`
- `discount_percent`
- `start_date`
- `end_date`
- `priority`
- `filters`
- `status`
- `color`
- `description`

### `promotion_products`

Tracks products currently affected by a promotion. It has a unique key across
`store_hash`, `product_id`, and `variant_id`, so only one promotion can own a
given product/variant at a time.

### `sync_jobs`

Queue table consumed by `bin/worker.php`.

Common `job_type` values:

- `sync_promotion`
- `cleanup`
- `cleanup_single`
- `omnibus_sync`

### `sync_log`

Operational log for sync and worker results.

### `product_price_history`

Omnibus price history.

Key fields:

- `store_hash`
- `product_id`
- `variant_id`
- `price`
- `currency`
- `recorded_at`

The lookup index includes `store_hash`, `product_id`, `variant_id`, `currency`,
and `recorded_at`.

### `webhooks`, `webhook_events`, `webhook_suppressions`

Webhook registration, received event audit, and short-lived suppression markers
used to avoid loops after app-originated BigCommerce writes.

## Migration Caution

This project does not use a full migration framework. Schema changes are usually
implemented in `app/install/install.php` and sometimes guarded in services. If
adding columns or indexes, keep old installations in mind and add compatibility
checks where needed.
