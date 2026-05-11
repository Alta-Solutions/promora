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

The service aggregates reference prices across variants when needed and delegates
BigCommerce custom field writes to `OmnibusFieldService`.

See `docs/omnibus.md` before changing this flow.

## Webhooks

`WebhookService` handles BigCommerce product and inventory webhooks. Product
updates refresh local cache and re-evaluate promotions for that product. The
suppression table prevents app-originated API writes from immediately triggering
recursive processing.

## Queue Worker

Run only against the intended database:

```powershell
php bin/worker.php
```

The worker processes pending jobs until it runs out of jobs or reaches its
execution time limit. It mutates database state and can call BigCommerce write
APIs through services.
