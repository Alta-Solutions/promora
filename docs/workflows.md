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

## Promotion Application Correction

Route: `?route=corrections`

This is an operational Promotions tool, linked from the Promotions list, for
voiding a product or variant that was incorrectly included in an active
promotion. It is not a Logs action because applying it writes product pricing
state.

Main files:

- `app/Controllers/CorrectionController.php`
- `app/Services/PromotionApplicationCorrectionService.php`
- `app/Services/PromotionService.php`
- `app/Views/corrections/index.php`

Flow:

1. User opens Application Corrections from `?route=promotions`.
2. User enters a SKU, and optionally a promotion ID.
3. Preview validates the store-scoped SKU, current active `promotion_products`
   row, affected price-history rows, and post-correction result.
4. Apply requires CSRF, a one-time preview token, a reason, and explicit
   confirmation that ignoring the wrong sale price for Omnibus is approved.
5. `PromotionService::voidPromotionProductAndReconcile()` excludes the wrong
   promotion for the product or variant, re-evaluates remaining active
   promotions, and either applies the best replacement promotion or restores the
   regular price.
6. After a successful BigCommerce write, the correction audit is marked applied,
   matching `product_price_history` rows are marked ignored, the exclusion is
   stored, and a targeted `omnibus_sync_products` job is queued.

Recent application corrections are shown on the same page for operator context.
The Logs section intentionally does not link to this workflow; it remains for
read-only sync, webhook, promotion correction, and archive diagnostics.

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

Before a finished promotion is cleaned up, `PromotionArchiveService` creates an
idempotent archive header in `promotion_archives` and links product interval
history in `promotion_product_history`. Successful promotion applications open
or refresh product history intervals; cleanup, product reconciliation, and
promotion replacement close those intervals before rows are removed from
`promotion_products`.

The archive is searchable from Logs > Promotion Archive. Backfill for promotions
that expired before this feature is best-effort: promotion definitions are
archived, but product rows are only available if they still exist locally or were
recorded after this feature was installed.

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

Scheduled Omnibus sync uses `OmnibusSyncSchedulerService`. During the day it
creates `omnibus_sync_products` only for dirty parent products: rows touched in
`products_cache` today, products with today's `product_price_history` rows, and
ignored price-history rows when the `ignored_at` column exists. Once per day,
at `OMNIBUS_FULL_SYNC_HOUR` or the first scheduler run after it, the scheduler
creates a full `omnibus_sync` job instead. The default full-sync hour is `2`.

The service calculates product and variant reference prices, writes the canonical
`promora.lowest_price_30d` BigCommerce metafields through
`OmnibusMetafieldService`, and updates the legacy `lowest_price_30d` product
custom field only as a storefront migration fallback. Promotion application does
not read the legacy custom field.

See `docs/omnibus.md` before changing this flow.

## Webhooks

`WebhookService` handles BigCommerce product and inventory webhooks. Product
webhook requests are acknowledged quickly after validation, persisted to
`webhook_events`, and queued as `webhook_event` jobs. Pending webhook event jobs
for the same store are merged into batches, while each `webhook_events` row
remains available for audit. The worker refreshes local cache and re-evaluates
promotions for each event, but processes webhook batches in bounded passes so
targeted `omnibus_sync_products` jobs are not blocked by a webhook backlog. The
default batch limit is `WEBHOOK_EVENT_BATCH_LIMIT=25`. The suppression table
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
