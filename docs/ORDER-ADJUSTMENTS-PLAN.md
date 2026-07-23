# Order Money — Adjustments, Delivery Costs & Payments

Additive redesign so orders support **many charges, discounts, and stacked coupons**, separate **customer delivery vs courier cost**, **multiple payment events** (advance + due, gateway + COD), and a **full money change log**, without breaking the running `sun2` app.

Status: **planning** (not implemented).

---

## Locked decisions

1. **Coupons can stack** — an order may have multiple coupon lines.
2. **Delivery stays separate from order adjustments** — customer delivery is **not** an `order_adjustments` row.
3. **Customer delivery vs courier cost are two different money fields** — never conflate:
   - **Customer delivery** → `orders.delivery_charge` (what we charge the buyer; defaults from `areas.delivery_charge_*`, editable on checkout/admin).
   - **Courier cost** → `orders.courier_charge` (**new** snapshotted field: what the courier charges **us**). Do **not** reuse `orders.charge` (that remains customer extras / adjustment charges) or COD `courier_balance_entries`.
4. **Courier cost updates across fulfillment phases** — set/refresh `courier_charge` when:
   - parcel is **created/sent to courier via API** (dispatch),
   - courier sends an **update notification** (webhook / status payload with fee),
   - parcel is **delivered or cancelled** (final fee / zero / policy).
   If the amount **changes** at any phase → append **audit log** (before/after, phase, source payload ref).
5. **Audit depth: full change log** — every add / edit / remove / replace of money components is append-only with actor + before/after; includes customer `delivery_charge` edits and `courier_charge` phase changes.
6. **Percent coupons** — store **resolved taka** on the line; put original `%` (and base used) in optional `meta` JSON.
7. **Admin → Orders shows net revenue** — every order (list + detail) shows **net revenue** with a visible **breakdown**, using:

   `Revenue − COGS + Charges − Discounts/coupons + Customer delivery − Courier cost`

   (i.e. merchandise margin + delivery margin). Show customer delivery and courier cost as separate breakdown lines so they never look like one field.
8. **Product max allowed discount** — Admin → Products gets a **max allowed discount** field (taka **per unit**). Stacked coupons must not discount a product line beyond that cap. Protects margin so coupon stacks cannot erase COGS protection on thin-margin SKUs.
9. **Multiple payments per order** — stop one-shot overwrite of paid/due. Record each receipt as a `payment_transactions` row (advance via gateway, later COD / another gateway, partials, refunds). Order scalars become **caches** derived from the ledger:

   ```
   paid_amount = sum(successful payment amounts)
   due_amount  = max(0, total − paid_amount)
   cod_amount  = residual to collect with courier (usually = due when remainder is COD)
   payment_status = unpaid | partial | paid   // from paid vs total
   ```

   Mix allowed: e.g. bKash advance now + remaining cash on delivery (or another gateway) later.

---

## Current state (what we must not break)

On `orders` today (scalars only):

| Field | Role |
|---|---|
| `subtotal` | Line goods |
| `delivery_charge` | **Customer** delivery (used) |
| `charge` | Extra **customer** surcharge (not courier cost) |
| `discount`, `total` | Totals |
| `cod_amount` / `due_amount` / `paid_amount` / `collected_amount` | Settlement scalars (written once today) |
| `payment_status` / `payment_method` / `payment_date` | Single-shot status / method |

**Payment gap today:** `payment_transactions` and `payment_methods` tables exist but are **unused** (no models, no admin “record payment” UI). Deliver / webhook / partial-return **overwrite** `paid_amount` / `due_amount` / `payment_status` in one shot. Admin order edit resets `due_amount = total` and `payment_status = unpaid`. Cannot record advance now and residual later, or gateway + COD mix.

**Courier-cost gap today:** there is **no** order-level field for what the courier charges us. `couriers.charge` / `osd_charge` are only a rate catalog; never snapshotted onto the order; never updated from API/webhook/deliver. `courier_balance_entries` tracks **COD book balance**, not delivery fees. `orders.charge` must not be overloaded for courier cost.

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

**Net revenue** (admin metric):

```
revenue             = subtotal                         -- merchandise sell total
cogs                = sum(order_products.purchase_price × quantity)
                      // propose: use (quantity − returned_quantity) when returns apply
charges             = sum(charge lines)                -- customer extras (not courier)
discounts           = sum(discount lines + coupon lines)
customer_delivery   = orders.delivery_charge           -- what buyer pays us for delivery
courier_cost        = orders.courier_charge             -- what courier charges us

net_revenue = revenue − cogs + charges − discounts
            + customer_delivery − courier_cost
```

Delivery margin (shown in breakdown):

```
delivery_margin = customer_delivery − courier_cost
```

Notes:
- **Do not clamp** to zero — lossy orders should show negative net revenue in admin.
- Customer delivery and courier cost are **independent**; changing one must not silently rewrite the other.
- `orders.charge` (adjustment charges) ≠ `orders.courier_charge` (merchant courier fee).

Admin UI must show:
- **Net revenue** (primary business figure on list cards / rows)
- **COD / Total** (what to collect — keep for ops / print / courier)
- **Breakdown** expandable or always-visible:
  - Revenue (subtotal)
  - COGS (with optional per-line cost detail on order show)
  - Each charge line
  - Each discount line
  - Each coupon line (code + amount)
  - **Customer delivery** (`delivery_charge`)
  - **Courier cost** (`courier_charge`)
  - Delivery margin (optional derived line)
  - **Net revenue**
  - **COD total**

Cached on `orders` for compat:

- `charge`  = sum of charge lines  
- `discount` = sum of discount + coupon lines  
- `coupon_id` = primary/first coupon (compat until readers migrate)  
- `total` / COD fields recomputed whenever lines change  
- `delivery_charge` / `courier_charge` updated via their own paths (not via adjustment sync)  

Optional later: persist `net_revenue` and/or `cogs` as cached columns if list queries need them without loading items/adjustments; until then derive in the calculator / model accessor (eager-load `items` + `adjustments` on admin list).

**Payment ledger → order caches:**

```
paid_amount      = sum(payment_transactions.amount where status in successful set)
due_amount       = max(0, total − paid_amount)
payment_status   = paid_amount <= 0 → unpaid
                   paid_amount < total → partial
                   else → paid
cod_amount       = residual intended for courier collection (default = due_amount when remainder is COD)
collected_amount = sum of COD/collection settlement txns (or courier-confirmed collect) — see open questions vs paid_amount
```

When `total` changes (adjustments / delivery edit), **recompute** `due_amount` / `payment_status` from existing successful payments — do **not** wipe `paid_amount` or delete transactions.

`orders.payment_method` becomes a **compat summary** (e.g. primary / last method, or `mixed`) — source of truth for methods is the transaction list.

---

## Customer delivery vs courier cost (detail)

### A. What we charge the customer — `orders.delivery_charge`

| Source | Behavior |
|---|---|
| Defaults | `areas.delivery_charge_upto_5` / `delivery_charge_over_5` via `CheckoutPricing` |
| Checkout | Auto from city/area + item count; buyer sees it in total |
| Admin | Editable on order form; may override default |
| Audit | Any change → money audit log (`field=delivery_charge`, actor, before/after, note) |

Still **not** an `order_adjustments` row. Still included in customer `total` / COD.

Legacy `couriers.customer_charge` / `customer_osd_charge` remain catalog leftovers — **do not** reintroduce as the live customer path (areas already own defaults). Optional later cleanup.

### B. What the courier charges us — `orders.courier_charge` (new)

| Phase | When | How value is chosen | Audit if changed |
|---|---|---|---|
| **1. API entry / dispatch** | `OrderDispatchService` creates consignment | Prefer fee from API response if present; else estimate from `couriers.charge` / `osd_charge` (Dhaka vs OSD from order city/area); store phase=`dispatch` | Yes |
| **2. Courier notification** | Webhook / tracking payload | Parse fee from provider payload when available; update only if parsed value present and differs | Yes (`phase=webhook` / `tracking`) |
| **3. Deliver / cancel** | Delivered webhook or admin deliver; cancel / C/R | Final fee from courier if provided; on cancel/return apply policy (keep fee, zero, or partial — see open questions) | Yes (`phase=delivered` / `cancelled`) |
| **4. Manual** | Admin edit on order | Staff override with reason | Yes (`phase=manual`) |

Implementation notes:
- Single writer service, e.g. `OrderCourierChargeSync::set(Order $order, Money $amount, string $phase, ?User $actor, array $meta)` — compares to current, writes order column, appends audit only on change.
- Keep raw evidence in `courier_data.api_data`; audit `meta` should reference `courier_data.id` / event type when possible.
- **Do not** put courier delivery fees into `courier_balance_entries` (that ledger is COD book balance).
- Rate catalog `couriers.charge` / `osd_charge` remains the **fallback estimate** at dispatch when API does not return a fee.

### Naming map (avoid collisions)

| Field | Means |
|---|---|
| `orders.delivery_charge` | Customer pays us for delivery |
| `orders.courier_charge` | Courier charges us for delivery (new) |
| `orders.charge` | Customer extra charges (adjustments sum) |
| `couriers.charge` / `osd_charge` | Default rates courier bills us (catalog) |
| `couriers.customer_*` | Legacy unused customer defaults |
| `courier_balance_entries` | COD held at courier (not delivery fee) |

---

## Multiple payments (advance + due, gateway + COD)

### Current (broken for this goal)

- Place order → `due_amount = total`, `paid_amount = 0`, `payment_status = unpaid`, single `payment_method` (usually `cod`).
- Deliver / webhook → set `paid = collected = cod`, `due = 0`, `status = paid` (**one shot**).
- No way to record “৳500 bKash now, ৳2000 COD later”.

### Target flow

1. Order created → `total` set; `paid=0`, `due=total`, `status=unpaid` (same as today).
2. **Record payment** (admin or gateway callback): insert `payment_transactions` row → sync order caches.
3. Residual due can be:
   - collected later as **COD** (courier `collectableAmount()` uses residual `cod_amount` / `due_amount`), or
   - collected later via **another gateway** (second txn), or
   - split across multiple events.
4. Deliver / webhook records a **COD payment txn for the residual** (or confirms collected amount), then syncs caches — **never** blindly sets `paid = full total` if advances already exist.
5. Admin can always open order → Payments → add/void/refund with audit.

### Example

| Step | Txn | paid | due | status |
|---|---|---|---|---|
| Place (total ৳3000) | — | 0 | 3000 | unpaid |
| Advance bKash ৳1000 | +1000 bKash | 1000 | 2000 | partial |
| Dispatch to courier | COD collectable = ৳2000 | 1000 | 2000 | partial |
| Delivered, COD ৳2000 | +2000 cod | 3000 | 0 | paid |

### Activate / extend `payment_transactions`

Existing columns are a good start. Additions:

| Column | Type | Notes |
|---|---|---|
| (existing) `order_id`, `method`, `amount`, `reference`, `status`, `meta`, `received_by` | | Keep |
| `kind` | string | `advance` \| `partial` \| `settlement` \| `refund` \| `adjustment` (optional; can live in `meta` initially) |
| `payment_method_id` | FK nullable → `payment_methods` | Prefer over free-string long-term; keep `method` code denormalized |
| `paid_at` | timestamp nullable | When money was received (vs `created_at`) |
| `external_id` | string nullable | Gateway charge id / trx id (unique per method when set) |

Successful statuses (propose): `completed` / `succeeded` (normalize one). Pending / failed / voided do **not** count toward `paid_amount`.

### `payment_methods` catalog

Seed/activate: `cod`, `bkash`, `nagad`, `cash`, `bank`, … (match what ops use). Admin CRUD can come later; hard-coded active list is fine for v1 if seeded.

### Sync rules (non-negotiable)

1. **Only** `OrderPaymentSync` (or equivalent) writes `paid_amount` / `due_amount` / `payment_status` / (usually) `cod_amount` from the ledger.
2. Deliver / webhook / partial-return **create or update payment txns**, then call sync — no direct scalar overwrite of paid/due except via sync.
3. Admin order money edits that change `total` call sync afterward.
4. Courier dispatch uses **residual** collectable (`due_amount` / `cod_amount`), not full `total`, when advances exist.
5. Cancel/return: do **not** silently zero successful gateway advances; use refund txns / policy (open question).

### Admin UI

- Order show / form: **Payments** panel — list txns (method, amount, reference, status, time, actor).
- Actions: **Record payment** (method, amount ≤ due unless override, reference, note).
- Show running **Paid / Due / Status**.
- Optional: “Mark residual as COD” sets expectation for dispatch without recording money yet.

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

Extend the same audit table (or allow `order_adjustment_id` null) for **non-adjustment money fields**:
- `field` column (nullable string): `delivery_charge` \| `courier_charge` \| `adjustment` \| …
- `phase` column (nullable string): `dispatch` \| `webhook` \| `tracking` \| `delivered` \| `cancelled` \| `manual` \| `checkout` \| `admin_edit`
- `source_courier_data_id` nullable FK/ref for API evidence

### `orders.courier_charge` (new column)

| Column | Type | Notes |
|---|---|---|
| `courier_charge` | decimal(12,2) default 0 | What courier charges **us**; independent of `delivery_charge` |

### Keep on `orders` (phase 1–2)

Do **not** drop `charge`, `discount`, or `coupon_id` until all readers use lines.

### `products.max_discount` (new)

| Column | Type | Notes |
|---|---|---|
| `max_discount` | decimal(12,2) nullable | **Per-unit** max coupon discount in taka. `null` = no cap. `0` = coupons cannot discount this product. |

Also snapshot onto `order_products.max_discount` at order time (same pattern as `price` / `purchase_price`) so later catalog edits do not rewrite historical caps.

### Coupon allocation vs max discount

For each cart/order line:

```
line_discount_cap = (max_discount ?? ∞) × quantity
```

Order-level coupon discount capacity:

```
order_coupon_cap = sum(line_discount_cap) over lines
```

When resolving stacked coupons:
1. Compute unconstrained coupon amounts as today (fixed / percent on eligible base).
2. Allocate each coupon’s resolved taka across lines (propose: **proportional to line_total**, then clamp per line to remaining `line_discount_cap`).
3. If a coupon cannot apply any amount after caps → reject that coupon with a clear message (checkout) or show warning (admin).
4. Sum of all coupon lines on the order must stay ≤ `order_coupon_cap`.
5. Store allocation in coupon line `meta`, e.g. `{ "allocations": [{ "product_id": 1, "amount": 50 }], "capped": true }`.

**Scope of the cap:** applies to **coupon** adjustment lines. Admin freeform `discount` lines — see open questions (propose: also respect caps by default; allow override with audited note).

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
9. **Product max discount:** coupon stacks cannot take more than `max_discount × qty` off any line; total coupon discount ≤ sum of line caps.
10. Prefer **auto-cap** (apply what fits under remaining room) over hard-fail when a stack partially fits — unless the coupon would apply **0**, then reject.

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
- [x] Delivery separate from adjustments: yes
- [x] Customer delivery (`delivery_charge`) vs courier cost (`courier_charge`): separate fields, never conflated
- [x] Courier cost refreshed on API entry / notification / deliver-cancel; audit on change
- [x] Full money audit log: yes (includes delivery + courier cost)
- [x] Percent → resolved taka + meta: yes
- [x] Admin Orders net revenue = Revenue − COGS + Charges − Discounts/coupons + Customer delivery − Courier cost
- [x] Product max allowed discount (per unit) caps coupon stacks
- [x] Multiple payments: ledger via `payment_transactions`; advance + due; gateway + COD mix; scalars = caches
- [ ] Confirm percent base: remaining merchandise after prior discount/coupon lines (proposed)
- [ ] Confirm `min_order` check: against original subtotal vs remaining after prior coupons
- [ ] Confirm max stack count (unlimited vs soft cap, e.g. 5)
- [ ] Confirm whether admin may stack coupons that checkout would reject (inactive / expired) — propose: admin override with logged note
- [ ] Confirm cancel/return behavior for `used_count`
- [ ] Confirm whether charges can be negative (propose: **no**; use discount line instead)
- [ ] Confirm rounding: integer taka for display/admin vs 2-decimal storefront percent (align to one rule)
- [ ] Confirm admin freeform discounts also respect product max caps (proposed **yes**, with audited override)
- [ ] Confirm default for existing products: `max_discount = null` (uncapped) vs seed to `price − purchase_price`
- [ ] Confirm cancel/return policy for `courier_charge` (keep / zero / partial)
- [ ] Per-courier: which API/webhook fields carry the actual fee (Steadfast / Pathao / Redx / CarryBee)
- [ ] Confirm `collected_amount` vs `paid_amount` (propose: `paid` = all successful txns; `collected` = COD/courier-confirmed subset)
- [ ] Confirm overpayment allowed (propose: allow with `due=0` and audit note)
- [ ] Confirm cancel with prior gateway advance: auto-refund txn vs leave paid and flag due negative / credit

### B. Schema & migrations

- [ ] Migration: create `order_adjustments`
- [ ] Migration: create `order_adjustment_logs` (with `field` + `phase` for delivery/courier cost too)
- [ ] Migration: add `orders.courier_charge` decimal(12,2) default 0
- [ ] Migration: enhance `payment_transactions` (`kind`, `paid_at`, `payment_method_id`, `external_id` as needed)
- [ ] Seed `payment_methods` (`cod`, `bkash`, `nagad`, `cash`, …)
- [ ] Migration: add `products.max_discount` (nullable decimal)
- [ ] Migration: add `order_products.max_discount` snapshot (nullable decimal)
- [ ] Unique index: one row per `(order_id, coupon_id)` where `type = coupon`
- [ ] Eloquent: `OrderAdjustment`, `OrderAdjustmentLog`, `PaymentTransaction`, `PaymentMethod`; update `Product`, `OrderProduct`, `Order`
- [ ] `Order` relations: `adjustments()`, `adjustmentLogs()`, `paymentTransactions()`, keep `coupon()` for compat
- [ ] Factories for tests
- [ ] Backfill command/migration:
  - adjustment lines from scalar charge/discount/coupon
  - optional: synthesize one `payment_transactions` row from existing paid/collected when `paid_amount > 0`
  - write `backfilled` log rows
- [ ] Verify backfill on a copy of production (40MB data dump when available)

### C. Domain services

- [ ] `OrderTotalCalculator` — single formula used by checkout + admin + sync; exposes `total`, `cogs()`, `netRevenue()` (`revenue − cogs + charges − discounts + delivery_charge − courier_charge`), and breakdown DTO (incl. customer delivery + courier cost)
- [ ] `Order::netRevenue()` / `Order::cogs()` / `Order::deliveryMargin()` helpers
- [ ] `OrderAdjustmentSync` — replace/set lines → recompute `charge`/`discount`/`total`/`cod_amount`/`due_amount` as needed
- [ ] `OrderAdjustmentAuditor` — write full log entries on every mutation (including batch replace)
- [ ] `OrderCourierChargeSync` — set/compare `courier_charge` by phase; audit only on change; never touch `delivery_charge`
- [ ] `OrderDeliveryChargeAudit` — log customer `delivery_charge` edits (checkout/admin) without treating them as adjustments
- [ ] `OrderPaymentRecorder` — create/void/refund payment txns (method, amount, reference, kind)
- [ ] `OrderPaymentSync` — recompute `paid_amount` / `due_amount` / `payment_status` / `cod_amount` (and `payment_method` summary) from ledger; **only** writer of those scalars
- [ ] Wire dispatch / webhook / tracking / deliver / cancel paths to `OrderCourierChargeSync`
- [ ] Rewire deliver / webhook / partial-return to record payment txn(s) then `OrderPaymentSync` (no direct paid/due overwrite)
- [ ] Provider fee parsers (Steadfast, Pathao, Redx, CarryBee) — extract fee from payload when present; else leave unchanged (dispatch may use catalog estimate)
- [ ] `CouponStackingService` — validate stack, compute resolved amounts, build meta for percent
- [ ] `ProductDiscountCap` helper — per-line / order coupon room from `max_discount × qty`; allocate & clamp coupon amounts
- [ ] Stop diverging storefront vs admin formulas (both include `charge` sum)
- [ ] Keep `delivery_charge` updates independent of adjustment sync **and** of `courier_charge`
- [ ] On admin order edit: if lines exist, prefer lines as source of truth; sync scalars from lines; **re-run payment sync** after `total` changes (do not reset paid)
- [ ] On legacy path that only sets scalars: optional “materialize lines from scalars” helper for safety
- [ ] `Order::collectableAmount()` uses residual due/COD after advances

### D. Coupon catalog & usage

- [ ] Allow applying multiple coupons at checkout (session list, not single code)
- [ ] UI: add/remove individual coupons; show each resolved amount
- [ ] Validate each coupon independently (active, dates, min_order, uses remaining)
- [ ] Enforce product max-discount caps when applying / recalculating coupon stacks (auto-cap; reject if zero room)
- [ ] Surface checkout/admin message when a coupon was capped or rejected due to max discount
- [ ] Recalculate all percent lines when subtotal changes (cart edit, admin line edit)
- [ ] Increment `used_count` once per distinct coupon when order placed
- [ ] Define & implement cancel/return usage compensation
- [ ] Admin coupon picker on order form (stack) + freeform charge/discount lines
- [ ] Prevent duplicate coupon on same order

### E. Admin UI

- [ ] **Orders list (`Admin → Orders`)**: for each order show
  - **Net revenue** = Revenue − COGS + Charges − Discounts/coupons + Customer delivery − Courier cost (may be negative)
  - Compact **breakdown** (inline expand or secondary lines): revenue, COGS, +charges, −discounts, −coupons (with codes), **customer delivery**, **courier cost**, COD total
  - Keep COD/total visible for ops; do not replace it silently with net revenue
  - Eager-load `items` + `adjustments` (or cached scalars) so list pagination stays fast
- [ ] Order form: replace single charge/discount inputs with adjustment list editor
  - Add charge (label + amount)
  - Add discount (label + amount)
  - Add coupon (search/select → resolved amount + meta)
  - Edit / remove line with reason (feeds audit `note`)
- [ ] Order form: **Customer delivery** editor (defaults from area; override allowed; audited)
- [ ] Order form / show: **Courier cost** display (read-mostly; manual override with reason); show last phase source
- [ ] Live preview: net revenue (with COGS + delivery margin) **and** COD total from calculator
- [ ] Order show: full money panel
  - Revenue (subtotal)
  - COGS (order-level sum; optional per-line purchase cost)
  - Each charge line (label + amount)
  - Each discount line (label + amount)
  - Each coupon line (code + resolved amount; show % from meta when present)
  - **Customer delivery** (`delivery_charge`)
  - **Courier cost** (`courier_charge`) + phase history snippet
  - **Net revenue**
  - **COD / Total**
- [ ] Order show: **Money history** panel from `order_adjustment_logs` (adjustments + delivery_charge + courier_charge)
- [ ] Order show / form: **Payments** panel
  - List transactions (method, amount, reference, status, paid_at, actor)
  - **Record payment** (gateway / cash / COD / other; amount; reference; note)
  - Show Paid / Due / Status live after each record
  - Void / refund action (policy-gated) with audit
- [ ] Keep print label on `collectableAmount()` (residual after advances)
- [ ] Moderator list/show: same net revenue + breakdown (read-only)
- [ ] Moderator permissions: who can add charges vs discounts vs coupons vs **record payments**
- [ ] Bangla not required in admin (admin stays English per existing locale split)

### E2. Admin → Products (max discount)

- [ ] Products list: add **Max discount** column (৳ / unit); inline-edit alongside price / cost / stock
- [ ] Product create/edit form: `max_discount` input (nullable; help text: “Max coupon discount per unit”)
- [ ] Validation: `max_discount >= 0`; warn in UI if `max_discount > price − purchase_price` (would allow below-COGS selling via coupons)
- [ ] Optional bulk action later: set max discount = `price − purchase_price` for selected products
- [ ] Snapshot `max_discount` onto `order_products` in admin + storefront order placers

### F. Storefront UI

- [ ] Checkout: multi-coupon apply/remove; list stacked discounts
- [ ] Checkout summary: show each adjustment line (not one “Discount” blob)
- [ ] Order detail: list charge/discount/coupon lines; keep delivery separate
- [ ] Order detail: show payment history / paid vs due when partial (already partially there — wire to real txns)
- [ ] Bangla strings for stacked coupons / extra charges / partial payment
- [ ] Session key migration: `checkout.coupon_code` → `checkout.coupon_codes[]` (with fallback)
- [ ] Later: storefront advance pay via gateway at checkout (optional phase; admin recording is v1)

### G. Integrations & reports

- [ ] Courier dispatch / Steadfast: pass **residual** collectable amount after advances
- [ ] On dispatch success: set initial `courier_charge` (API fee or catalog estimate) + audit
- [ ] On webhook / tracking: parse fee; update + audit only when value changes
- [ ] On delivered / cancelled (webhook or admin): apply final fee policy + audit if changed
- [ ] On delivered / COD collect: insert COD `payment_transactions` for collected residual (or confirm amount), then payment sync
- [ ] Dashboard/report queries: document that `orders.charge`/`discount` remain sum caches; add delivery margin = `delivery_charge − courier_charge`; payments from txn ledger
- [ ] Optional later: report “discount given by coupon X” via `order_adjustments`
- [ ] Legacy `import:legacy`: map single legacy charge/discount into one line each + scalars; map legacy courier fee fields if present into `courier_charge`; backfill payment txn from legacy paid when possible

### H. Audit & status history

- [ ] Every create/update/delete of a line → `order_adjustment_logs` row
- [ ] Batch recalculation → `replaced_set` (or per-line logs — prefer per-line + one totals snapshot)
- [ ] Every `delivery_charge` change → audit (`field=delivery_charge`, phase=`checkout`/`admin_edit`)
- [ ] Every `courier_charge` change → audit (`field=courier_charge`, phase=`dispatch`/`webhook`/`tracking`/`delivered`/`cancelled`/`manual`)
- [ ] Every payment txn create/void/refund → money audit (or txn itself is the ledger + status history note)
- [ ] Mirror short note on `order_status_history` when `total`, payment status, or courier fee changes materially
- [ ] Actor always set when admin; null/system for checkout/backfill/API
- [ ] Admin filter/search money logs by order (and later by coupon / phase / payment method)

### I. Tests

- [ ] Unit: calculator with mixed charges/discounts/coupons + delivery
- [ ] Unit: `netRevenue` = revenue − cogs + charges − discounts + delivery_charge − courier_charge; can be negative
- [ ] Unit: changing `delivery_charge` does not alter `courier_charge` (and vice versa)
- [ ] Unit: `OrderCourierChargeSync` audits only when value changes; records phase
- [ ] Unit: COGS from `purchase_price × quantity` on order lines
- [ ] Unit: product `max_discount` clamps stacked coupons per line and order-wide
- [ ] Unit: coupon auto-caps when partial room remains; rejects when room is 0
- [ ] Unit: percent stacking order & rounding
- [ ] Unit: duplicate coupon rejected
- [ ] Unit: min_order / inactive / expired per coupon in a stack
- [ ] Unit: sync updates scalars correctly
- [ ] Unit: auditor writes before/after totals
- [ ] Feature: admin products list/edit max discount column
- [ ] Feature: admin orders list shows net revenue + breakdown (incl. customer delivery + courier cost)
- [ ] Feature: admin order show shows full breakdown (incl. COGS + both delivery fields) + net revenue + COD total
- [ ] Feature: dispatch sets courier_charge; webhook/deliver updates with audit trail
- [ ] Feature: record advance gateway payment then residual COD; status becomes partial then paid
- [ ] Feature: deliver/webhook does not wipe prior advances; paid = sum(txns)
- [ ] Feature: dispatch collectable amount uses residual due after advance
- [ ] Feature: admin add/remove multiple adjustments
- [ ] Feature: checkout apply two coupons respects product max discounts
- [ ] Feature: backfill from scalar-only order
- [ ] Feature: print/COD unchanged when scalars synced (except residual collectable)
- [ ] Feature: subtotal change recalculates percent coupon lines
- [ ] Unit: `OrderPaymentSync` paid/due/status from multiple txns
- [ ] Regression: old single-coupon checkout path until session migrated
- [ ] Regression: fully unpaid COD orders still behave as today

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
- [ ] Suggest max discount from margin (`price − purchase_price`) on product edit
- [ ] Category-level default max discount inherited by products

---

## Open questions (need input)

1. Percent stack base = remaining merchandise after prior discount/coupon lines? (proposed **yes**)
2. `min_order` evaluated on original subtotal for every coupon in the stack? (proposed **yes**)
3. Soft cap on number of coupons per order?
4. Admin override of expired/inactive coupons?
5. Rounding rule: always integer taka at persist time?
6. On order cancel, decrement `used_count` for each coupon line?
7. For returned lines, should COGS use `(quantity − returned_quantity) × purchase_price`? (proposed **yes**)
8. Do admin freeform discount lines respect product max caps? (proposed **yes**, override with audited note)
9. Existing products: leave `max_discount` null (uncapped) until staff set values, or backfill to `price − purchase_price`?
10. On courier cancel / C/R, what happens to `courier_charge` — keep last fee, set 0, or provider-specific?
11. If webhook has no fee field, leave `courier_charge` unchanged (proposed **yes**) vs re-estimate from catalog?
12. Storefront gateway advance at checkout in v1, or admin-recorded payments first? (proposed **admin v1**, storefront gateway later)
13. Which methods to seed first beyond COD (`bkash`, `nagad`, `cash`, `bank`)?
14. On cancel with gateway advance already captured — refund flow required in v1?

---

## Out of scope (this initiative)

- Changing product line discounts / per-item promotions
- Payment gateway fees as order adjustments (`payment_methods.charge` may stay metadata until needed)
- Full PSP integration / automated bKash API (v1 can be **manual record** of gateway payments; automate later)
- Merging customer delivery or courier cost into `order_adjustments` rows
- Using `courier_balance_entries` for delivery fees (stays COD ledger)
- Removing scalar columns in the first production deploy
- Replacing area-based customer delivery defaults with `couriers.customer_*`

---

## Key files to touch (when implementing)

- Migrations under `database/migrations/`
- `app/Models/Order.php`, new adjustment models
- `app/Services/...` (CheckoutPricing, OrderPlacer, AdminOrderService, CouponService)
- Livewire: `AdminOrderForm`, `AdminOrderShow`, `AdminOrders`, `StorefrontCheckout`, `StorefrontOrderDetail`
- Courier: `OrderDispatchService`, webhook processors, `OrderDeliveryReturnService`, `CourierBalanceService`
- Payments: new recorder/sync + activate `payment_transactions` / `payment_methods`
- Views for breakdown + money history + **payments panel**
- Tests under `tests/Unit` + `tests/Feature`

---

## Success criteria

- Existing orders look identical after backfill (same total / COD).
- Admin can add multiple charges, discounts, and stacked coupons on one order.
- **Admin → Orders** list and detail each show **net revenue** = Revenue − COGS + Charges − Discounts/coupons + Customer delivery − Courier cost, with a clear **breakdown**.
- Customer `delivery_charge` and merchant `courier_charge` stay independent; courier fee changes across dispatch / webhook / deliver-cancel are audited.
- **Multiple payments** work: advance via gateway + remaining COD (or another gateway); Paid/Due/Status always match the transaction ledger; deliver does not wipe advances.
- Courier collectable amount is the **residual due**, not always full total.
- **Admin → Products** supports per-unit **max allowed discount**; coupon stacks cannot exceed line/order caps.
- Checkout can apply more than one coupon.
- Every money-component change has a full audit row with actor and before/after totals.
- Print labels & courier COD keep working without code changes beyond scalar sync.
- `delivery_charge` remains a first-class order field, not an adjustment line.
