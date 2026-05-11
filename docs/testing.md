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
```

Run a grouped set:

```powershell
vendor\bin\phpunit.bat app\Controllers\PromotionControllerSubmissionTokenTest.php app\Services\PriceLoggerTest.php app\Services\OmnibusSyncServiceTest.php app\Services\PromotionServiceOmnibusValidationTest.php
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
```

`bin/worker.php` processes real queue rows from the configured database.
`bin/test_omnibus.php` can call BigCommerce when remote sync is enabled.

## What To Test By Change Type

- Controller form safety: token/session tests and PHP syntax checks.
- Promotion pricing/sync logic: focused `PromotionService` tests.
- Omnibus calculation: `OmnibusPricingService`, `PriceLogger`, and `OmnibusSyncService` tests.
- View-only JavaScript/CSS changes: PHP syntax checks for the view and manual browser verification when behavior is interactive.
- DB query changes: add tests where possible and inspect `store_hash` scoping.
