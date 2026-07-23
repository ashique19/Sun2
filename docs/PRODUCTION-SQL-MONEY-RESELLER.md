# Production DB apply (money + reseller)

**Deploy order (required):**

1. Backup the production database.
2. Run [`production_apply_money_reseller.sql`](./production_apply_money_reseller.sql) in phpMyAdmin (SQL tab; allow multiple statements).
3. Deploy the application code for this PR.
4. Clear Spatie permission cache: `php artisan permission:cache-reset` (or clear app/config cache).

Do **not** deploy the new code before the SQL — admin order screens query `order_adjustments` / `courier_charge` / `reseller_*` columns.

The SQL is idempotent (column/table existence checks + backfill `NOT EXISTS` guards) and records both Laravel migration names so `php artisan migrate` will not double-apply.
