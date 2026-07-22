# Public Pages UI/UX — Implementation Todo

Audience-first backlog for Sundoritoma storefront + public share.

**Primary audience:** Bangladeshi shoppers on mobile, often limited formal education, often on slow / metered mobile data. Prefer **clear Bangla**, **simple navigation**, and **reliable image loading** over a “premium” look.

Status: **implemented on branch `cursor/bangla-ux-storefront-6039`**.

---

## Design principles

1. **Bangla-first UI**
2. **Simple over gorgeous**
3. **Mobile-first, low literacy**
4. **Bandwidth-honest images** (BDIX-stable `www.sundoritoma.com` URLs)

---

## Shipped

### P0 — Bangla copy
- [x] `lang/bn/storefront.php`
- [x] `SetStorefrontLocale` (storefront `bn`, admin `en`)
- [x] Buyer chrome + auth + account + checkout + share translated
- [x] Prefer `description_bn` on PDP

### P0 — Simple nav
- [x] Sticky bottom: হোম | ক্যাটাগরি | কার্ট | কল
- [x] Guest mobile menu with shop links
- [x] Single hero image; category-first home

### P0 — Images
- [x] Listing default `_sm` + xs/sm/md srcset
- [x] PDP `_md` main / `_sm` thumbs
- [x] Share small thumbs; print CSS

### P1 — Share / PDP / checkout
- [x] Share brand + expiry CTAs + sticky totals + print
- [x] Sticky mobile buy bar + SVG wishlist
- [x] Checkout Bangla; delivery guide after city/area

### P2 — Findability
- [x] In-stock filter + Bangla sort
- [x] Empty search recovery
- [x] Home logo fallback

## Later (P3)

- [ ] `saveData` / কম ডাটা mode
- [ ] PDP lightbox without huge preloads
- [ ] Optional Bangla digit prices
