# Testing

The project uses PHPUnit from Composer dev dependencies.

## Common Commands

Run syntax checks on touched PHP files:

```powershell
php -l app/Controllers/PromotionController.php
php -l app/Services/PromotionService.php
php -l app/Views/promotions/create.php
```

Run focused PHPUnit tests:

```powershell
vendor\bin\phpunit.bat app\Controllers\PromotionControllerSubmissionTokenTest.php
vendor\bin\phpunit.bat app\Services\PriceLoggerTest.php
vendor\bin\phpunit.bat app\Services\OmnibusSyncServiceTest.php
vendor\bin\phpunit.bat app\Services\PromotionServiceOmnibusValidationTest.php
vendor\bin\phpunit.bat app\Services\PromotionArchiveServiceTest.php
```

Run a grouped set:

```powershell
vendor\bin\phpunit.bat app\Controllers\PromotionControllerSubmissionTokenTest.php app\Services\PriceLoggerTest.php app\Services\OmnibusSyncServiceTest.php app\Services\PromotionServiceOmnibusValidationTest.php app\Services\PromotionArchiveServiceTest.php
```

Check staged or unstaged diff for whitespace issues:

```powershell
git diff --check
```

## Existing Test Style

Tests are currently colocated near the classes they cover, for example:

- `app/Services/PromotionServiceOmnibusValidationTest.php`
- `app/Services/PriceLoggerTest.php`
- `app/Services/OmnibusSyncServiceTest.php`
- `app/Controllers/PromotionControllerSubmissionTokenTest.php`

Many tests use reflection and small fake collaborators to avoid real database or
BigCommerce calls.

## Risky Commands

Do not run these unless the user explicitly approves the target environment:

```powershell
php bin/worker.php
php bin/test_omnibus.php
php bin/webhook_monitor.php
```

`bin/worker.php` processes real queue rows from the configured database.
`bin/test_omnibus.php` can call BigCommerce when remote sync is enabled.
`bin/webhook_monitor.php` can create or reactivate BigCommerce webhooks unless
run with `--dry-run`.

## What To Test By Change Type

- Controller form safety: token/session tests and PHP syntax checks.
- Promotion pricing/sync logic: focused `PromotionService` tests.
- Omnibus calculation: `OmnibusPricingService`, `PriceLogger`, and `OmnibusSyncService` tests.
- View-only JavaScript/CSS changes: PHP syntax checks for the view and manual browser verification when behavior is interactive.
- DB query changes: add tests where possible and inspect `store_hash` scoping.

## Operational regression tests

Run the full suite with `vendor\bin\phpunit.bat app`. The MariaDB tests require
a disposable, separately initialized local database whose name begins with
`promora_test_`, on a dedicated port other than 3306. Use the installer with
its database credentials explicitly pointed at this fixture. These tests claim
jobs across the fixture database and exercise schema triggers. All API calls use
test doubles. Set `PROMORA_TEST_DATABASE` and `PROMORA_TEST_MYSQL_PORT` for the
test process. Optional credentials are `PROMORA_TEST_USER` and
`PROMORA_TEST_PASSWORD`. Without the two fixture settings the MariaDB tests skip.

The fixture covers parent ownership, operator decisions, lost-response recovery,
partial correction finalization, expiring leases on two independent connections,
retention across stores, interrupted catalog refresh and grouped history parity.
