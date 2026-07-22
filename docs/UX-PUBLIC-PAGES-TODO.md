# Public Pages UI/UX — Implementation Todo

Audience-first backlog for Sundoritoma storefront + public share.

**Primary audience:** Bangladeshi shoppers on mobile, often limited formal education, often on slow / metered mobile data. Prefer **clear Bangla**, **simple navigation**, and **reliable image loading** over a “premium” look.

Related surfaces:
- Public share: `resources/views/livewire/public-product-share.blade.php`
- Storefront shell/header: `resources/views/components/storefront/`
- PDP: `resources/views/livewire/storefront-product.blade.php`
- Listings: `storefront-category.blade.php`, `storefront-search.blade.php`
- Home: `resources/views/livewire/storefront-home.blade.php`
- Images: `app/Support/StorefrontAssets.php`, `listing-image.blade.php`

---

## Design principles (locked for this backlog)

1. **Bangla-first UI** — Chrome, buttons, errors, empty states, checkout, and share page copy in Bangla. Keep product *names* as stored (often already Bangla/mixed). Prefer `description_bn` when present.
2. **Simple over gorgeous** — Large tap targets, short sentences, icons + labels, obvious next step. Reduce decorative hero/gold ornament if it competes with “what do I do next?”
3. **Mobile-first, low literacy** — Prefer pictures + one big action. Avoid English jargon (Wishlist / Checkout / OTP as-is). Pair words with familiar icons (phone, cart, home).
4. **Bandwidth-honest images** — Never ship a bigger file than the on-screen size needs. Lean on existing `_xs/_sm/_md/_lg` variants + BDIX-friendly stable URLs on `sundoritoma.com`.

---

## P0 — Language & simplicity foundation

### 1. Bangla-first storefront copy layer
- [ ] Set storefront `lang="bn"` (layout)
- [ ] Introduce Laravel lang files (e.g. `lang/bn.json` or `lang/bn/*.php`) for all buyer-facing chrome
- [ ] Translate: header, footer, announcement, cart, checkout, OTP, login/register, account, empty states, validation messages shown to buyers
- [ ] Prefer plain words, e.g.  
  - Add to Cart → **কার্টে রাখুন** / **কিনুন**  
  - Checkout → **অর্ডার করুন**  
  - OTP → **মোবাইল কোড** (৬ সংখ্যা)  
  - Wishlist → **পছন্দ** / **সেভ**  
  - Out of stock → **স্টক নেই**
- [ ] Product body: show `description_bn` when non-empty, else `description`
- [ ] Keep admin English (staff tool); share page Bangla if staff/warehouse are Bangla-speaking (default: Bangla)

**Acceptance:** A Bangla-only reader can complete browse → cart → COD order without needing English UI words.

### 2. Simplify layout for easy navigation (not luxury-first)
- [ ] Prioritize a **clear mobile path**: বড় ক্যাটাগরি → পণ্য → কিনুন → অর্ডার
- [ ] Add sticky bottom nav on mobile: **হোম | ক্যাটাগরি | কার্ট | কল** (helpline `tel:01880001255`)
- [ ] Guest menu must open shop links without login (ক্যাটাগরি, সার্চ, কার্ট)
- [ ] Enlarge primary CTAs; one primary action per screen
- [ ] Soften/remove decorative noise in first viewport (eyebrow stacks, thin gold chrome) if it hurts scanning
- [ ] Home: category grid as the main job; hero optional / secondary (single image OK; carousel not required)

**Acceptance:** First-time mobile user finds a category and a product in few taps; call-to-order is always one tap away.

---

## P0 — Low-bandwidth images (+ BDIX)

### 3. Image loading strategy for slow mobile data
Current base: variants `_xs/_sm/_md/_lg`, `StorefrontAssets::srcset()`, listing `loading="lazy"`, CDN host `https://www.sundoritoma.com/public/…` (good candidate for BDIX cache if that host is on the cache).

**Rules to implement:**
- [ ] **Default `src` = smallest adequate variant**, not `md`  
  - Listing/card thumb → `_sm` (or `_xs` on 2-col mobile if quality OK)  
  - Cart / share row thumb → `_xs` or `_sm`  
  - PDP main → `_md` (LCP), thumbs → `_xs`/`_sm`  
  - Hero → one `_md` (or mobile `_sm`), not full `_lg` by default
- [ ] Keep `srcset` + correct `sizes` so good connections can upgrade; poor connections keep the small default
- [ ] Always set explicit **width/height** (or aspect-ratio box) to avoid layout jump while images crawl in
- [ ] Solid cream placeholder while loading (no giant blank holes)
- [ ] `loading="lazy"` below the fold; **only** LCP image gets `fetchpriority="high"`
- [ ] Share page: stop using large `md` frames for list rows; use small thumbs + readable names
- [ ] Prefer **WebP** (with jpeg fallback) when generating/uploading new derivatives, if not already
- [ ] Cap hero: prefer **one** promotional image over multi-slide autoplay on mobile (autoplay burns data + attention)
- [ ] Do not hotlink unpredictable query-busted URLs that defeat BDIX/CDN cache; keep stable paths
- [ ] Confirm production **Cache-Control** on `/public/img/**` is long-lived (immutable variants are ideal)
- [ ] Optional later: “কম ডাটা” toggle or `navigator.connection.saveData` → force `_xs` only

**BDIX note:** Hosting BDIX cache helps when the browser requests the **same cached host/path**. Keep storefront image URLs on the BDIX-enabled domain (`www.sundoritoma.com` today via `StorefrontAssets::CDN_BASE`). Avoid switching listing images to a third-party CDN that is not on BDIX.

**Acceptance:** On a throttled 3G profile, category grid becomes usable quickly (small thumbs); PDP LCP is one mid-size image; share/pick list does not download oversized photos.

---

## P1 — Public product share page

### 4. Brand frame + Bangla purpose (simple)
- [ ] Minimal header: logo, সুন্দরিতমা, হেল্পলাইন
- [ ] Title in Bangla (e.g. **পণ্যের তালিকা**) + one-line purpose for pickers
- [ ] No full storefront chrome; keep pick-friendly

**Acceptance:** Viewer knows whose list this is and what to do.

### 5. Expiry & empty recovery (Bangla)
- [ ] Expired / empty: plain Bangla explanation + **হোমে যান** + **কল করুন**
- [ ] Mention link may expire (~২৪ ঘণ্টা) so they ask staff for a new link

**Acceptance:** No dead ends.

### 6. Readable list + print + light images
- [ ] Sticky summary: লাইন সংখ্যা + মোট পিস
- [ ] Names wrap 2–3 lines (no harsh single-line truncate)
- [ ] Small cached thumbs only; optional PDP link when product exists
- [ ] Print button (**প্রিন্ট**) + print CSS (hide delete)

**Acceptance:** Staff can scan/print on phone; images stay small.

---

## P1 — Buy path clarity

### 7. PDP: sticky buy bar + plain actions
- [ ] Mobile sticky bar: পরিমাণ + **কার্টে রাখুন** / **কিনুন**
- [ ] Icon + Bangla label for wishlist (not English “Wishlist”)
- [ ] Trust line in Bangla: ক্যাশ অন ডেলিভারি · সারা দেশে ডেলিভারি · কল ০১৮৮০০০১২৫৫
- [ ] Avoid showing exact stock count if confusing; prefer **স্টক আছে** / **স্টক নেই**

**Acceptance:** Thumb can always reach buy; meaning of buttons is obvious in Bangla.

### 8. Checkout copy & steps in Bangla
- [ ] Step labels, field labels, OTP screen in simple Bangla
- [ ] Emphasize: কোড যাবে মোবাইলে → কোড দিন → অর্ডার হবে → টাকা ডেলিভারিতে
- [ ] Delivery charge help appears after city/area chosen (short Bangla, not a dense English guide)

**Acceptance:** First-time COD buyer understands each step without English.

---

## P2 — Findability without complexity

### 9. Category / search recovery (keep filters light)
- [ ] Prefer simple controls: **কম দাম / বেশি দাম**, **স্টক আছে** — avoid dense filter panels
- [ ] Empty search: show popular categories + **সব পণ্য দেখুন**
- [ ] Large category tiles with image + Bangla name (already image-led — keep)

**Acceptance:** Failed search still leads somewhere useful.

### 10. Home: useful first screen
- [ ] If no hero CMS: logo + short Bangla promise + category grid (no empty marketing paragraph wall)
- [ ] If hero exists: one clear Bangla CTA (**কেনাকাটা করুন** → categories)

**Acceptance:** Home’s first job is “pick a category,” not “admire a campaign.”

---

## Optional polish (P3)

- [ ] Bangla digit support in price display where helpful (optional; Western digits are widely OK for prices)
- [ ] Voice-call FAB always visible on long pages
- [ ] PDP lightbox only if it does not preload huge images
- [ ] `saveData` / low-data mode
- [ ] Accessibility: focus states, sufficient contrast on gold buttons with Bangla type

---

## Suggested implementation order

1. **Task 1** — Bangla copy layer (unblocks everything customer-facing)
2. **Task 3** — Image defaults / sizes / share thumbs (bandwidth)
3. **Task 2** — Simple mobile nav + call entry points
4. **Tasks 4–6** — Share page
5. **Tasks 7–8** — PDP + checkout clarity
6. **Tasks 9–10** — Findability / home
7. P3 as capacity allows

## Out of scope

- Full bilingual toggle (English mode) unless later requested — default Bangla
- Admin UI translation
- New payment gateways
- Replacing BDIX with a foreign CDN
