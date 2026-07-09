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
