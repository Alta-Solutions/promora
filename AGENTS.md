# Agent Instructions

This repository is a PHP BigCommerce promotion manager. It is multi-store by
design: most reads and writes must be scoped by `store_hash`.

## Read First

- `docs/architecture.md` for the app layout and request flow.
- `docs/workflows.md` for promotion sync, product cache sync, queue worker, and webhooks.
- `docs/omnibus.md` before changing lowest-price or price-history logic.
- `docs/database.md` before changing SQL or schema-sensitive code.
- `docs/testing.md` before running tests or scripts.

## Project Shape

- Entry point and router: `index.php`.
- Controllers: `app/Controllers`.
- Service/business logic: `app/Services`.
- Models and DB access: `app/Models`.
- Views: `app/Views`.
- Public assets: `public`.
- Queue worker: `bin/worker.php`.
- Install/schema bootstrap: `app/install/install.php`.

## Development Rules

- Preserve tenant isolation. Always check `store_hash` handling before changing DB queries.
- Prefer existing services over direct API or SQL additions.
- Do not call live BigCommerce write operations unless the user explicitly asks for it.
- Do not run integration scripts that touch real stores/products unless the user explicitly approves the target.
- Keep edits scoped. Avoid unrelated formatting churn, especially in large PHP views.
- Do not commit, push, or merge unless the user explicitly asks.
- When changing frontend views, keep interactions usable without adding marketing-style UI.

## Safe Verification

Run syntax checks for touched PHP files:

```powershell
php -l app/Controllers/PromotionController.php
php -l app/Services/PromotionService.php
```

Run focused PHPUnit tests:

```powershell
vendor\bin\phpunit.bat app\Services\PromotionServiceOmnibusValidationTest.php
vendor\bin\phpunit.bat app\Services\OmnibusSyncServiceTest.php
vendor\bin\phpunit.bat app\Services\PriceLoggerTest.php
vendor\bin\phpunit.bat app\Controllers\PromotionControllerSubmissionTokenTest.php
```

Run whitespace checks before committing:

```powershell
git diff --check
```

## Risk Notes

- `bin/test_omnibus.php` can interact with a configured real store if remote sync is enabled.
- `bin/worker.php` processes real queued jobs from the configured database.
- `ProductCacheService::fullSync()` and cache actions fetch and mutate local cache for a real store.
- Promotion sync can write BigCommerce prices/custom fields.
- Omnibus sync writes the `lowest_price_30d` custom field on BigCommerce products.
