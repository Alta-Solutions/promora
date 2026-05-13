# Codex Task: Omnibus Variant Range Fallback

Target project:

```text
C:\Users\Jovan Ninkovic\OneDrive - Alta Solutions doo\Projects\Salvus\eljekarna24\Production\eljekarna24-stencil-theme
```

Related existing instruction:

```text
docs\stencil-omnibus-variant-json-codex.md
```

## Goal

When `lowest_price_30d` is a variant JSON payload and no variant is selected,
show a prior-price range instead of hiding the block.

Input format:

```json
{"type":"variant_prior_prices","currency":"EUR","values":{"5631":"6.23","5648":"15.64"}}
```

## Display Rules

1. Simple numeric value:

```text
15.64
```

Show exactly as before:

```text
€15,64
```

2. Variant JSON with selected variant:

Use:

```js
payload.values[String(selectedVariantId)]
```

Show:

```text
€15,64
```

3. Variant JSON without selected variant:

Calculate min and max from all numeric values in `payload.values`.

If min and max are equal, show a single value:

```text
€6,23
```

If min and max differ, show a range:

```text
€6,23 - €15,64
```

4. Discount badge:

Do not show or calculate `.discount-wrapper` while displaying a range. Discount
percentage must only be shown after a concrete variant is selected and a concrete
variant sale price is known.

5. Missing/invalid data:

If JSON is invalid, `values` is empty, or no numeric values exist, hide the
`.lowest-price` block.

If selected variant has no mapped value, fall back to the range display. If that
range cannot be calculated, hide the block.

## PDP Behavior

On product detail page:

- initial render with variant JSON and no selected variant: show range
- after variant selection: show selected variant prior price
- when switching variant: update the value without page refresh
- if selected variant has no mapped prior price: show range or hide if range is
  unavailable
- discount badge remains hidden for range and is recalculated only for selected
  variant

## Category/List Card Behavior

On category cards/listings:

- simple value: show value
- variant JSON: show range
- do not show discount badge from variant JSON unless the card has a known
  selected/default variant and current sale price for that same variant

## Suggested Helper Functions

```js
function getNumericPayloadValues(values) {
    return Object.values(values || {})
        .map(value => Number(String(value).replace(',', '.')))
        .filter(value => Number.isFinite(value) && value > 0);
}
```

```js
function getVariantRange(values) {
    const prices = getNumericPayloadValues(values);
    if (!prices.length) return null;

    const min = Math.min(...prices);
    const max = Math.max(...prices);

    return { min, max };
}
```

```js
function formatEuro(value) {
    return `€${Number(value).toFixed(2).replace('.', ',')}`;
}
```

```js
function formatPriorPriceDisplay(valueOrRange) {
    if (!valueOrRange) return null;

    if (typeof valueOrRange === 'number') {
        return formatEuro(valueOrRange);
    }

    if (valueOrRange.min === valueOrRange.max) {
        return formatEuro(valueOrRange.min);
    }

    return `${formatEuro(valueOrRange.min)} - ${formatEuro(valueOrRange.max)}`;
}
```

## Acceptance Checks

Use a product where `lowest_price_30d` is:

```json
{"type":"variant_prior_prices","currency":"EUR","values":{"5631":"6.23","5648":"15.64"}}
```

Verify:

- PDP before variant selection shows `€6,23 - €15,64`
- PDP before variant selection does not show a discount badge
- selecting variant `5631` shows `€6,23`
- selecting variant `5648` shows `€15,64`
- switching variants updates the value without refresh
- category card shows `€6,23 - €15,64`
- raw JSON is never visible to customers
