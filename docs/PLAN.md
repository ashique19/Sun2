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
    Customer-facing price pair order (locked for v1): struck-through **regular** first,
    then **selling** `price` emphasized (stacked). See §10 presentation. Do **not** add a
    separate “discounted price” column or swap the meaning of `price`. See §10.
11. **Priced product image (share asset):** each product may have one separately stored
    **priced image** (source gallery image left untouched). Generated in-browser for
    preview/layout + server-side with **PHP GD** (no AI). Auto-regenerate when selling
    or regular price changes. Primary purpose: **sharing**. See §11.
12. **Admin social posts (FB + Instagram):** select products → compose post (text, thumb
    vs priced image, album/carousel vs collage) → publish via existing Meta Graph Page
    credentials; persist posts for homepage **Latest posts**, on-site post pages, and
    **Re-publish**. See §12.
13. **Admin Inbox (Messenger + WhatsApp):** top-level **Admin → Inbox** unified conversation
    window to read/reply from the site via existing channel webhooks + `ChannelReplyService`.
    Track unread; AI suggested replies explicitly **out of v1**. See §13.
14. **Needs admin attention:** reusable attention queue when automation cannot safely
    conclude (first case: Steadfast “delivered” / partial COD mismatch). Surface on
    **Admin → Dashboard** above the daily order qty table, with review links. See §14.
15. **AI product image generate (Gemini):** on Admin product create/edit, modal to upload a
    raw photo + prompt, generate candidate images via Gemini (each Generate appends),
    browser-edit candidates, **+** to promote into the product gallery. Persist drafts and
    prompt history. See §15.

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
composed file separate from gallery `product_images`. Social compose/publish: `social_posts`
(+ products + per-channel publications) for Meta posts and homepage Latest posts (see §12).
Channel inbox: `channel_conversations` / `channel_messages` (Messenger + WhatsApp) with staff
`last_read_*` for unread in Admin → Inbox (see §13). Ops exceptions: `admin_attentions` (or
equivalent) for cases automation cannot safely resolve — e.g. Steadfast COD mismatch (see §14).

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

### Presentation (locked for v1)

When a valid regular price exists, show **regular first**, then selling price — stacked:

```html
<s>regular price</s><br /><b>price</b>
```

Meaning:

1. Line 1: `compare_at_price` with strikethrough (e.g. ~~৳ 2,000~~).
2. Line 2: selling `price` in bold (e.g. **৳ 1,500**).

When `compare_at_price` is null / invalid: show only bold selling `price` (no empty struck line).

Apply this order consistently on storefront surfaces that show the pair (cards, PDP,
wishlist, cart unit price, share chrome) and on the **priced image** stamp (§11).
Inline/single-line layouts may keep the same **order** (regular then selling) even if
they sit on one row instead of `<br />`.

### Work to implement (when approved)

1. **Admin product create/edit** — add “Regular price (৳)” field bound to `compare_at_price`;
   validate nullable numeric ≥ 0 and `> price` when present.
2. **Admin product show** — display regular price alongside price.
3. **Admin products list** — optional inline edit for regular price (same pattern as price).
4. **Storefront display** — use the locked presentation above everywhere customers see
   catalog unit price.
   - Cards / PDP today may still show selling-first side-by-side; align to regular-first
     (stacked or same order on one row) when implementing.
   - Offer/JSON-LD **amount** stays `price`.
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

Price stamp on the image follows §10 presentation (locked v1):

```html
<s>regular price</s><br /><b>price</b>
```

- If valid `compare_at_price` (> `price`): struck regular on line 1, bold selling `price` on line 2.
- Else: bold selling `price` only.

### Stamp style (locked for v1)

For readability on jewelry photos (busy / light / dark backgrounds):

- **Text:** black
- **Backdrop:** semi-transparent white panel behind the price block (rounded optional;
  padding so text does not sit on the photo edge of the panel)
- Struck regular + bold selling both sit inside the same panel
- Exact opacity / padding / corner radius: sensible defaults in code; may be tuned later
  without changing this locked direction (black on translucent white)

Do **not** use bare white/gold text with no backdrop as the v1 default (fails on light stones
and gold jewelry).

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

### Follow-on (explicitly later — not priced-image v1)

- Bulk download of many products’ priced images.
- Social compose/publish that **consumes** priced images — see **§12** (separate feature).
- Wiring priced image into existing public share-list / channel draft UIs (when those
  share actions are built).

### Explicit non-goals (priced-image v1)

- Do not stamp price onto gallery `product_images` rows or replace PDP media.
- Do not use AI / external image APIs for composition.
- Do not build bulk zip download or the full social-post admin/homepage flow here (§12).

## 12. Admin social posts + Latest posts — locked plan

**Status:** planned / not implemented yet.
**Depends on:** §11 for the “images with price” source option (thumbs work without it).
**v1 channels (locked):** **Facebook Page** + **Instagram** (same Meta Graph app / Page
token stack; IG Business account linked to the Page — permissions already cover both).

### Admin compose (Products)

1. Multi-select products on **Admin → Products** → **Make post**.
2. **Post text** (required or strongly encouraged).
3. **Image source** (radio):
   - **Product thumb** — listing/primary gallery image per product
   - **Images with price** — each product’s `priced_image_path` (§11); block or warn per
     product missing a priced image
4. **Multi-product layout** (choice at compose time — both options in v1):
   - **Album / carousel** — one image per selected product; publish as FB multi-photo /
     IG carousel-capable payload as Graph allows
   - **Collage** — one composed grid/collage image (PHP GD) used as the share image and
     as the homepage / on-site post thumbnail
5. **Publish** to Facebook + Instagram (v1 default: both when configured). Persist the
   post regardless so homepage / re-publish still work if a channel fails (record per-channel
   status).

### Data model

| Table / columns | Role |
|-----------------|------|
| `social_posts` | `body`, `image_source` (`thumb` \| `priced`), `layout` (`album` \| `collage`), `collage_path` / `thumbnail_path` (homepage card), status, `created_by`, timestamps |
| `social_post_products` | `social_post_id`, `product_id`, `sort_order`; optional snapshot paths used at publish |
| `social_post_publications` | `social_post_id`, `channel` (`facebook` \| `instagram`), `external_id`, `external_url`, `status`, `error`, `published_at` |

Re-publish creates a **new** `social_post_publications` row (same post content → push again);
keep prior external ids/history.

### Homepage + on-site post page (locked)

- Homepage section **Latest posts**: social-style thumbnail (collage path, or first product
  image / album cover) + short excerpt → links to **on-site post page** (not straight to FB).
- **On-site post page** includes:
  - Full post text + media presentation
  - **Link to Facebook post** when `social_post_publications` has a successful FB
    `external_url` / id
  - Each linked **product** → own storefront PDP
  - **See more similar products** → category listing (derive from post products’ category;
    if mixed categories, primary/first product’s category or a small set of category links —
    prefer first product’s category for v1 simplicity unless we later refine)

Optional later: surface Instagram permalink the same way as FB when available.

### Behavior notes

- Publishing uses existing config: `FACEBOOK_PAGE_ACCESS_TOKEN`, `FACEBOOK_PAGE_ID`,
  `FACEBOOK_GRAPH_VERSION` (and IG user/media endpoints via the linked business account).
- Do **not** rely on Facebook embed widgets for Latest posts — our DB is source of truth.
- Single-product posts: album vs collage both allowed; collage of one ≈ framed single image
  (or treat as single photo publish — implementation detail).

### Work to implement (when approved) — v1

1. Migrations for the three tables above.
2. Admin products multi-select + compose UI (text, image source, layout choice, publish).
3. Collage composer (GD) when layout = collage; store thumbnail for homepage.
4. Meta Graph publish service: Facebook + Instagram; write `social_post_publications`.
5. Re-publish action on saved post / admin post show.
6. Storefront: Latest posts on home + on-site post show route (FB link, products, category
   “see more”).
7. Feature tests: persist post without channels; publication rows; homepage lists published
   posts; re-publish adds a new publication attempt.

### Follow-on (not v1)

- TikTok / YouTube / WhatsApp Status-or-Channels as additional `channel` values.
- Bulk download of priced images only (still §11 follow-on).
- Advanced mixed-category “see more” UX.

### Explicit non-goals (v1)

- Social **login** for customers (still out of scope per decision #9).
- Organic posting to TikTok or non-Meta networks.
- Using Facebook Page plugin / embed as the homepage feed.

## 13. Admin Inbox (Messenger + WhatsApp) — locked plan

**Status:** planned / not implemented yet.
**Depends on:** existing `channel_conversations` / `channel_messages`, Messenger + WhatsApp
webhooks, and `ChannelReplyService` (already used from order-scoped conversation modal).

### Goal

A first-class **combined** inbox so staff reply to **Facebook Messenger** and **WhatsApp**
from the admin site — not only via the current order-attached conversation modal.

### Locked product choices

1. **Nav:** new top-level **Admin → Inbox** (not nested only under Orders).
2. **Unread:** track unread using **last staff read** vs **last inbound** (conversation is
   unread when inbound is newer than staff last-read).
3. **AI suggest reply:** explicitly **out of v1** for this window (separate later backlog;
   few-shot Gemini style assist can plug into the same composer later).

### UI (v1)

- **Left:** unified conversation list (Messenger + WhatsApp badges), sorted by latest
  activity; filters at least: channel, unread, within 24h messaging window, has linked
  draft/order.
- **Right:** thread (inbound/outbound, attachments) + text composer + Send.
- **Context panel / header:** customer identity when known; link to AI draft / order when
  `draft_order_id` or order relation exists; 24h window indicator (reuse
  `ChannelConversation::isWithinMessagingWindow()`).
- **Deep links:** keep “Open chat” from Admin Orders / order show → same Inbox thread
  (order modal may thin out or become a shortcut; Inbox is canonical).
- **Refresh:** Livewire poll / refresh-after-send for v1 (no websocket requirement).

### Data / behavior

- Reuse `channel_conversations.channel` = `messenger` | `whatsapp` and message store.
- Add staff read tracking, e.g. `last_read_at` (and optional `last_read_by` user id) on
  `channel_conversations`; set on open thread / mark read; unread = `last_inbound_at` >
  `last_read_at` (or null read).
- Outbound send continues through `ChannelReplyService` (Graph Messenger + WhatsApp Cloud
  API); respect 24h window errors already returned to UI.
- Inbound webhooks unchanged; new messages make threads rise in the list and flip unread
  until staff opens/read.

### Work to implement (when approved) — v1

1. Migration: `last_read_at` / `last_read_by` (or equivalent) on `channel_conversations`.
2. Livewire Admin Inbox page + nav item (roles: same staff who can manage channel orders /
   drafts — align with existing admin/dev access; moderators TBD — default **no** unless
   we explicitly grant later).
3. List query with unread + filters; thread load; mark read on open.
4. Composer → `ChannelReplyService::sendText`; show window/send errors.
5. Deep-link from order conversation entry points into Inbox.
6. Feature tests: unread flips on inbound; mark read clears; send messenger + whatsapp
   paths; outside-window blocked.

### Follow-on (explicitly later — not Inbox v1)

- **AI suggested replies** / learn-from-outbound-style (Gemini few-shot) — separate todo.
- Websockets / push presence; typing indicators; rich templates / quick-reply chips.
- Assign conversation to a staff member; snooze / resolved states beyond unread.

### Explicit non-goals (v1)

- Auto-send bot replies or FAQ automation.
- AI suggest button in the composer.
- Replacing Meta Business Suite entirely for comments/ads — this inbox is for **Page
  Messenger + WhatsApp** threads already ingested by our webhooks.

## 14. Needs admin attention (+ Steadfast COD checkpoint) — locked plan

**Status:** planned / not implemented yet.
**Trigger example:** Steadfast delivery webhook maps both `delivered` and `partial_delivered`
(and similar) to our `delivered` today in `SteadfastWebhookProcessor::mapDeliveryStatus()`,
so partial COD collections can look like full delivery.

### Goal

When automation **does not confidently understand** a situation, it must **not silently
guess**. Instead it opens a **Needs admin attention** item with a clear message and a
**Review** link. First concrete checkpoint: Steadfast delivery vs COD collected.

### Steadfast delivery checkpoint (first case)

On Steadfast `delivery_status` (and any path that would auto-mark **delivered**, including
tracking messages that imply delivered):

1. Determine **expected COD** = our order collectable / `cod_amount` (residual courier should
   collect — same source used when dispatching).
2. Determine **collected** from webhook payload when present (e.g. `cod_amount` / collected
   fields Steadfast sends); if Steadfast status is `partial_delivered` (or equivalent) treat
   as a partial-collection signal even when they also label it delivered.
3. **Match** (within a small money tolerance, e.g. ৳1): proceed with current delivered
   settlement flow (record collection, status `delivered`, reseller credit as today).
4. **Mismatch** (collected ≠ expected, including partial under-collection or over-collection):
   - **Do not** auto-mark a clean full `delivered` / do not run full delivered side-effects
     (reseller credit, etc.) as if COD were complete.
   - Still persist courier payload / `courier_data` + status history note that courier reported
     delivered/partial.
   - Create an **admin attention** item, message like:
     **“COD is ৳xxx but collected ৳yyy, yet courier reported delivered — review.”**
   - **Review** link → admin order show (and/or deliver/partial-return UI) so staff can
     apply the correct settlement (full deliver, partial return, adjust collection).

Exact held order status while attention is open (stay `dispatched` vs a dedicated flag) is an
implementation detail; locked rule is: **no silent full-delivery completion on COD mismatch**.

### Reusable “Needs admin attention” system

Generic queue for this and **future** cases from other areas (inbox parse failures, social
publish errors, import anomalies, stock conflicts, etc.).

| Field (conceptual) | Role |
|--------------------|------|
| `type` / `code` | e.g. `steadfast_cod_mismatch` |
| `title` / `message` | Human-readable summary (include xxx/yyy amounts) |
| `severity` | optional: warning / critical |
| `subject` | polymorphic or `order_id` (+ optional other ids) |
| `action_url` | Review deep-link |
| `payload` / `meta` | Raw context (webhook snippet, expected vs collected) |
| `status` | `open` \| `resolved` \| `dismissed` |
| `resolved_by` / `resolved_at` | When staff finishes review |

Deduping: avoid flooding — e.g. one open item per order+type until resolved (update message
if a newer webhook repeats the mismatch).

### Admin → Dashboard placement (locked)

On **Admin → Dashboard**, show a **Needs admin attention** section **above** the existing
segment cards’ daily table — specifically **before** the **“Last 30 Days”** order qty / value
table (the daily totals block). Preferred order on the page:

1. (Optional keep) existing segment count cards as today  
2. **Needs admin attention** (open items: message + Review button/link; empty state hidden or
   quiet)  
3. **Last 30 Days** daily order qty table  

List open items newest-first; show count badge. Resolving/dismissing from order review or
from the dashboard row clears them from this section.

### Work to implement (when approved) — v1

1. Migration + model for attention items; resolve/dismiss API.
2. Steadfast webhook: COD expected vs collected checkpoint; open attention on mismatch;
   only auto-complete delivered when amounts match.
3. Dashboard section above Last 30 Days with Review links.
4. Tests: match → delivered; mismatch → attention + not clean delivered; dashboard lists open
   items; resolve removes from open list.

### Follow-on

- More attention types from other subsystems (reuse same table + dashboard section).
- Optional Admin nav badge / dedicated attentions index page if volume grows.

### Explicit non-goals (v1)

- Fully auto-inferring partial-return line items from Steadfast without admin review.
- Replacing Steadfast’s own portal UI — we only gate **our** order state + money effects.

## 15. AI product image generate (Gemini) — locked plan

**Status:** planned / not implemented yet.
**Entry:** Admin → Products → create/edit.

### Locked product choices

1. **Staging:** generated candidates are **saved as drafts on the product** (not only
   browser session). They are **not** in the live gallery until staff clicks **+**.
2. **Prompts:** saved for reuse. Show **recent prompts** in the modal in **latest-first**
   order; selecting one fills the textarea (still editable). Each Generate that runs
   persists/updates that prompt in history.
3. **Layout:** open via a button into a **modal** (cleanest across screen sizes) — not a
   permanent side column on the edit page.

### Modal UX

| Control | Behavior |
|---------|----------|
| Raw photo upload | Source image for Gemini (one active raw per generate run; replaceable). Prefer persist raw on product while drafts exist so regenerate works after reload. |
| Prompt textarea | Editable; can be filled from recent prompt chips/list |
| Recent prompts | Latest-first list/chips of previously used prompts |
| **Generate** | Call Gemini with raw + prompt; **append** a new candidate to the draft list (does not replace prior drafts) |
| Draft list | Thumbnails of generated candidates |
| Edit | Browser Cropper scopes (same idea as existing product image queue editor) |
| **+** | Promote that (optionally edited) draft into the normal product image gallery / upload queue; draft can be removed from staging after promote |
| Discard | Optional remove draft / clear raw without affecting gallery |

Create-product flow: if no product id yet, either require save-first before generate, or
create a draft product / temp holding area then attach on first save — prefer **ensure
product saved** (existing create pattern) before opening the generate modal when possible.

### Backend / Gemini

- Today `GeminiClient` is JSON/text oriented (order parse). Add an **image generation**
  path: multimodal `generateContent` with raw image + prompt and
  `responseModalities` including `IMAGE`.
- Separate config model e.g. `GEMINI_IMAGE_MODEL` (image-capable, e.g. flash-image family);
  keep existing `GEMINI_MODEL` for JSON parse.
- Longer timeout for image gen than parse.
- Store draft files under product-scoped public/storage paths; DB rows for drafts + prompts.

### Data model (conceptual)

| Store | Role |
|-------|------|
| `product_image_drafts` (or `product_images` with `is_draft` / `kind=ai_generated`) | path, product_id, prompt used, sort, timestamps — excluded from storefront until promoted |
| `ai_image_prompts` | `prompt` text, `last_used_at`, optional `user_id` / use_count — recent list ordered by `last_used_at` desc |
| Raw source | column/path on product or draft-session row (`raw_source_path`) |

Promotion (**+**): copy/move into normal `product_images` (or existing pending queue then save),
running the same store pipeline as manual uploads (hash, primary rules, etc.).

### Work to implement (when approved) — v1

1. Migrations for drafts + prompt history (+ raw path if needed).
2. `GeminiClient` image-generate method + config for image model/timeout.
3. Product edit: “Generate images” button → modal (raw, prompt, recent, Generate, drafts,
   edit, +).
4. Promote draft → gallery; delete draft; persist across reload.
5. Tests: generate appends draft; + creates gallery image; prompts ordered latest-first;
   gallery/storefront ignore drafts.

### Explicit non-goals (v1)

- Auto-adding every generation to the live gallery without **+**.
- Bulk multi-raw generate in one click.
- Replacing manual upload / Cropper queue (this is an additional path).
- Using AI for §11 priced-image stamp (priced stamp stays GD/deterministic).
