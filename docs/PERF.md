# Performance notes

Lightweight checks for Sun2 admin/storefront hot paths. Re-run with:

```bash
php artisan bench:admin-queries --rounds=3
```

## Measured (local, 2026-07-10)

| Query | Avg time |
|-------|----------|
| New orders list (paginate 20 + items) | ~41 ms |
| Dispatched list stored tracking (no live API) | ~8 ms |
| Category PLP (24 products + listingImage) | ~11 ms |
| Dashboard segment counts | ~14 ms (60s cache) |
| Dashboard daily totals (30d) | ~30 ms (60s cache) |

Times vary by DB size and machine. Dispatched list no longer auto-calls courier APIs on load; use **Refresh tracking** for live status.

## Changes in this pass

- Removed junk (`tmp_*`, `storage/sun.sql`, dead `welcome.blade.php`)
- Renamed `AdminAccess::ensureStaffAdmin()`; unified admin nav partial
- Dispatched orders: stored tracking on render; explicit refresh for live API
- `StorefrontAssets`: skip `is_file` probes outside local/testing (CDN URLs)
- PLP/search: eager-load `listingImage` only
- Dashboard: grouped status counts + 60s cache; `orders.has_return` index

## Kept (required)

- Storefront pages, SMS OTP, Pathao/Redx/CarryBee clients already wired
- Unused schema tables (blogs, costs, payables, settings, payment_*) — leave migrations

## Backlog (next, not now)

- Sales report: `whereBetween` on `placed_at` + year-list cache
- Slim Livewire public models (`StorefrontProduct` / `AdminOrderShow` IDs only)
- Product search fulltext if search volume grows
- Trait-split large admin Livewire files if they keep growing
