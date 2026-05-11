# Architecture

Promora is a server-rendered PHP app for managing BigCommerce product-level
promotions. It uses Composer PSR-4 autoloading for the `App\` namespace and a
shared `App\Models\Database` singleton for PDO access.

## Entry Point

`index.php` bootstraps:

- `config.php`
- session support from `app/Support/session.php`
- locale initialization through `App\Support\Translator`
- route/action dispatch to controller classes

Routes are mapped in `index.php`. Most routes require an authenticated session
and a selected `store_hash`.

## Main Layers

- `app/Controllers`: HTTP request handling, form parsing, JSON endpoints, redirects.
- `app/Services`: business logic, BigCommerce API calls, sync orchestration.
- `app/Models`: thin database models and schema helpers.
- `app/Views`: PHP templates for server-rendered pages.
- `public`: CSS and JavaScript assets.
- `bin`: CLI scripts and queue worker.

## Store Context

The app is multi-tenant. `Database::setStoreContext($storeHash)` is used to
scope model and service work. Controllers normally derive the store from
`$_SESSION['store_hash']`. Queue workers set the store context from the job row.

When editing code, verify that every query touching store-owned data includes
`store_hash` either directly or via a model/service that applies it.

## Important Services

- `PromotionService`: creates, updates, previews, syncs, and cleans up promotions.
- `ProductCacheService`: fetches products from BigCommerce and stores product,
  variant, image, custom field, price, and inventory data in local cache.
- `QueueService`: creates and claims rows in `sync_jobs`.
- `BigCommerceAPI`: low-level BigCommerce REST wrapper.
- `CustomFieldService`: batches custom field writes/removals.
- `PriceLogger`: records effective price changes and seeds Omnibus baseline history.
- `OmnibusPricingService`: calculates current display/reference data from price history.
- `OmnibusSyncService`: refreshes `lowest_price_30d` custom fields from cached products.
- `OmnibusFieldService`: creates, updates, deletes, and cache-syncs the
  `lowest_price_30d` custom field.
- `WebhookService`: receives product and inventory changes and updates local state.
- `WebhookSuppressionService`: suppresses webhook loops caused by app-originated API writes.

## Views And Frontend

Promotion create/edit views are large PHP templates with inline JavaScript for
filters and preview behavior. Shared styling lives mainly in `public/css/promotions.css`.

Keep changes small in these files. For submit flows, prefer server-side safety
plus client-side locking. Do not rely only on JavaScript for write safety.
