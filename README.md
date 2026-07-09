# Sundoritoma 2 (`sun2`)

Ground-up redesign of [sundoritoma.com](https://sundoritoma.com) — a Bangladeshi
traditional & imitation jewelry e-commerce store — on a modern stack.

## Stack

- **Laravel 13** (PHP 8.3+)
- **Livewire 4** (+ Alpine) — storefront and admin
- **Tailwind CSS v4** (Vite)
- **MySQL / MariaDB**
- **spatie/laravel-permission** for roles & permissions

## Local development

```bash
composer install
npm install
cp .env.example .env        # then set DB_* (defaults: DB_DATABASE=sun2, DB_USERNAME=sun2)
php artisan key:generate
php artisan migrate          # creates the (empty) schema — no data import
npm run build                # or: npm run dev
php artisan serve
```

The database is intentionally kept **empty** during development. Production data is
migrated separately via a dedicated ETL importer (see `database/legacy/README.md`);
it is never imported automatically, so `migrate:fresh` stays fast.

## Data model

The schema is a clean redesign of the legacy database, built to **intake** the legacy
`categories`, `products`, and `orders` data (legacy primary keys preserved via `legacy_id`
columns; all money normalized to `DECIMAL`, all tables `utf8mb4`). See the migrations in
`database/migrations/`.

## Status

Scaffold + redesigned schema + storefront foundation. Buyer's panel (catalog → PDP →
cart → COD/bKash checkout → account) and the admin back office are built on top of this.
