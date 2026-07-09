# Sundoritoma 2 (`sun2`) — Rebuild Plan & Decisions

Living document capturing the plan, locked decisions, target data model, ETL mapping,
and open items for the ground-up redesign of [sundoritoma.com](https://sundoritoma.com).
Keep this updated so any new contributor/agent can pick up exactly where we are.

## 1. Goal

Rebuild the legacy **Laravel 5.4** jewelry e-commerce store as a modern, simple, well-
navigable app while keeping all features and being able to **intake the legacy data**
(especially products, categories, orders).

## 2. Target stack (locked)

- **Laravel 13** (PHP 8.3+)
- **Livewire 4** (+ Alpine) — storefront **and** admin
- **Tailwind CSS v4** (Vite)
- **MySQL / MariaDB**
- **spatie/laravel-permission** for RBAC

## 3. Decisions (locked)

1. Full redesign (not an in-place upgrade); build fresh in `sun2`.
2. **Keep** blog/CMS and the finance/expenses modules — fully functional.
3. **Drop** `currencies`, `languages`, `circulars`, `temps`, `gateways` (unused/replaced).
4. Checkout is **COD-first**, but **online payment is in scope** (bKash/gateway) via
   `payment_transactions` + `payment_methods`.
5. RBAC via **spatie/laravel-permission** (replaces legacy `users.role` tinyint +
   custom `Controller@action` permission strings).
6. **Add** reviews, wishlist, and coupons.
7. Design language: refreshed **gold-on-cream** premium jewelry aesthetic (serif
   headings + clean sans), mobile-first (BD audience). See `docs/mockups/`.
8. Data is **not** imported during normal dev; the DB stays empty so `migrate:fresh`
   is fast. Production data is loaded later via a dedicated `import:legacy` ETL command.

## 4. Delivery sequence

1. ✅ Scaffold + redesigned schema (this repo).
2. ▶ Buyer's storefront: Home → Product Listing → Product Detail → Cart → COD/bKash
   checkout → account.
3. ETL importer (`import:legacy`) validated against the production dump.
4. Admin back office (built + iterated from real screenshots of current admin options).
5. Integrations (couriers: Pathao/Steadfast/RedX/CarryBee; SMS; social login; bKash).
6. Cutover: final data import, DNS switch, legacy DB kept read-only as fallback.

## 5. Data model

The schema lives in `database/migrations/`. Principles: all `utf8mb4`, all money
`DECIMAL(12,2)`, no `ON UPDATE` on business dates, proper unique keys + indexes,
legacy primary keys preserved + `legacy_id` columns for traceability.

New tables vs. legacy: `product_images`, `carts`/`cart_items`, `coupons`,
`order_status_history`, `payment_transactions`, `payment_methods`, `product_reviews`,
`wishlists`, `addresses`. Renamed: legacy `payments` (business payables) → `payables`
(customer payments now live in `payment_transactions`).

## 6. Legacy → new ETL field mapping (intake-critical)

Preserve legacy `id` as the new `id` (+ `legacy_id`). Transforms below.

### categories → categories
- `name_slug` → `slug` (ensure unique); `name/headline/summary/details/thumb_image/display_order/is_homepage` → direct.
- `parent_id` → null for now (flat today).

### products → products + product_images + product_tag
- `name` → `name` + generated unique `slug`.
- `thumb_image` → a `product_images` row with `is_primary=1`.
- `all_images` (serialized/blob) → parse into N `product_images` rows (`sort_order`). **Confirm format from the dump.**
- `product_detail`/`product_detail_bn` → `description`/`description_bn` (latin1→utf8mb4).
- `price`/`purchase_price` int → DECIMAL; `stock_quantity` tinyint → int.
- `tags`/`product_tag` latin1 → utf8mb4, dedupe `unique(product_id, tag_id)`.

### orders → orders + order_products + order_status_history + payment_transactions
- `id` → `id` (+ `order_number` = legacy id).
- Buyer fields → direct (snapshot).
- All money (`subtotal/charge/discount/total` int; `delivery_charge/cod/collected_amount/due_amount` double; `paid_amount`/`courier_balance_*` **varchar**) → DECIMAL; derive `payment_status`.
- `payment_gateway` → `payment_method`.
- `status` varchar → enum (**confirm full set of production values**).
- `courier_id/courier_name/courier_tracker` → mapped; `courier_data` JSON blob → `order_status_history` rows (+ `courier_data` table).
- `created_by`/`updated_by` (int ZEROFILL) → normal FK; invalid `0000-00-00` dates → NULL.
- `order_products`: money → DECIMAL, legacy `value` → `line_total`.

### users → users + spatie roles + addresses
- Preserve `id`; `contact` → `phone` (unique login); dedupe email.
- `role` tinyint → spatie role (**need role map, see open items**).
- Split address fields → `addresses`; keep `referrer_id`/`referral_balance`.

## 7. Open items (need input)

1. **`users.role` map:** what do values `1, 2, 3, 4` mean? (dev/admin/moderator/client?) — needed for spatie role assignment.
2. **Order `status` values** actually used in production (to complete the enum).
3. **`all_images` storage format** in legacy `products` (JSON / PHP-serialized / comma-separated) — determines the image parser.
4. Admin options: the initial admin mockups are a starting point; the full set of current
   admin options will be provided via screenshots and folded in during phase 4.

## 8. Mockups

Approved direction (storefront) and starting point (admin) in `docs/mockups/`:

- `mockup_home_desktop.png`, `mockup_home_mobile.png`
- `mockup_plp_desktop.png` (product listing + filters)
- `mockup_pdp_desktop.png` (product detail + gallery)
- `mockup_cart_checkout_mobile.png` (cart drawer + COD checkout)
- `mockup_admin_orders.png`, `mockup_admin_orders_mobile.png`, `mockup_admin_order_detail.png`

> Note: these are AI-generated concept mockups regenerated for repo storage; product
> photos and exact copy are placeholders to be replaced with the real catalog/Bengali text.

## 9. Repo / environment notes

- Legacy app lives in the sibling `sun` repo (reference only). This `sun2` project is the rebuild.
- Local setup: see `README.md`. PHP 8.3, `composer install`, `npm install`, `.env`,
  `php artisan migrate`, `npm run dev`, `php artisan serve`.
- Legacy production dump: place at `database/legacy/legacy_dump.sql` (git-ignored — PII).
