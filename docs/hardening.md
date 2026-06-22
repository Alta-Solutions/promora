# Application Hardening Backlog

This document records pragmatic improvements for strengthening the current PHP
application without a full rewrite. The main goals are to reduce the risk of
wrong prices, cross-store data leakage, failed background jobs, and regressions
in Omnibus compliance logic.

## Highest-Risk Areas

- Tenant isolation: most reads and writes must remain scoped by `store_hash`.
- Promotion sync: queued jobs can write BigCommerce prices, custom fields, and
  metafields.
- Omnibus pricing: lowest-price calculations affect legal storefront display and
  promotion eligibility.
- Webhooks: product and inventory updates can trigger cache refreshes and
  promotion re-evaluation.
- Cleanup: finished or replaced promotions must restore state and archive
  product history correctly.

## Recommended Priorities

### 1. Expand Regression Tests

Add focused tests around behavior that is expensive to repair after production
drift:

- overlapping promotions and priority resolution
- promotion cleanup and price restoration
- product webhook refresh for products already covered by a promotion
- `store_hash` isolation in services and models
- BigCommerce API retry, rate-limit, and partial-failure cases
- archive finalization before promotion ownership rows are removed
- targeted Omnibus sync after promotion or cleanup price changes

Prefer small service-level tests with fake collaborators where possible. Avoid
tests that require a live BigCommerce store unless the target store is explicitly
approved.

### 2. Strengthen Tenant Guardrails

Cross-store leakage is one of the highest-impact failure modes. New database
work should either use a service/model that already applies store context or
include explicit `store_hash` predicates.

Useful improvements:

- add tests for services that must fail or no-op without a store context
- centralize common tenant-scoped query helpers where it reduces duplication
- inspect every new SQL query for `store_hash` handling during review
- make intentional cross-store queries rare and clearly documented inline

### 3. Improve Queue Worker Reliability

The worker mutates local state and can call BigCommerce write APIs. It should be
easy to understand which job changed what and whether retrying is safe.

Candidate improvements:

- explicit job lease or lock timeout for claimed jobs
- retry policy by error type, including non-retryable terminal failures
- dead-letter status for permanently failed jobs
- structured job context in logs: `job_id`, `store_hash`, `job_type`,
  `promotion_id`, `product_id`, and source job where available
- dry-run mode for high-risk sync flows that calculates intended changes without
  writing BigCommerce data

Queue library options:

- First harden the current custom queue if the goal is the lowest-risk
  improvement. The most important fix is an atomic claim path so two workers
  cannot process the same pending job. Prefer a transaction with row locking, or
  `SELECT ... FOR UPDATE SKIP LOCKED` where the production MySQL version
  supports it.
- If replacing the queue layer, prefer Symfony Messenger before introducing a
  full framework. It can be adopted in a standalone PHP app and supports
  standard retry/failure handling with Doctrine, Redis, AMQP/RabbitMQ, and other
  transports.
- Start with the Doctrine transport only if keeping MySQL as the queue backend
  is an operational requirement. Redis or RabbitMQ is a better long-term broker
  choice when multiple workers, clearer queue isolation, or higher throughput are
  needed.
- Laravel Queue is mature, but it is a better fit when the application is moving
  toward Laravel. Pulling it into this custom PHP app only for queue handling
  would add framework concepts without solving the promotion-domain risks.
- Any queue library can redeliver a job after a worker crash. Handlers that write
  BigCommerce prices, custom fields, or metafields must remain idempotent and
  audit-friendly.

Reference links:

- Symfony Messenger: https://symfony.com/doc/current/messenger.html
- Symfony releases and PHP requirements: https://symfony.com/releases
- Laravel Queues: https://laravel.com/docs/13.x/queues

### 4. Improve Operational Observability

Production support should not require reading raw logs or guessing worker state.

Useful views or structured logs:

- failed BigCommerce API calls with endpoint, response code, and store context
- pending, running, retrying, and failed queue jobs
- last successful promotion sync per store
- Omnibus sync result counts per run
- webhook events that were accepted, rejected, suppressed, or failed later in
  the worker
- cleanup jobs that changed product ownership or restored prices

### 5. Formalize Schema Changes

The schema is currently bootstrapped in `app/install/install.php`, with some
backwards-compatibility guards in services. A small migration ledger would make
installations easier to reason about.

Possible approach:

- add a `schema_migrations` table
- record each applied migration with a version and timestamp
- keep migrations idempotent for older installations
- keep compatibility guards during transition where existing installs may be
  partially upgraded

### 6. Add Stronger Input Validation Boundaries

Controller parsing and JSON payloads should fail predictably before reaching
sync or pricing services.

Good candidates for small validator or DTO classes:

- promotion create and edit payloads
- product filter JSON
- queue job payloads, especially targeted `omnibus_sync_products`
- webhook payloads after signature validation
- BigCommerce API responses used by sync logic

These classes do not need to introduce a framework. The immediate value is a
clear boundary between raw request data and business logic.

### 7. Harden BigCommerce API Writes

BigCommerce writes should be auditable, retry-aware, and idempotent where
possible.

Recommended guardrails:

- handle rate limits consistently
- retry only known transient failures
- avoid retrying unsafe writes unless the operation is idempotent or current
  remote state has been checked
- write audit context before and after price, custom-field, and metafield
  changes
- make suppression markers explicit for app-originated writes that can trigger
  webhooks

### 8. Add Continuous Verification

Minimum automated checks before merging or deploying changes:

```powershell
php -l app/Controllers/PromotionController.php
php -l app/Services/PromotionService.php
vendor\bin\phpunit.bat app\Services\PromotionServiceOmnibusValidationTest.php
vendor\bin\phpunit.bat app\Services\OmnibusSyncServiceTest.php
vendor\bin\phpunit.bat app\Services\PriceLoggerTest.php
vendor\bin\phpunit.bat app\Controllers\PromotionControllerSubmissionTokenTest.php
git diff --check
```

As coverage improves, prefer adding grouped PHPUnit commands for promotion sync,
Omnibus, queue, webhook, and archive behavior.

## Rewrite Guidance

A Node.js rewrite may make sense only if it comes with a clear architectural
goal, such as TypeScript contracts, a new UI, or a different deployment model.
For the current production risk profile, strengthening the PHP application is
the safer first investment. The best preparation for any future rewrite is a
larger regression suite and clear service-level behavior contracts.
