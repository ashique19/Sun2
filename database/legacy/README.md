# Legacy data (local only)

Drop the production MySQL dump here as:

```
database/legacy/legacy_dump.sql
```

This directory's `*.sql` files are **git-ignored on purpose** — the dump contains
customer PII (names, phones, addresses) and must not be committed to the repository.

It is used **only for read-only planning and to build/validate the legacy ETL
importer**. Data is **not** imported into the app during normal development
(`migrate:fresh` keeps the DB empty), so refreshing the database stays fast.

When the importer is ready it will be run explicitly (e.g. `php artisan import:legacy`),
never automatically as part of migrations or seeding.

## Rebuild flow (local → production dump)

1. Place the old production dump at repo root as `sun.sql` (or under this folder as `legacy_dump.sql`).
2. `php artisan migrate:fresh --force` then optionally `php artisan locations:seed`.
3. `php artisan legacy:load-sql --path=sun.sql` (loads into isolated `sun_legacy`; drops/recreates that DB).
4. Stepwise ETL (descriptions stay blank on purpose — see Phase 2):

```bash
php artisan import:legacy --fresh --only=countries,categories,tags,couriers
php artisan import:legacy --only=users
php artisan import:legacy --only=products
php artisan import:legacy --only=orders
php artisan import:legacy --only=settings
php artisan admin:ensure-user
```

5. Export built `sun2` for manual production upload, e.g. `sun2_production_ready.sql` via `mysqldump` (gitignored PII — do not commit).

**Windows note:** never pipe `mysqldump` through PowerShell (`| Set-Content` / `Out-File`) — that re-encodes UTF-8 Bengali into garbage (`αª…`). Use mysqldump’s `--result-file=path.sql` so the client writes the file directly.

## Phase 2 — product descriptions (deferred)

Phase 1 intentionally **does not** import `product_detail` / `product_detail_bn` (those HTML blobs hung a prior full import). Descriptions are worth a separate later pass:

- Add a dedicated command (e.g. `import:legacy-descriptions`) that `UPDATE`s `products.description` / `description_bn` by `id` from `sun_legacy`.
- Chunk ≤ 25 rows; `SELECT` only `id, product_detail, product_detail_bn`; log progress; support `--from-id=` resume.
- Sanitize/allowlist HTML before storefront `{!! !!}` rendering.
- Re-dump `sun2` after backfill before uploading to production.

Do **not** re-enable description import inside the main `import:legacy` products step.
