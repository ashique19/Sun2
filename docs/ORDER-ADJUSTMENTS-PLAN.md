# Order Adjustments — Multiple Charges, Discounts & Coupons

Additive redesign so orders support **many charges, discounts, and stacked coupons**, with a **full money change log**, without breaking the running `sun2` app.

Status: **planning** (not implemented).

---

## Locked decisions

1. **Coupons can stack** — an order may have multiple coupon lines.
2. **Delivery stays separate** — keep `orders.delivery_charge`; do **not** model delivery as an adjustment row.
3. **Audit depth: full change log** — every add / edit / remove / replace of money components is append-only with actor + before/after.
4. **Percent coupons** — store **resolved taka** on the line; put original `%` (and base used) in optional `meta` JSON.
5. **Admin → Orders shows net revenue** — every order (list + detail) shows **net revenue after charges, discounts, and coupons**, with a visible **breakdown**. Delivery remains listed separately and is **not** included in net revenue (see formulas below).

---

## Current state (what we must not break)

On `orders` today (scalars only):

| Field | Role |
|---|---|
| `subtotal` | Sum of product lines |
| `delivery_charge` | Geo delivery (stays as-is) |
| `charge` | Single extra charge |
| `discount` | Single discount amount |
| `coupon_id` | Single coupon FK |
| `total` | Persisted grand total |
| `cod_amount` / `due_amount` / … | Settlement; print & courier use these |

Formulas in use:

- Storefront: `total = max(0, subtotal + delivery − discount)` (no `charge`)
- Admin: `total = max(0, subtotal + delivery + charge − discount)`

Readers that must keep working during rollout: checkout, admin order form/show, storefront order detail, print labels, Steadfast COD amount, reports summing `orders.total`.

---

## Target formulas

**Customer / COD total** (what buyer pays — existing `orders.total`):

```
total = max(0,
  subtotal
  + delivery_charge          -- still on orders; not an adjustment
  + sum(charge lines)
  − sum(discount lines)      -- includes coupon-resolved amounts
)
```

**Net revenue** (admin metric — merchandise money after adjustments; **excludes delivery**):

```
net_revenue = max(0,
  subtotal
  + sum(charge lines)
  − sum(discount lines)      -- includes coupon-resolved amounts
)
```

Equivalently: `net_revenue = max(0, total − delivery_charge)` when scalars are in sync.

Admin UI must show both:
- **Net revenue** (primary business figure on list cards / rows)
- **COD / Total** (what to collect — keep for ops / print / courier)
- **Breakdown** expandable or always-visible: subtotal, each charge line, each discount line, each coupon line (code + amount), delivery (separate), then net revenue + COD total

Cached on `orders` for compat:

- `charge`  = sum of charge lines  
- `discount` = sum of discount + coupon lines  
- `coupon_id` = primary/first coupon (compat until readers migrate)  
- `total` / COD fields recomputed whenever lines change  

Optional later: persist `net_revenue` as a cached column if list queries need it without loading adjustments; until then derive in the calculator / model accessor.
---

## Proposed schema

### `order_adjustments` (line items)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `order_id` | FK → orders CASCADE | |
| `type` | enum/string | `charge` \| `discount` \| `coupon` |
| `label` | string | Display name / coupon code snapshot |
| `amount` | decimal(12,2) | **Always ≥ 0**; sign implied by `type` |
| `coupon_id` | FK nullable | Set for `type=coupon`; nullOnDelete |
| `source` | string | `checkout` \| `admin` \| `system` \| `backfill` |
| `sort_order` | smallint | Display order |
| `meta` | JSON nullable | e.g. `{ "coupon_type": "percent", "percent": 10, "base": 1500 }` |
| `created_by` | FK users nullable | Who added the line |
| `updated_by` | FK users nullable | Last editor of the line |
| `created_at` / `updated_at` | timestamps | |

Indexes: `(order_id, type)`, `(coupon_id)`, `(order_id, sort_order)`.

### `order_adjustment_logs` (full audit)

Append-only. Never update/delete rows in app code.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `order_id` | FK → orders CASCADE | |
| `order_adjustment_id` | bigint nullable | Line id if still exists; keep after line delete |
| `action` | string | `created` \| `updated` \| `deleted` \| `replaced_set` \| `backfilled` |
| `type` | string nullable | Snapshot of line type |
| `label` | string nullable | Snapshot |
| `amount_before` / `amount_after` | decimal nullable | |
| `coupon_id` | bigint nullable | Snapshot |
| `meta_before` / `meta_after` | JSON nullable | |
| `order_charge_before` / `order_charge_after` | decimal nullable | Scalar snapshot |
| `order_discount_before` / `order_discount_after` | decimal nullable | |
| `order_total_before` / `order_total_after` | decimal nullable | |
| `note` | text nullable | Human reason |
| `actor_id` | FK users nullable | |
| `created_at` | timestamp | No `updated_at` |

Also continue writing a short summary into `order_status_history` when totals change (existing UX), pointing operators to the money audit for detail.

### Keep on `orders` (phase 1–2)

Do **not** drop `charge`, `discount`, or `coupon_id` until all readers use lines.

---

## Stacking rules (coupons) — draft to implement

1. Multiple coupons allowed on one order.
2. Each coupon line stores **resolved taka** at apply/recalc time.
3. Apply order: product `subtotal` → apply coupons in `sort_order` (or apply-time order) → then admin discounts → charges do not reduce coupon base unless we decide otherwise.
4. **Default base for percent:** current remaining merchandise subtotal after earlier coupon/discount lines (not including delivery or charges). Lock in code + tests.
5. Each coupon still enforces its own `min_order` against **original subtotal** (or remaining? — see open questions).
6. `usage_limit` / `used_count`: increment **once per coupon per order** when order is placed/confirmed with that coupon; decrement/compensate on cancel only if we already do similar today (define explicitly).
7. Same coupon code twice on one order: **reject** (unique `(order_id, coupon_id)` among non-deleted coupon lines).
8. Admin freeform `discount` lines do **not** consume coupon usage.

---

## Compatibility strategy (no big-bang)

| Phase | What ships | App behavior |
|---|---|---|
| **0 — Plan** | This doc | — |
| **1 — Schema + backfill** | Tables + migrate existing scalars → 0–1 lines each | App unchanged; rows exist for old orders |
| **2 — Dual-write** | Service writes lines **and** scalar sums | Old UI still edits scalars *or* new UI edits lines; either path keeps scalars correct |
| **3 — Dual-read UI** | Admin form/show list lines; storefront detail lists lines | Fallback to scalars if no rows |
| **4 — Checkout stacking** | Multi-coupon session + apply | Still sync scalars |
| **5 — Audit UI** | Admin money history panel | Read `order_adjustment_logs` |
| **6 — Harden** | Totals always from lines; scalars = cache only | Deprecate direct scalar edits |
| **7 — Cleanup (optional later)** | Drop or ignore `orders.coupon_id` as source of truth | Only after all readers migrated |

---

## Implementation todo

### A. Decisions / product rules (resolve before or during phase 2)

- [x] Coupons stack: yes
- [x] Delivery separate: yes
- [x] Full money audit log: yes
- [x] Percent → resolved taka + meta: yes
- [x] Admin Orders: show net revenue + breakdown (delivery excluded from net revenue)
- [ ] Confirm percent base: remaining merchandise after prior discount/coupon lines (proposed)
- [ ] Confirm `min_order` check: against original subtotal vs remaining after prior coupons
- [ ] Confirm max stack count (unlimited vs soft cap, e.g. 5)
- [ ] Confirm whether admin may stack coupons that checkout would reject (inactive / expired) — propose: admin override with logged note
- [ ] Confirm cancel/return behavior for `used_count`
- [ ] Confirm whether charges can be negative (propose: **no**; use discount line instead)
- [ ] Confirm rounding: integer taka for display/admin vs 2-decimal storefront percent (align to one rule)

### B. Schema & migrations

- [ ] Migration: create `order_adjustments`
- [ ] Migration: create `order_adjustment_logs`
- [ ] Unique index: one row per `(order_id, coupon_id)` where `type = coupon`
- [ ] Eloquent: `OrderAdjustment`, `OrderAdjustmentLog`
- [ ] `Order` relations: `adjustments()`, `adjustmentLogs()`, keep `coupon()` for compat
- [ ] Factories for tests
- [ ] Backfill command/migration:  
  - `charge > 0` → one `charge` line (`source=backfill`)  
  - `discount > 0` + `coupon_id` → one `coupon` line  
  - `discount > 0` without coupon → one `discount` line  
  - write `backfilled` log rows with scalar before/after equal  
- [ ] Verify backfill on a copy of production (40MB data dump when available)

### C. Domain services

- [ ] `OrderTotalCalculator` — single formula used by checkout + admin + sync; exposes `total`, `netRevenue()`, and breakdown DTO (subtotal, delivery, charge lines, discount lines, coupon lines)
- [ ] `Order::netRevenue()` accessor/helper using calculator (fallback to `max(0, total − delivery_charge)` when no lines)
- [ ] `OrderAdjustmentSync` — replace/set lines → recompute `charge`/`discount`/`total`/`cod_amount`/`due_amount` as needed
- [ ] `OrderAdjustmentAuditor` — write full log entries on every mutation (including batch replace)
- [ ] `CouponStackingService` — validate stack, compute resolved amounts, build meta for percent
- [ ] Stop diverging storefront vs admin formulas (both include `charge` sum)
- [ ] Keep `delivery_charge` updates independent of adjustment sync
- [ ] On admin order edit: if lines exist, prefer lines as source of truth; sync scalars from lines
- [ ] On legacy path that only sets scalars: optional “materialize lines from scalars” helper for safety

### D. Coupon catalog & usage

- [ ] Allow applying multiple coupons at checkout (session list, not single code)
- [ ] UI: add/remove individual coupons; show each resolved amount
- [ ] Validate each coupon independently (active, dates, min_order, uses remaining)
- [ ] Recalculate all percent lines when subtotal changes (cart edit, admin line edit)
- [ ] Increment `used_count` once per distinct coupon when order placed
- [ ] Define & implement cancel/return usage compensation
- [ ] Admin coupon picker on order form (stack) + freeform charge/discount lines
- [ ] Prevent duplicate coupon on same order

### E. Admin UI

- [ ] **Orders list (`Admin → Orders`)**: for each order show
  - **Net revenue** (after charges / discounts / coupons; excludes delivery)
  - Compact **breakdown** (inline expand or secondary lines): subtotal, +charges, −discounts, −coupons (with codes), delivery (separate), COD total
  - Keep COD/total visible for ops; do not replace it silently with net revenue
  - Eager-load `adjustments` (or use cached scalars + coupon) so list pagination stays fast
- [ ] Order form: replace single charge/discount inputs with adjustment list editor
  - Add charge (label + amount)
  - Add discount (label + amount)
  - Add coupon (search/select → resolved amount + meta)
  - Edit / remove line with reason (feeds audit `note`)
- [ ] Live preview: net revenue **and** COD total from calculator
- [ ] Order show: full money panel
  - Subtotal
  - Each charge line (label + amount)
  - Each discount line (label + amount)
  - Each coupon line (code + resolved amount; show % from meta when present)
  - Delivery (separate)
  - **Net revenue**
  - **COD / Total**
- [ ] Order show: **Money history** panel from `order_adjustment_logs`
- [ ] Keep print label on `collectableAmount()` (no change required if scalars synced)
- [ ] Moderator list/show: same net revenue + breakdown (read-only)
- [ ] Moderator permissions: who can add charges vs discounts vs coupons
- [ ] Bangla not required in admin (admin stays English per existing locale split)

### F. Storefront UI

- [ ] Checkout: multi-coupon apply/remove; list stacked discounts
- [ ] Checkout summary: show each adjustment line (not one “Discount” blob)
- [ ] Order detail: list charge/discount/coupon lines; keep delivery separate
- [ ] Bangla strings for stacked coupons / extra charges
- [ ] Session key migration: `checkout.coupon_code` → `checkout.coupon_codes[]` (with fallback)

### G. Integrations & reports

- [ ] Courier dispatch / Steadfast: still pass synced collectable amount
- [ ] Dashboard/report queries: document that `orders.charge`/`discount` remain sum caches
- [ ] Optional later: report “discount given by coupon X” via `order_adjustments`
- [ ] Legacy `import:legacy`: map single legacy charge/discount into one line each + scalars

### H. Audit & status history

- [ ] Every create/update/delete of a line → `order_adjustment_logs` row
- [ ] Batch recalculation → `replaced_set` (or per-line logs — prefer per-line + one totals snapshot)
- [ ] Mirror short note on `order_status_history` when `total` changes (“Total ৳A → ৳B (money adjustments)”)
- [ ] Actor always set when admin; null/system for checkout/backfill
- [ ] Admin filter/search money logs by order (and later by coupon)

### I. Tests

- [ ] Unit: calculator with mixed charges/discounts/coupons + delivery
- [ ] Unit: `netRevenue` excludes delivery; equals subtotal + charges − discounts/coupons
- [ ] Unit: percent stacking order & rounding
- [ ] Unit: duplicate coupon rejected
- [ ] Unit: min_order / inactive / expired per coupon in a stack
- [ ] Unit: sync updates scalars correctly
- [ ] Unit: auditor writes before/after totals
- [ ] Feature: admin orders list shows net revenue + breakdown per order
- [ ] Feature: admin order show shows full breakdown + net revenue + COD total
- [ ] Feature: admin add/remove multiple adjustments
- [ ] Feature: checkout apply two coupons
- [ ] Feature: backfill from scalar-only order
- [ ] Feature: print/COD unchanged when scalars synced
- [ ] Feature: subtotal change recalculates percent coupon lines
- [ ] Regression: old single-coupon checkout path until session migrated

### J. Rollout / ops

- [ ] Deploy migrations + backfill on staging/`pokaco5_sun2` copy first
- [ ] Dual-write behind no flag initially if sync is additive and safe
- [ ] Feature-flag new admin/checkout UI if needed (`ORDER_ADJUSTMENTS_UI=true`)
- [ ] Monitor: orders where `abs(charge − sum(lines)) > 0.01` (drift check command)
- [ ] Drift repair command
- [ ] Docs: update `docs/PLAN.md` + admin help blurb
- [ ] After soak: remove dead single-field UI; keep columns as cache
- [ ] Optional later: drop `orders.coupon_id` as write source (read-only cache of first coupon line)

### K. Nice-to-haves (later)

- [ ] Named charge presets (“Packaging”, “Gift wrap”, “Remote area fee”)
- [ ] Coupon mutual-exclusion groups (cannot combine with X)
- [ ] Per-customer coupon claim table
- [ ] Adjustment templates for common admin edits
- [ ] Export money audit CSV per date range

---

## Open questions (need input)

1. Percent stack base = remaining merchandise after prior discount/coupon lines? (proposed **yes**)
2. `min_order` evaluated on original subtotal for every coupon in the stack? (proposed **yes**)
3. Soft cap on number of coupons per order?
4. Admin override of expired/inactive coupons?
5. Rounding rule: always integer taka at persist time?
6. On order cancel, decrement `used_count` for each coupon line?
7. Confirm net revenue **excludes delivery** (proposed **yes** — delivery is courier pass-through, not merchandise revenue).
8. Should net revenue later subtract product `purchase_price` cost (true margin), or stay at “adjusted merchandise total” for v1? (proposed **v1 = adjusted merchandise / no COGS**)

---

## Out of scope (this initiative)

- Changing product line discounts / per-item promotions
- Payment gateway fees (`payment_methods.charge`)
- Merging delivery into adjustments
- Removing scalar columns in the first production deploy

---

## Key files to touch (when implementing)

- Migrations under `database/migrations/`
- `app/Models/Order.php`, new adjustment models
- `app/Services/...` (CheckoutPricing, OrderPlacer, AdminOrderService, CouponService)
- Livewire: `AdminOrderForm`, `AdminOrderShow`, `StorefrontCheckout`, `StorefrontOrderDetail`
- Views for breakdown + money history
- Tests under `tests/Unit` + `tests/Feature`

---

## Success criteria

- Existing orders look identical after backfill (same total / COD).
- Admin can add multiple charges, discounts, and stacked coupons on one order.
- **Admin → Orders** list and detail each show **net revenue** after charges/discounts/coupons, with a clear **breakdown**; delivery shown separately and excluded from net revenue.
- Checkout can apply more than one coupon.
- Every money-component change has a full audit row with actor and before/after totals.
- Print labels & courier COD keep working without code changes beyond scalar sync.
- `delivery_charge` remains a first-class order field, not an adjustment line.
