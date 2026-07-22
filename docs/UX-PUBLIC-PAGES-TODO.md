# Public Pages UI/UX — Implementation Todo

Actionable backlog from the public-view UI/UX review. Work top-down by priority.
Each task lists acceptance criteria so “done” is unambiguous.

Related surfaces:
- Public share: `resources/views/livewire/public-product-share.blade.php`
- Storefront shell/header: `resources/views/components/storefront/`
- PDP: `resources/views/livewire/storefront-product.blade.php`
- Listings: `storefront-category.blade.php`, `storefront-search.blade.php`
- Home: `resources/views/livewire/storefront-home.blade.php`

---

## P0 — Public product share page

### 1. Brand frame + purpose copy
- [ ] Wrap share page with minimal brand chrome: logo linking home, site name, helpline (`01880001255`)
- [ ] Add short purpose line under the title (e.g. “Shared product list for order preparation”)
- [ ] Keep layout lean (no full storefront nav/cart) so it stays pick-friendly
- [ ] Match existing cream/gold tokens (`#FAF6EF`, `#C9A227`, `#EFE7D6`)

**Acceptance:** A first-time viewer can tell this is Sundoritoma and why the list exists without admin context.

### 2. Expiry & empty recovery CTAs
- [ ] Expired state: copy + primary CTA to home + secondary contact (helpline / email)
- [ ] Empty list state: same recovery pattern (not only “No products left”)
- [ ] Optional: note that the link may have expired (24h) so the viewer knows to ask staff for a new one

**Acceptance:** Expired/empty pages never dead-end; user can leave to the store or contact care.

### 3. Print layout, names, sticky totals
- [ ] Sticky (or always-visible) summary: line count + total pcs
- [ ] Stop aggressive single-line truncation on product names; allow 2–3 lines (or expand on tap)
- [ ] Optional: link name/image to storefront PDP when `product_id` resolves to a published product
- [ ] Add print-friendly CSS (`@media print`): hide delete controls, tighten spacing, keep images readable
- [ ] Add a “Print list” control (window.print) for staff on mobile/desktop

**Acceptance:** Staff can scan and print a multi-order pick list; names remain readable; totals stay visible while scrolling.

---

## P1 — Mobile guest shop IA

### 4. Shop navigation without forcing login
- [ ] Guest mobile header: expose browse (Categories / Search / key shop links), not only Login + Cart
- [ ] Prefer a lightweight drawer or sheet with Shop + Account sections
- [ ] Keep Login / Sign up reachable; do not require auth to open the menu
- [ ] Preserve existing authenticated account drawer behavior

**Acceptance:** A logged-out shopper on a phone can reach categories/search without logging in.

---

## P1 — Product detail page

### 5. Sticky mobile buy bar + consistent wishlist icons
- [ ] On small screens, sticky bottom bar with qty + Add to Cart (respect out-of-stock)
- [ ] Replace ♡/♥ text emoji wishlist affordances with SVG icons consistent with the cart icon
- [ ] Ensure sticky bar does not cover footer content awkwardly (safe-area / padding)
- [ ] Keep desktop buy controls as-is (or lightly aligned)

**Acceptance:** Mobile PDP purchase controls stay reachable while scrolling; wishlist/cart iconography matches.

---

## P2 — Listings & search recovery

### 6. Filters + richer empty/search states
- [ ] Category (and ideally search): filters for price range and in-stock only (minimum viable)
- [ ] Preserve existing sort; filters compose with sort + pagination
- [ ] Empty search: suggest popular categories or “View all” / home collection link
- [ ] Empty filtered category: clear-filters control + message

**Acceptance:** Shoppers can narrow listings by price/stock; failed searches offer a next step.

---

## P2 — Home hero brand signal

### 7. Stronger brand when slides are weak/empty
- [ ] No-slides fallback: include logo/brand-forward treatment, not only headline + paragraph
- [ ] When slides exist, ensure brand remains visible (logo already in header is OK if contrast/size is strong)
- [ ] Avoid stacking extra promo chips/stats in the first viewport

**Acceptance:** Removing the nav still leaves a recognizable Sundoritoma first impression on home (especially with empty hero CMS).

---

## Optional polish (P3)

- [ ] PDP: image zoom/lightbox on main gallery image
- [ ] PDP: reconsider showing exact stock quantity publicly (e.g. “In stock” / “Low stock” without count)
- [ ] Checkout: progressive disclosure for delivery-charge guide after city/area selection
- [ ] Order confirmation (guests): clearer “save this number / we’ll call you” guidance
- [ ] Accessibility pass: focus states on qty steppers, hero dots, share delete button

---

## Suggested implementation order

1. Tasks **1–3** (share page) — highest leverage, isolated surface
2. Task **4** (mobile guest nav)
3. Task **5** (PDP buy bar / icons)
4. Tasks **6–7** (listings + home)
5. P3 polish as capacity allows

## Out of scope for this backlog

- Admin order “List products” generation flow (already works)
- Payment methods beyond COD
- Full storefront redesign / new design system
