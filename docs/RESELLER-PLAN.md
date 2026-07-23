# Reseller Portal — Plan & Implementation Notes

Status: **phases 1–6 shipped** on branch `cursor/implement-money-reseller-plans-dd17`.

Production dump with data is local at `database/legacy/legacy_dump.sql` (gitignored, ~38MB). Live DB already has Spatie role **`vendors`** (legacy id map `role=3`) with existing users — we **rename that role to `reseller`** for the product name and keep the same assignments.

---

## Locked requirements (from product)

1. **Role:** `reseller` (rename/alias of legacy `vendors`).
2. **Product commission column** — base commission per unit at catalog price.
3. **Reseller can create orders** on behalf of a customer (phone/name/address).
4. **Customer checkout** can attach a reseller by **ID or phone**.
5. **Reseller may raise sell price above catalog price**; the **extra** (`sell − base`) × qty is **added to their commission**.
6. **Commission is earned only when the order is delivered** (not on place/dispatch).
7. **Resellers see order audit / status history** for their orders.
8. **Reseller dashboard:** in-progress orders, history, **account balance**, **payment / payout status**.

---

## Commission math (per line)

At order time, snapshot:

| Field | Meaning |
|---|---|
| `order_products.base_price` | Catalog `products.price` when ordered |
| `order_products.price` | Actual unit sell price (reseller may set ≥ base) |
| `order_products.commission_rate` | Snapshot of `products.commission` (৳ / unit) |
| `order_products.commission_earned` | Computed when **delivered** (or 0 until then) |

```
markup_per_unit     = max(0, sell_price − base_price)
line_commission     = round((commission_rate + markup_per_unit) × quantity)  // integer taka
                      // on return, reduce by returned_quantity (same rule as COGS)
order_commission    = sum(line_commission)
```

Rules:
- All commission and wallet amounts are **whole taka** (no decimals).
- Reseller **cannot** set `sell_price < base_price` (floor = our price).
- Admin-created / storefront-without-reseller orders: no reseller commission (even if product has a rate).
- Storefront with reseller attached: commission uses catalog sell price unless we later allow customer-facing markup (v1: **no markup on storefront**; only base `commission_rate × qty`).
- Credit wallet **once** on transition → `delivered`. Reverse / claw back on return after delivery (policy below).

---

## Schema

### Rename role
- Migration: if role `vendors` exists → rename to `reseller`; else `findOrCreate('reseller')`.
- Update `LegacyImporter` map: `3 => reseller` (stop creating `vendors`).
- `EnsureAdminUserCommand` seeds `reseller`.

### `products`
- `commission` `decimal(12,2) default 0` — ৳ per unit base commission.

### `orders`
- `reseller_id` nullable FK → `users` (`nullOnDelete`), indexed.
- Buyer remains `user_id`; actor remains `created_by` / `updated_by`.

### `order_products`
- `base_price` `decimal(12,2) default 0`
- `commission_rate` `decimal(12,2) default 0` — snapshot
- `commission_earned` `decimal(12,2) default 0` — filled on deliver

### Wallet (do **not** overload `users.referral_balance`)

`reseller_wallet_entries` (append-only ledger, similar to `courier_balance_entries`):

| Column | Notes |
|---|---|
| `user_id` | reseller |
| `type` | `commission` \| `payout` \| `adjustment` \| `reversal` |
| `amount` | signed: `+` credit, `−` debit |
| `balance_after` | running balance |
| `order_id` | nullable |
| `note` | |
| `created_by` | nullable (admin for payouts) |
| timestamps | |

Cached balance: either `users.reseller_balance` decimal **or** sum of entries. Prefer **`users.reseller_balance`** updated in the same transaction as each entry (fast dashboard).

`reseller_payouts` (optional v1.1 — can start with wallet entries of type `payout` only):

| Column | Notes |
|---|---|
| `user_id`, `amount`, `status` (`pending`/`paid`/`rejected`), `method`, `note`, `paid_at`, `created_by` | Admin pays reseller |

---

## Surfaces

### A. Reseller portal — `/reseller/*` (`auth` + `role:reseller`)

| Page | Purpose |
|---|---|
| Dashboard | Balance, pending commission (undelivered), in-progress counts, recent orders |
| Orders (in progress) | `new` / `confirmed` / `dispatched` for `reseller_id = me` |
| Orders (history) | delivered / returned / cancelled |
| Order show | Customer snapshot, items (base vs sell), money, **status timeline / audit** |
| Create order | Reuse admin order write path with price ≥ base editable; set `reseller_id` + `created_by` |
| Account / payouts | Balance + wallet ledger + payout status |

Layout: light reseller chrome (not full admin nav). No catalog/settings access.

### B. Storefront checkout
- Optional field: **Reseller ID or phone**
- Resolve to active user with `reseller` role; set `orders.reseller_id`
- Invalid / inactive → validation error (or ignore? — propose **error if filled but not found**)

### C. Admin
- Users: manage resellers (segment like moderators) — create/edit, activate
- Products: edit `commission` (list column optional)
- Orders: show reseller name/phone; filter by reseller
- Payouts: mark wallet payouts paid (v1.1)

---

## Services

| Service | Responsibility |
|---|---|
| `ResellerOrderService` | Wrap create lines: enforce price ≥ base, snapshot base/commission_rate, set reseller_id |
| `ResellerCommissionService` | On deliver: compute `commission_earned`, credit wallet once (idempotent). On return after deliver: reversal entry |
| `ResellerResolver` | Phone / id → active reseller user |
| `ResellerWalletService` | Append ledger + update `reseller_balance` |

Wire `ResellerCommissionService` into `OrderDeliveryReturnService` / webhook delivered paths (same place payment settlement runs).

---

## Security

- Resellers **only** see orders where `reseller_id = auth_id`.
- Cannot edit commission_rate / purchase_price / admin notes.
- Cannot dispatch courier / change status beyond what we allow (propose: **create + view only** in v1; status changes stay admin/courier).
- Rate-limit order create.

---

## Phased delivery

| Phase | Status | Notes |
|---|---|---|
| **1 — Foundation** | ✅ Done | Migrations, role rename, models, product commission admin field, plan doc |
| **2 — Portal shell** | ✅ Done | `/reseller` dashboard + order lists + order show (audit) |
| **3 — Create order** | ✅ Done | `ResellerOrderCreate` Livewire form + `ResellerOrderService` (price ≥ base enforced, commission snapshot, status history) |
| **4 — Checkout attach** | ✅ Done | `resellerRef` field in `StorefrontCheckout` → `ResellerResolver` → `OrderPlacer` sets `reseller_id`; Bangla lang key added |
| **5 — Commission credit** | ✅ Done | `ResellerCommissionService` credits wallet on deliver; `ResellerWalletService` ledger |
| **6 — Admin payouts** | ✅ Done | Admin records payout on reseller user edit; reseller wallet shows Paid badge |

### Also shipped (unlisted phases)
- **Admin reseller users** — `admin.users.resellers` route + segment in `AdminUsers` / `AdminUserEdit` + nav link in admin sidebar

---

## Open questions

1. On **partial return** after delivery: claw back commission for returned qty only? (proposed **yes**)
2. If order cancelled **before** delivery: no commission (proposed **yes**)
3. Storefront: require valid reseller when field filled, or silently ignore bad input?
4. May reseller edit their order after create (before dispatch)? (proposed **no** in v1)
5. Default `products.commission` for existing catalog: `0` until admin sets?

---

## Out of scope (v1)

- Reseller-owned product catalog
- Automatic mobile banking payouts
- Multi-level MLM / sub-resellers
- Using `referral_balance` for reseller money
