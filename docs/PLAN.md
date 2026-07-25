# Sundoritoma 2 (`sun2`) — Rebuild Plan & Decisions

Living document capturing the plan, locked decisions, target data model, ETL mapping,
and open items for the ground-up redesign of [sundoritoma.com](https://sundoritoma.com).
Keep this updated so any new contributor/agent can pick up exactly where we are.

## 1. Goal

Rebuild the legacy **Laravel 5.4** jewelry e-commerce store as a modern, simple, well-
navigable app focused on catalog, COD checkout, and order operations, while being able
to **intake the legacy data** (especially products, categories, orders).

## 2. Target stack (locked)

- **Laravel 13** (PHP 8.3+)
- **Livewire 4** (+ Alpine) — storefront **and** admin
- **Tailwind CSS v4** (Vite)
- **MySQL / MariaDB**
- **spatie/laravel-permission** for RBAC

## 3. Decisions (locked)

1. Full redesign (not an in-place upgrade); build fresh in `sun2`.
2. **Drop** blog/CMS, static pages admin, finance/expenses/payables UI, and site settings
   admin (tables may remain from early schema; no product work planned).
3. **Drop** `currencies`, `languages`, `circulars`, `temps`, `gateways` (unused/replaced).
4. Checkout is **COD only** — no bKash / online payment gateway in scope.
5. RBAC via **spatie/laravel-permission** (replaces legacy `users.role` tinyint +
   custom `Controller@action` permission strings). Roles in use: `dev`, `admin`,
   `moderator` (New orders view+print only), `customers`, `reseller` (portal at
   `/reseller`; renamed from legacy `vendors`).
6. **Add** reviews, wishlist, and coupons.
7. Design language: refreshed **gold-on-cream** premium jewelry aesthetic (serif
   headings + clean sans), mobile-first (BD audience). See `docs/mockups/`.
8. Data is **not** imported during normal dev; the DB stays empty so `migrate:fresh`
   is fast. Production data is loaded later via a dedicated `import:legacy` ETL command.
9. **Out of scope:** social login, SMS notifications, additional courier APIs beyond
   what is already wired (Steadfast is the primary courier integration).
10. **Product regular / strikethrough price (locked naming A):** keep `products.price` as
    the **selling** amount used for all calculations (cart, checkout, order snapshots,
    SEO offer price, reseller base, coupons). Reuse existing nullable
    `products.compare_at_price` as the optional **regular / “was”** display price.
    Admin UI label: **“Regular price”** (DB column stays `compare_at_price` — no rename).
    Show storefront as selling `price` + struck-through `compare_at_price` when set and
    greater than `price`. Do **not** add a separate “discounted price” column or swap
    the meaning of `price`. See §10.
11. **Priced product image (share asset):** each product may have one separately stored
    **priced image** (source gallery image left untouched). Generated in-browser for
    preview/layout + server-side with **PHP GD** (no AI). Auto-regenerate when selling
    or regular price changes. Primary purpose: **sharing**. See §11.

## 4. Delivery sequence

1. ✅ Scaffold + redesigned schema (this repo).
2. ✅ Buyer's storefront: Home → Product Listing → Product Detail → Cart → COD
   checkout → account (wishlist, reviews, coupons).
3. ▶ ETL importer (`import:legacy`) validated against the production dump.
4. ✅ Admin back office (orders, products, categories, coupons, hero slides, couriers,
   reviews, reports, users/customers & moderators).
5. Cutover: final data import, DNS switch, legacy DB kept read-only as fallback.

## 5. Data model

The schema lives in `database/migrations/`. Principles: all `utf8mb4`, all money
`DECIMAL(12,2)`, no `ON UPDATE` on business dates, proper unique keys + indexes,
legacy primary keys preserved + `legacy_id` columns for traceability.

New tables vs. legacy: `product_images`, `carts`/`cart_items`, `coupons`,
`order_status_history`, `payment_transactions`, `payment_methods`, `product_reviews`,
`wishlists`, `addresses`. Renamed: legacy `payments` (business payables) → `payables`
(customer payments now live in `payment_transactions`).

Products money: `price` = selling amount; `compare_at_price` = optional regular / “was”
display price (see §10); `purchase_price` = cost; `commission` / `max_discount` as documented
elsewhere. Share asset: optional `priced_image_path` + `priced_image_layout` (see §11) —
composed file separate from gallery `product_images`.

Note: blogs, pages, costs/payables, settings, and payment-method tables may still exist
from early migrations but are **not** in active product scope.

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
- `role` tinyint → spatie role: `1=dev`, `2=admin`, `3=reseller` (legacy name `vendors`),
  `4=customers`, `5=moderator`.
- Split address fields → `addresses`; keep `referrer_id`/`referral_balance`.

## 7. Open items (need input)

1. **Order `status` values** actually used in production (to complete the enum).
2. **`all_images` storage format** in legacy `products` (JSON / PHP-serialized / comma-separated) — determines the image parser.
3. Admin polish: fold in any remaining real-admin screenshots if needed before cutover.
4. **Reseller portal** — see [`docs/RESELLER-PLAN.md`](RESELLER-PLAN.md). Foundation shipping: role
   `reseller` (renamed from legacy `vendors`), product commission, wallet, `/reseller` shell.
   Still to build: create-order UI, checkout reseller ID/phone, admin reseller CRUD/payouts.

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

## 10. Product regular price (`compare_at_price`) — locked plan

**Status:** planned / not implemented yet (schema + partial storefront display already exist).
**Locked:** naming choice **A** (keep DB `compare_at_price`; label “Regular price” in admin).

### Model

| Column | Role |
|--------|------|
| `price` | What the customer pays. Sole amount for cart, checkout, order line snapshots, SEO/JSON-LD offer, commissions, coupons. |
| `compare_at_price` | Optional regular / “was” price for **display only**. Nullable. |

Schema already has `compare_at_price` on `products` (`DECIMAL(12,2)` nullable). No migration rename.

### Rules

- `compare_at_price` nullable; empty / null → no strikethrough UI.
- If set, must be **`>` `price`** (otherwise treat as unset / reject on save).
- Orders, carts, and historical lines continue to snapshot **`price` only** — do not
  snapshot `compare_at_price` onto order lines unless a future need appears.

### Work to implement (when approved)

1. **Admin product create/edit** — add “Regular price (৳)” field bound to `compare_at_price`;
   validate nullable numeric ≥ 0 and `> price` when present.
2. **Admin product show** — display regular price alongside price.
3. **Admin products list** — optional inline edit for regular price (same pattern as price).
4. **Storefront display** — same pattern everywhere customers see catalog unit price:
   bold/current `price` + ~~`compare_at_price`~~ when valid.
   - Already: product card, PDP.
   - Still needed: wishlist, cart line unit price, share / SEO-facing product chrome if it
     shows a human-readable price pair (offer/JSON-LD **amount** stays `price`).
5. **Leave unchanged:** order totals, coupons / `max_discount`, reseller base/sell,
   `purchase_price`, ETL (legacy has no compare-at; keep import as `null` unless a
   legacy source is identified later).

### Explicit non-goals

- Do not rename `price` to “discounted price”.
- Do not add a second selling-price column.
- Do not rename the DB column to `regular_price` (choice B rejected).

## 11. Priced product image (price stamped on photo) — locked plan

**Status:** planned / not implemented yet.
**Purpose:** produce a reusable shareable image with price burned onto the product photo
(Messenger / WhatsApp / social later). Gallery / PDP images stay clean originals.

### Model

On `products` (not a `product_images` gallery row):

| Column | Role |
|--------|------|
| `priced_image_path` | Public path to the composed file (nullable). One per product. |
| `priced_image_layout` | JSON: position / size (e.g. `x`, `y`, `scale` or font size, optional align). Defaults when null. |

Source image for composition = listing / primary thumb (same as admin products list thumb:
primary preferred, else first by `sort_order`). **Never mutate** that source file.

Price text on the image follows §10: selling `price`; if valid `compare_at_price` (> price),
show strikethrough regular + current selling price.

### Behavior (locked)

1. **Admin → Products list:** button **“Put price on image”** — one-click generate with
   **default layout** (no editor). Requires a source image + price; replaces any existing
   priced image for that product.
2. **Admin → Product edit:** same generate action + **editor** to adjust price
   **position / size** on a live preview; save replaces existing priced image and persists
   layout. Re-generate uses saved layout when present, else defaults.
3. **Replace semantics:** create or edit always overwrites the previous priced file and
   updates `priced_image_path` / layout (at most one priced image per product).
4. **Auto-regenerate:** if a priced image already exists and admin changes `price` or
   `compare_at_price`, regenerate server-side with the saved layout (or defaults).
   Also regenerate when the **source** listing/primary image changes (new primary,
   replace/delete that affects the source) if a priced image exists.
5. **Pipeline:** browser Canvas for preview / drag-resize UX; **PHP GD** for the
   authoritative saved file (deterministic, no AI). Client must not be the only writer of
   the final pixels for auto-regen paths.
6. **Tech:** extend existing GD usage (already used for hash / hero / category resize);
   no Intervention Image / Imagick required for v1.

### Work to implement (when approved) — v1

1. Migration: `priced_image_path`, `priced_image_layout` on `products`.
2. Service: compose JPEG/PNG from source path + prices + layout; delete old file on replace.
3. Admin products list: per-row **Put price on image** (defaults).
4. Admin product edit: preview, position/size controls, generate/save, show current priced image.
5. Hooks: after price / regular price save and after primary/source image changes → auto-regen
   when `priced_image_path` is set.
6. Tests: compose replaces previous path; layout preserved on price-change regen; skip when
   no source image / no priced image yet.

### Follow-on (explicitly later — not v1)

- Bulk download of many products’ priced images.
- Share-to-social flows that attach/upload the priced image.
- Wiring priced image into existing public share-list / channel draft UIs (when those
  share actions are built).

### Explicit non-goals (v1)

- Do not stamp price onto gallery `product_images` rows or replace PDP media.
- Do not use AI / external image APIs for composition.
- Do not build bulk zip download or social posting in v1.
