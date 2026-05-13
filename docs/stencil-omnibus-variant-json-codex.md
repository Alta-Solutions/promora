# Codex Task: Stencil Omnibus Variant JSON

Target project:

```text
C:\Users\Jovan Ninkovic\OneDrive - Alta Solutions doo\Projects\Salvus\eljekarna24\Production\eljekarna24-stencil-theme
```

Primary file:

```text
templates\components\custom\product\custom-price.html
```

## Context

The Promora app writes `lowest_price_30d` as a BigCommerce parent product custom
field.

Old/simple format:

```text
15.64
```

New variant-product quick-fix format:

```json
{"type":"variant_prior_prices","currency":"EUR","values":{"5631":"6.23","5648":"15.64"}}
```

For variant products, the storefront must read `values[selectedVariantId]`.

## Implementation Instructions For Codex

1. Open `templates\components\custom\product\custom-price.html`.

2. Preserve the existing markup structure and CSS classes:

```text
.lowest-price
.lowest-price-desktop
.lowest-price-mobile
.lowest-price__text
.lowest-price__value
.discount-wrapper
.discount
```

3. Update the template so the raw `lowest_price_30d` custom field value is stored
   on the `.lowest-price` element as an escaped data attribute, for example:

```html
data-lowest-price-raw="{{value}}"
```

If needed, use a Handlebars-safe escaping helper already available in the theme.
Do not print JSON as the visible price.

4. Keep server-rendered fallback for simple numeric values exactly as it works
   today.

5. Add JavaScript in the theme product details flow, preferably near the existing
   variant price update logic in:

```text
assets\js\theme\common\product-details.js
```

or, if the theme already has a custom product script, use that existing custom
area instead.

6. The JavaScript must:

- find `.lowest-price[data-lowest-price-raw]`
- parse the raw value
- if it is a plain number/string number, keep the current display
- if it is JSON with `type === "variant_prior_prices"`, hide the block until a
  variant is selected
- when BigCommerce returns variant data, use `productAttributesData.v3_variant_id`
  or the existing variant id already handled in `product-details.js`
- read `payload.values[String(variantId)]`
- update both `.lowest-price-desktop .lowest-price__value` and
  `.lowest-price-mobile .lowest-price__value`
- format the value as Croatian/European EUR text, matching existing output:
  `€15,64`
- if no value exists for the selected variant, hide `.lowest-price`

7. If `.discount-wrapper .discount` is present, recalculate it from:

```text
(priorPrice - currentSalePrice) / priorPrice * 100
```

Use the current selected variant sale price from the same product attributes
response if available. Do not calculate the percentage from regular price.
If the percentage is zero/negative or data is missing, hide the discount wrapper.

8. Make the code backward-compatible:

- old value `15.64` must still display as before
- old value `15,64` must still display as before
- new JSON value must never be shown directly
- invalid JSON must leave the block hidden rather than showing broken text

9. Verify manually on a product with variants:

- before variant selection, no JSON is visible
- selecting variant `5631` shows `€6,23`
- selecting variant `5648` shows `€15,64`
- switching variants updates the value without page refresh
- a variant without a mapped value hides the lowest-price block

## Suggested Helper Logic

Use small functions, not inline ad hoc parsing in multiple places:

```js
function parseLowestPricePayload(rawValue) {
    const raw = String(rawValue || '').trim();
    if (!raw) return null;

    if (raw[0] !== '{') {
        const normalized = Number(raw.replace(',', '.'));
        return Number.isFinite(normalized) ? { type: 'single', value: normalized } : null;
    }

    try {
        const parsed = JSON.parse(raw);
        if (parsed && parsed.type === 'variant_prior_prices' && parsed.values) {
            return { type: 'variant_prior_prices', values: parsed.values };
        }
    } catch (error) {
        return null;
    }

    return null;
}
```

```js
function formatEuro(value) {
    return `€${Number(value).toFixed(2).replace('.', ',')}`;
}
```

Adapt the final code to the theme's existing style and linting rules.
