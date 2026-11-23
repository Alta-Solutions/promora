# Performance Operations

## Database And PHP Runtime

Recommended starting points:

- Local/staging MariaDB: set InnoDB buffer pool to 256 MB when the host has enough free RAM.
- Dedicated production DB host: set InnoDB buffer pool to 60-70% of available RAM.
- PHP: enable OPcache and start with 128 MB memory for `opcache.memory_consumption`.

## BigCommerce Safety

Use `BIGCOMMERCE_READ_ONLY=true` for local and staging benchmarks. In that mode
the BigCommerce client rejects `POST`, `PUT`, `PATCH`, and `DELETE` before the
network call is made.

## Migrations

Schema changes are tracked in `schema_migrations`.

```powershell
php bin\migrate.php --status
php bin\migrate.php --up
```

The installer also runs pending migrations for new installations.

## Archive Backfill

Promotion archive backfill is intentionally not run during GET log requests.
Use the maintenance command explicitly:

```powershell
php bin\backfill_promotion_archives.php --store-hash=STORE_HASH
php bin\backfill_promotion_archives.php --store-hash=STORE_HASH --apply
```

The default mode is dry-run. Add `--apply` only when local archive rows should be
written.

The [rollout and maintenance guide](operations-rollout.md) covers operational logs, retention, canary workers and measurement.
