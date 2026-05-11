# Quick Product Cache Sync Specification

## Context

`CacheController::quickSync()` currently exposes `?route=cache&action=quickSync`.
It returns JSON and calls `ProductCacheService::syncModifiedProducts()`.

Current behavior:

- Fetches BigCommerce products modified in the last 24 hours using `date_modified:min`.
- Includes variants, images, and custom fields.
- Writes results into `products_cache` via `batchCacheProducts()`.
- Refreshes the custom-field filter index.
- Logs product and variant prices into `product_price_history` when Omnibus is enabled.
- Returns `{ success: true, updated: <count> }`.

This means the method is not dead code, but it is a narrow incremental product
cache refresh. It is not the same as syncing active promotions.

## Problems To Fix

1. The sync window is hardcoded to the previous 24 hours.
   If the action is not run for more than 24 hours, changed products can be
   missed.

2. The endpoint mutates local database state through a GET request.
   It should be POST-only and CSRF protected.

3. UI naming is ambiguous.
   `app/Views/settings/index.php` has a JavaScript `quickSync()` that calls the
   cache endpoint, while `app/Views/layouts/sidebar.php` also defines
   `quickSync()` but calls `?route=api&action=sync_all`, which queues promotion
   sync jobs.

4. The response only returns a single count.
   It does not tell the user which window was used, whether products were
   fetched from multiple pages, or whether any products failed to cache.

5. Deleted products are not cleaned from `products_cache`.
   BigCommerce `date_modified:min` will not necessarily surface deleted
   products, so stale local rows can remain.

6. The method name suggests a broad sync.
   The actual behavior is specifically "refresh recently modified products in
   local cache".

## Target Behavior

Implement a reliable incremental product cache sync with explicit semantics:

- The action refreshes products changed since the last successful product cache
  sync, with a small overlap window.
- The action updates local cache only. It does not apply promotions directly.
- If the cache refresh changes prices and Omnibus is enabled, price history is
  updated through the existing `batchCacheProducts()` behavior.
- The action is triggered with POST and CSRF validation.
- UI labels clearly separate:
  - Product cache sync
  - Promotion sync
  - Omnibus sync

## Sync Window Rules

Use a persisted timestamp instead of a fixed 24-hour window.

Recommended source:

- Add a store-scoped setting/metadata row for `product_cache_last_quick_sync_at`,
  or reuse an existing store settings mechanism if one already exists.

Window calculation:

- If `product_cache_last_quick_sync_at` exists, use it minus a safety overlap.
- If it does not exist, fall back to the last `MAX(cached_at)` from
  `products_cache` for the current `store_hash`.
- If neither exists, use a conservative fallback such as `now - 24 hours`, or
  force the user to run full cache sync first.

Recommended overlap:

- 5 to 10 minutes.
- Purpose: avoid missing products around clock drift, paging delay, and API
  timestamp precision.

Only update `product_cache_last_quick_sync_at` after the full operation succeeds.
Store the completion time, not the start time, unless the implementation also
records the requested window.

## Controller Contract

Change `CacheController::quickSync()` to:

- Accept only POST.
- Validate CSRF token.
- Return HTTP 403 for invalid CSRF.
- Return HTTP 405 for non-POST requests, or a JSON error with an appropriate
  status code if the app currently prefers JSON-only responses.
- Return structured JSON:

```json
{
  "success": true,
  "updated": 42,
  "window_start": "2026-05-11 19:05:00",
  "window_end": "2026-05-11 20:15:00",
  "pages_fetched": 1,
  "errors": 0
}
```

For failures:

```json
{
  "success": false,
  "error": "Human-readable failure"
}
```

## Service Contract

Refactor `ProductCacheService::syncModifiedProducts()` so it accepts an optional
window start:

```php
public function syncModifiedProducts(?\DateTimeInterface $since = null): array
```

Return a result array instead of only a count:

```php
[
    'updated' => 42,
    'window_start' => '2026-05-11 19:05:00',
    'window_end' => '2026-05-11 20:15:00',
    'pages_fetched' => 1,
    'errors' => 0,
]
```

The controller can keep backward-compatible response shape by exposing
`updated`, but the service should carry enough detail for logs and UI.

Implementation notes:

- Continue using `BigCommerceAPI::getProducts()` because it already paginates.
- Keep `include=variants,images,custom_fields`.
- Use existing `batchCacheProducts()` to avoid duplicating cache, index, and
  Omnibus price-history logic.
- Do not write BigCommerce prices or custom fields from this flow.

## UI Changes

Settings page:

- Rename the JavaScript function to something explicit, for example
  `quickProductCacheSync()`.
- Call `?route=cache&action=quickSync` with POST and CSRF token.
- Show a message that includes the updated product count and window start.

Sidebar:

- Rename the current `quickSync()` to something like `syncAllPromotions()`.
- Keep it calling `?route=api&action=sync_all` unless promotion sync behavior is
  intentionally changed.

Avoid having global functions with the same name but different behavior.

## Deletions And Stale Rows

Do not solve deleted-product cleanup inside the first quick-sync change unless
needed immediately.

Recommended follow-up:

- Add a separate stale cache cleanup job.
- Possible strategies:
  - Periodic full product id reconciliation.
  - Webhook-driven cleanup for product deletion events.
  - Mark-and-sweep after full sync.

Quick sync should remain focused on modified products.

## Security

- `quickSync` must require CSRF validation.
- Use POST for state-changing actions.
- Keep tenant isolation through the current `store_hash` context.
- Do not allow a request to specify arbitrary `store_hash`.

## Verification Plan

Syntax checks:

```powershell
php -l app/Controllers/CacheController.php
php -l app/Services/ProductCacheService.php
php -l app/Views/settings/index.php
php -l app/Views/layouts/sidebar.php
```

Focused tests to add or update:

- `CacheControllerQuickSyncTest`
  - rejects non-POST or invalid CSRF
  - returns structured JSON on success
  - returns JSON error on service exception

- `ProductCacheServiceQuickSyncTest`
  - uses persisted last-success timestamp when present
  - falls back safely when no previous sync timestamp exists
  - applies overlap to the calculated window
  - only updates the last-success timestamp after successful cache write
  - does not update the timestamp when BigCommerce/API/cache write fails

Manual verification:

1. Run Product Cache quick sync from settings.
2. Confirm JSON/UI shows updated count and window.
3. Confirm `products_cache.cached_at` changes for modified products.
4. Confirm `product_price_history` receives effective price rows when Omnibus is
   enabled and price changed.
5. Confirm sidebar promotion sync still queues promotion jobs and does not call
   the product cache endpoint.

## Non-Goals

- Do not merge quick product cache sync with promotion sync.
- Do not make this action write BigCommerce prices or custom fields.
- Do not replace full cache sync.
- Do not implement stale deleted-product cleanup in the same change unless it is
  explicitly prioritized.
