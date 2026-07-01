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
- `user_id`
- `user_email`
- `enable_omnibus`
- `currency`
- `settings`
- `updated_at`

### `products_cache`

Local product and variant cache used by filtering, previews, sync, and Omnibus.

Key fields:

- `store_hash`
- `type`: `product` or `variant`
- `product_id`
- `variant_id`
- `price`
- `sale_price`
- `cost_price`
- `tax_class_id`
- `tax_rate`
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

Lifecycle fields:

- `first_applied_at`: first application time for the current owning promotion.
- `omnibus_reference_at`: locked Omnibus lifecycle reference for the current
  owning promotion. It is preserved across retries and audited corrections, and
  reset when another promotion takes ownership.

### `promotion_product_exclusions`

Store-scoped exclusion list used after an application correction. It prevents
the same promotion filters from re-selecting a product or variant that was
voided because it was incorrectly included.

Key fields:

- `store_hash`
- `promotion_id`
- `product_id`
- `variant_id`
- `correction_id`
- `reason`
- `created_at`

The unique key is scoped to `store_hash`, `promotion_id`, `product_id`, and
`variant_id`.

### `promotion_revisions`

Audit log for controlled edits that preserve an active promotion lifecycle.
Current rows use `change_type = active_discount_correction`.

Key fields:

- `store_hash`
- `promotion_id`
- `reason`
- `actor_source`
- `actor_user_id`
- `actor_email`
- `actor_is_owner`
- `old_discount_percent`
- `new_discount_percent`
- `old_terms`
- `new_terms`
- `created_at`

### `promotion_application_corrections`

Audit table for SKU-level void corrections where a product or variant was
incorrectly included in an active promotion.

Key fields:

- `store_hash`
- `promotion_id`
- `product_id`
- `variant_id`
- `sku_snapshot`
- `operation`
- `status`
- `reason`
- `visibility_confirmed`
- `actor_source`
- `actor_user_id`
- `actor_email`
- `before_state`
- `after_state`
- `ignored_history_row_ids`
- `replacement_promotion_id`
- `error_message`
- `created_at`
- `applied_at`

Rows are inserted before the high-risk write as `pending`, then marked
`applied` only after the BigCommerce price/custom-field update succeeds. Failed
writes are recorded without adding an exclusion or ignoring price-history rows.

### `promotion_archives`

Immutable header snapshot for promotions that have finished or are manually
deleted after being active. It is tenant-scoped and keyed by `store_hash` plus
`promotion_id`.

Key fields:

- `store_hash`
- `promotion_id`
- `name`
- `discount_percent`
- `start_date`
- `end_date`
- `filters`
- `filters_text`
- `archived_at`
- `cleanup_completed_at`
- `product_count`
- `backfill_status`

### `promotion_product_history`

Searchable interval history for products and variants that were successfully
covered by a promotion. Unlike `promotion_products`, rows are not deleted during
cleanup; they are closed with `removed_at` and `removal_reason`.

Key fields:

- `store_hash`
- `promotion_id`
- `archive_id`
- `product_id`
- `variant_id`
- `product_name`
- `sku`
- `original_price`
- `promo_price`
- `discount_percent`
- `applied_at`
- `last_seen_at`
- `removed_at`
- `removal_reason`

### `sync_jobs`

Queue table consumed by `bin/worker.php`.

Common `job_type` values:

- `webhook_event`
- `sync_promotion`
- `single_sync`
- `cleanup`
- `cleanup_single`
- `omnibus_sync`
- `omnibus_sync_products`

`payload` is nullable JSON text used by targeted jobs. For `webhook_event`, it
may contain a legacy `webhook_event_id` value and a batched `webhook_event_ids`
list. For `omnibus_sync_products`, it contains normalized parent product IDs and
source metadata, for example:

```json
{"product_ids":[10625,11839],"source":"sync_promotion","promotion_id":113,"source_job_id":9775}
```

Scheduler-created targeted Omnibus jobs use `source = scheduler_incremental`
and may include `day_start`, `cache_dirty_count`, `history_dirty_count`,
`ignored_history_dirty_count`, and `total_dirty_count`.

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
- `ignored_at`
- `ignored_reason`
- `ignored_by_correction_id`

The lookup index includes `store_hash`, `product_id`, `variant_id`, `currency`,
and `recorded_at`.

Omnibus queries must filter out ignored rows with `ignored_at IS NULL`.
Application corrections mark rows ignored instead of deleting them so the
original accidental sale price remains audit-visible.

### `webhooks`, `webhook_events`, `webhook_suppressions`

Webhook registration, received event audit, and short-lived suppression markers
used to avoid loops after app-originated BigCommerce writes.

`webhooks` also tracks monitor state for BigCommerce-side deactivation:

- `last_checked_at`
- `last_reactivated_at`
- `reactivation_attempts`
- `last_error`

## Migration Caution

This project does not use a full migration framework. Schema changes are usually
implemented in `app/install/install.php` and sometimes guarded in services. If
adding columns or indexes, keep old installations in mind and add compatibility
checks where needed.
