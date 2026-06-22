# Omnibus Lowest Price Tracker

The Omnibus feature tracks the lowest reference price in the previous 30 days
and writes it to BigCommerce metafields that are readable by the storefront.

## Main Files

- `app/Services/PriceLogger.php`
- `app/Services/ProductCacheService.php`
- `app/Services/OmnibusPricingService.php`
- `app/Services/OmnibusSyncService.php`
- `app/Services/OmnibusMetafieldService.php`
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
the price reduction for already-applied products change, such as:

- discount percentage
- start date

Filter changes are scope changes. Newly matching products must be validated
against the real application time, but products that already have an active
`promotion_products` row for the same promotion must not be reinterpreted as a
new price drop solely because the filter set was expanded.

Metadata-only edits, such as changing the internal name, description, color, or
custom field label, must not make an already-applied promotion look like a new
price reduction. Existing products for the same promotion can skip Omnibus
revalidation when their `promotion_products.synced_at` is newer than the last
Omnibus terms update. They can still be synced so metadata/custom fields are
refreshed.

### Audited Active Promotion Correction

The edit form supports an explicit `active_discount_correction` mode for fixing
an incorrectly entered discount percentage without interpreting the correction
as a new campaign. It is intentionally narrow:

- the promotion must already be active and remain active
- the discount percentage must actually change
- the start date must remain unchanged
- a reason is required
- the actor must come from the verified BigCommerce load session
- the correction is written to `promotion_revisions`

The bypass applies only to products and variants that already have an active
`promotion_products` row for the same promotion. Newly matching products still
use the real application time and normal 30-day validation.

New `promotion_products` rows lock `omnibus_reference_at`. Retries and audited
corrections preserve it. When another promotion takes ownership, the locked
reference is reset. Legacy rows without this field value continue using the
existing history-observation fallback. Immediately before an audited correction,
legacy rows for that promotion materialize the earliest matching current promo
price observation after the lifecycle reference, with the lifecycle date as a
fallback.

Backend promotion validation must not read BigCommerce storefront metadata as an
authority. `lowest_price_30d` custom fields and `promora.lowest_price_30d`
metafields are output artifacts for the storefront. Promotion application uses
local `product_price_history` through `OmnibusPricingService`, plus the
explicit existing-promotion revalidation skip described above.

Omnibus metadata sync must use the same lifecycle reference for products
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

### Application Correction By SKU

`?route=corrections` handles the narrower case where a SKU was incorrectly
included in an active promotion and received an unapproved discount. The tool
does not delete price history. It marks the wrong `product_price_history` rows
with `ignored_at`, `ignored_reason`, and `ignored_by_correction_id`, then
reconciles the product into the best remaining active promotion or restores the
regular price.

All Omnibus readers must ignore price-history rows where `ignored_at IS NOT
NULL`. This includes lowest-price calculations, promotion validation, targeted
Omnibus sync, and repair flows. Future promotions for the same SKU should be
validated against the corrected history, not against the voided accidental sale
price.

Application corrections require a reason and explicit business confirmation
that hiding the accidental sale price from Omnibus storefront metadata is
approved for the selected store. The audit trail remains in
`promotion_application_corrections`; only the price-history row eligibility for
Omnibus calculation changes.

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
- The BigCommerce metafield `promora.lowest_price_30d` should expose the
  Omnibus reference price so the storefront can show the correct legal
  reference.
- If the storefront cannot calculate the customer-facing percentage from
  `lowest_price_30d`, avoid showing a percentage discount and show only the new
  price plus the required prior price.

## BigCommerce Metafield Sync

`OmnibusMetafieldService` owns the canonical storefront storage:

- simple products use a product metafield
- variant products use a variant metafield for each affected variant
- create the metafield when there is an active valid reduction
- update it when the reference price changes
- delete it when there is no active valid reduction
- use `permission_set = read_and_sf_access`

Canonical metafield contract:

```text
namespace: promora
key: lowest_price_30d
value: 15.64
permission_set: read_and_sf_access
```

`OmnibusFieldService` still maintains the old product custom field
`lowest_price_30d` as a temporary storefront migration fallback. It must not be
treated as canonical storage or as backend promotion-validation input. If a
legacy custom-field value would exceed BigCommerce's 250-character custom-field
limit, the app skips writing it and removes an existing legacy field when
present.

### Frontend Contract

For simple products, read product metafield:

```text
promora.lowest_price_30d
```

For variant products, read the selected variant's metafield:

```text
promora.lowest_price_30d
```

During rollout, the old product custom field may be used only as a storefront
display fallback. Do not add new storefront behavior that depends on the legacy
variant JSON custom-field format.

## Tests To Update

When changing Omnibus logic, update or add focused tests around:

- baseline seed price for sale products
- variant vs parent history
- missing full-window history
- carry-forward minimum calculation
- promotion validation against the reference price
