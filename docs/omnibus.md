# Omnibus Lowest Price Tracker

The Omnibus feature tracks the lowest reference price in the previous 30 days
and writes it to the BigCommerce product custom field `lowest_price_30d`.

## Main Files

- `app/Services/PriceLogger.php`
- `app/Services/ProductCacheService.php`
- `app/Services/OmnibusPricingService.php`
- `app/Services/OmnibusSyncService.php`
- `app/Services/OmnibusFieldService.php`
- `app/Services/PromotionService.php`

## Data Model

History is stored in `product_price_history`:

- `store_hash`
- `product_id`
- `variant_id`
- `price`
- `currency`
- `recorded_at`

Parent product rows use `variant_id IS NULL`. Variant rows use the BigCommerce
variant ID.

## Effective Price

For current price logging:

- use `sale_price` when it is numeric and greater than zero
- otherwise use regular `price`

This is handled by `ProductCacheService::getEffectivePrice()` and similar logic
in sync services.

## Initial History Seeding

When history has been cleared or Omnibus is enabled for an existing catalog, the
app may not have a full 30-day history. The code seeds an initial baseline row at
the start of the current 30-day window.

For products already on sale:

- seed regular `price` as the baseline previous price
- then log the current effective `sale_price`

Example:

- regular price: `5.00`
- sale price: `4.50`
- baseline row: `5.00` at the 30-day window start
- current row: `4.50` at observation time

This is intentional. The baseline row represents known state at the start of the
window, not a price-change event that happened on that exact date.

## Carry-Forward Logic

`OmnibusPricingService::calculateWindowMinimum()` carries the last known price
from before the window into the window. This means a baseline row remains useful
after it moves before the rolling window boundary, as long as there is no newer
price state replacing it.

Do not change this without tests. It is central to how price state is interpreted
over time.

## Full 30-Day History Requirement

Some sync paths pass `require_full_30_days_history => true`. In that mode, the
calculator requires a known price at or before the relevant window start. This is
why baseline seeding is needed for legacy catalogs.

## Promotion Validation

Promotion preview uses `PromotionService::validatePromotionPriceAgainstOmnibus()`.
When Omnibus is enabled, a promotion is allowed only when the promo price is below
the Omnibus reference price. There is a fallback to base price when history is
missing but the product base price is known.

### Promotion Reference Date

Do not trust a backdated promotion `start_date` by itself. The Omnibus reference
date must represent when the price reduction is actually applied or changed.

For preview:

- use `max(submitted_start_date, now)`

For saved promotions during sync:

- use `max(start_date, created_at, omnibus_terms_updated_at)`

This prevents users from setting a promotion start date before a known lower
price change in order to bypass the 30-day lowest-price validation. A future
scheduled promotion still uses its future `start_date`.

`omnibus_terms_updated_at` is updated only when the promotion terms that affect
the price reduction change, such as:

- discount percentage
- start date
- product filters

Metadata-only edits, such as changing the internal name, description, color, or
custom field label, must not make an already-applied promotion look like a new
price reduction. Existing products for the same promotion can skip Omnibus
revalidation when their `promotion_products.synced_at` is newer than the last
Omnibus terms update. They can still be synced so metadata/custom fields are
refreshed.

If BigCommerce is left in a partial state where `lowest_price_30d` already
exists on a product but the sale price or promotion custom field was not applied,
promotion sync may repair the missing write even if normal Omnibus revalidation
would now fail. This repair is allowed only when the target promo price is still
strictly lower than the already displayed `lowest_price_30d` value. It must not
be treated as a general force-apply bypass.

Omnibus custom field sync must use the same lifecycle reference for products
that already have an active `promotion_products` row. A retry, webhook refresh,
manual Omnibus sync, or Sync All run must not reinterpret the same active
promotion as a new price drop just because price history contains a later
technical `regular -> sale` transition.

If the first observed sale-price history row is slightly after the promotion
lifecycle reference, Omnibus sync may use that first observed current promo price
timestamp as the calculation reference. It must use the earliest matching current
promo price after the lifecycle reference, not a later retry-created price row.
If that history row has not been written yet, the product cache observation time
may be used as a fallback when it is after the lifecycle reference.

### Practical Compliance Guide

Use three different price concepts deliberately:

- `regular price`: internal catalog/base price in BigCommerce.
- `promo price`: new selling price that the promotion will apply.
- `lowest_price_30d`: Omnibus prior/reference price, calculated as the lowest
  price applied in the relevant 30-day period before the reduction.

Validation rule:

```text
promo_price < lowest_price_30d
```

Do not allow a promotion as an Omnibus price reduction when:

```text
promo_price == lowest_price_30d
```

The product may be sold at the same price, but it should not be advertised as a
new discount/reduction because the effective reduction against the Omnibus prior
price is zero.

Public discount percentage:

```text
display_discount_percent = (lowest_price_30d - promo_price) / lowest_price_30d * 100
```

Do not calculate the publicly displayed discount percentage from regular price
when `lowest_price_30d` is lower than regular price.

Example:

```text
regular price:     10.00
lowest_price_30d:   9.00
promo price:        8.00
```

Allowed:

```text
promo_price < lowest_price_30d
8.00 < 9.00
```

Public discount:

```text
(9.00 - 8.00) / 9.00 = 11.11%
```

Do not advertise this as `20% off`, even though `8.00` is 20% below the regular
price of `10.00`.

Implementation guidance:

- The app may continue using regular price as the internal business basis for
  calculating the target `promo_price`.
- The storefront/customer-facing discount percentage must be based on
  `lowest_price_30d`.
- The BigCommerce custom field `lowest_price_30d` should expose the Omnibus
  reference price so the storefront can show the correct legal reference.
- If the storefront cannot calculate the customer-facing percentage from
  `lowest_price_30d`, avoid showing a percentage discount and show only the new
  price plus the required prior price.

## BigCommerce Custom Field Sync

`OmnibusFieldService` owns the `lowest_price_30d` field:

- create the field when there is an active valid reduction
- update it when the reference price changes
- delete it when there is no active valid reduction
- synchronize the local `products_cache.custom_fields` state after successful API writes

## Tests To Update

When changing Omnibus logic, update or add focused tests around:

- baseline seed price for sale products
- variant vs parent history
- missing full-window history
- carry-forward minimum calculation
- promotion validation against the reference price
