# Workflows

## Promotion Creation

Route: `?route=promotions&action=create`

Main files:

- `app/Controllers/PromotionController.php`
- `app/Services/PromotionService.php`
- `app/Views/promotions/create.php`

Flow:

1. Controller validates CSRF and a one-time create submission token.
2. Controller parses form data and JSON filters.
3. `PromotionService::createPromotion()` validates discount and determines status.
4. Promotion row is inserted into `promotions`.
5. If status is `active`, sync jobs are queued.
6. User is redirected back to promotion list.

Duplicate-submit protection is intentionally both client-side and server-side.
Do not remove the server-side token when changing the loading UI.

## Active Promotion Correction

Route: `?route=promotions&action=edit&id={promotion_id}`

An active promotion can be edited in `active_discount_correction` mode when an
incorrect discount percentage must be fixed without starting a new Omnibus
lifecycle. The controller takes the actor identity from the verified
BigCommerce session. The service requires a reason, writes `promotion_revisions`,
preserves the lifecycle only for existing `promotion_products` rows, and queues
the regular promotion sync. New filter matches still pass normal Omnibus
validation. The last 100 audited corrections for the selected store are visible
under `?route=logs&action=corrections`.

## Promotion Preview

Route: `?route=promotions&action=preview`

The create/edit views send filters, discount percent, and start date via AJAX.
`PromotionService::previewPromotionProducts()` calculates candidate products and
Omnibus validation state without writing BigCommerce data.

## Promotion Sync

Queued promotion sync is processed by `bin/worker.php`.

Core service method:

- `PromotionService::syncSinglePromotionBatch()`

The sync flow calculates best promotion candidates, updates BigCommerce prices
and custom fields, updates `products_cache`, logs effective price changes, and
records rows in `promotion_products`.

When a queued promotion sync, manual single-promotion resync, or cleanup changes
`sale_price`, the worker queues `omnibus_sync_products` with only the affected
parent product IDs. Promotion and cleanup jobs are processed before Omnibus jobs
so the targeted Omnibus sync sees the updated local price cache.

## Cleanup

Cleanup jobs restore prices/custom fields for products no longer covered by a
promotion. Cleanup can be global (`cleanup`) or promotion-specific
(`cleanup_single`).

## Product Cache Sync

Main service: `ProductCacheService`

`fullSync()` fetches products from BigCommerce with variants, images, and custom
fields. `batchCacheProducts()` writes parent and variant rows to `products_cache`,
updates the custom field filter index, seeds initial Omnibus history, and logs
current effective prices when Omnibus is enabled.

`updatePriceCacheDirectly()` updates local cache after app-originated price
writes so the app does not need read-after-write API calls.

## Omnibus Sync

Manual Omnibus sync creates an `omnibus_sync` job. The worker runs
`OmnibusSyncService::processBatch()` over parent products from `products_cache`.
Targeted jobs use `omnibus_sync_products` and pass product IDs from the job
payload to the same service method.

The service calculates product and variant reference prices, writes the canonical
`promora.lowest_price_30d` BigCommerce metafields through
`OmnibusMetafieldService`, and updates the legacy `lowest_price_30d` product
custom field only as a storefront migration fallback. Promotion application does
not read the legacy custom field.

See `docs/omnibus.md` before changing this flow.

## Webhooks

`WebhookService` handles BigCommerce product and inventory webhooks. Product
webhook requests are acknowledged quickly after validation, persisted to
`webhook_events`, and queued as `webhook_event` jobs. The worker then refreshes
local cache and re-evaluates promotions for the product. The suppression table
prevents app-originated API writes from immediately triggering recursive
processing.

`bin/webhook_monitor.php` is the CLI health monitor for registered BigCommerce
webhooks. It checks active stores, reconciles the expected product webhook
scopes, recreates missing hooks, and reactivates inactive hooks only after the
receiver health check passes. Use `--dry-run` to inspect changes without calling
BigCommerce write endpoints.

## Queue Worker

Run only against the intended database:

```powershell
php bin/worker.php
```

The worker processes pending jobs until it runs out of jobs or reaches its
execution time limit. It mutates database state and can call BigCommerce write
APIs through services.
