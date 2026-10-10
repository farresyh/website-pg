# ADR-079: Storefront Conversion & Polish — Real Product Artwork, Dynamic Payment Channels & Official SVG Logos, Denomination-vs-Pass Package Tabs, and Guest Checkout Convenience

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Shipped — 2026-09-08, commit `52af736` on `feature/adr-079-storefront-polish`.

**Context:**
Following the storefront visual redesign (ADR-063/ADR-064) and production deployment on `pekangame.space`, a comprehensive UI/UX audit identified conversion friction and design gaps:
1. `ProductHeaderCard.tsx` on `/order/[slug]` hardcoded a 64×64px initial letter box (`game.name.charAt(0)`), completely ignoring `game.imageUrl` even when an image was uploaded in admin and returned by the public API.
2. `SiteHeader.tsx` on desktop contained duplicate "Track Order" links side-by-side (one text link in `<nav>`, one primary `<Button>`).
3. `PaymentMethodsSection.tsx` and `SiteFooter.tsx` consumed static mock arrays from `placeholder-data.ts` (showing TnG, GrabPay, Card) even when only FPX was active in the database. Furthermore, payment channels in checkout (Step 3) rendered as plain text buttons with no official brand logos, degrading buyer trust.
4. Games with dozens of packages (e.g. Mobile Legends with 60+ packages) rendered a flat list. High-margin, highly sought-after recurring packages (Weekly Diamond Pass, Twilight Pass) were buried among numerical denominations.
5. In `ReviewModal.tsx`, `customer_name` and `customer_phone` are strictly mandatory (CHIP payment gateway requires full name for FPX; Gamevion requires phone for automated fulfillment). However, guest users had to re-type these on every purchase.
6. The homepage `QuickCounterCard` required selecting a tile and then clicking a separate button; 1-click direct navigation is faster and more intuitive.
7. The order status tracker lacked a 1-click clipboard copy button for `order_number` and WhatsApp support links lacked pre-filled order context.

**Decision:**

1. **`ProductHeaderCard.tsx` artwork:** Render `game.imageUrl` inside the 64×64px rounded border frame (`neo-sm`) using `next/image`. Fall back to the stylized initial letter only when `imageUrl` is null.
2. **Desktop Header cleanup:** Remove the duplicate `<Link href="/track-order">Track Order</Link>` from the desktop `<nav>`, keeping the primary CTA `<Button>Track Order</Button>` intact.
3. **Dynamic Payment Methods & Official SVG Brand Logos:**
   - Wire `PaymentMethodsSection` and `SiteFooter` to `listPaymentChannels()` so they display only active channels (`is_active = true`), matching the checkout behavior.
   - Build a shared `PaymentChannelIcon` component in `storefront/src/components/icons/` with official, optimized SVGs for Malaysian payment brands (`chip_fpx` / FPX, `chip_touchngo` / Touch 'n Go eWallet, `chip_duitnow_qr` / DuitNow QR, `chip_grabpay` / GrabPay, and generic Card / Visa / Mastercard).
   - In Step 3 (`OrderForm.tsx`), render these brand badges inside the channel selection tiles.
4. **Denomination vs. Pass Package Tabs:**
   - In backend `CatalogController::publicPackage()`, expose `has_denomination` (`$package->denomination !== null`) and `has_catalog_code` (`$package->catalog_code !== null`).
   - In `storefront/src/components/order/PackageGrid.tsx`, implement a 3-tab filter:
     - **"All"** (default) — all packages.
     - **"Direct Top Up"** — packages where `hasDenomination === true`.
     - **"Pass"** — packages where `hasCatalogCode === true`.
   - The "Pass" tab is rendered only if at least one package has `hasCatalogCode === true`. The 12-item cut-off with "Show all" toggle is preserved for lengthy lists.
5. **Guest Checkout Convenience & Security:**
   - In `ReviewModal.tsx`, retain `customer_name`, `customer_email`, and `customer_phone` as mandatory fields to satisfy CHIP and Gamevion.
   - Add a client-side "Remember my details" option using browser `localStorage` (zero server/database load). On subsequent checkouts, fields are prefilled automatically.
   - Add reassuring micro-copy clarifying that name and phone are required for banking and WhatsApp delivery receipts.
   - Retain the explicit manual Terms & Conditions tickbox per founder's instruction.
6. **Quick Top-Up 1-Click Navigation:**
   - Tapping a game tile in `QuickCounterCard.tsx` immediately routes to `/order/${game.slug}`, eliminating the secondary button click.
7. **Order Status Polish:**
   - In `OrderStatusTracker.tsx`, add a 1-click "Copy" button next to `order_number` with an immediate "Copied!" visual cue.
   - Enhance the WhatsApp support link with pre-filled message text including the order number and game name.

**Rationale:**
- Displaying actual game artwork immediately establishes platform credibility and matches the founder's catalog updates.
- Dynamic payment methods prevent storefront-gateway divergence, and official SVG logos provide high conversion trust for Malaysian gamers without admin upload overhead or asset distortion.
- Differentiating between standard currency and passes/bundles organizes dense catalogs into immediate user intent without requiring complex database schema changes.
- Client-side `localStorage` persistence creates a seamless "faster checkout" experience for repeat guests without touching backend database state.
- Preserving the manual T&C tickbox maintains strict legal acknowledgment as requested by the founder.

**Consequence to track:**
- Backend `CatalogController::publicPackage()` gains two lightweight boolean keys; update `CatalogPackageWireSchema` in storefront Zod types.
- Ensure `localStorage` access is wrapped in `try/catch` and guarded against SSR hydration mismatch.
- Keep `docs/prd.md` §14 and §15 updated upon shipping.

### Shipped Addendum (2026-09-08)
Shipped in full on `feature/adr-079-storefront-polish`:
- **Backend:** `CatalogController::publicPackage()` exposes `has_denomination` & `has_catalog_code`. Full test suite passing (1,610/1,610 green; `CatalogControllerTest` coverage added).
- **Storefront:** `ProductHeaderCard` artwork rendering, `SiteHeader` nav link cleanup, `PaymentMethodsSection` & `SiteFooter` dynamic channels with `PaymentIcons` SVG badges, `PackageGrid` 3 tabs with collapse toggle, `ReviewModal` client-side `localStorage` contact persistence + reassuring copy + mandatory T&C, `QuickCounterCard` 1-click navigation, `OrderStatusTracker` 1-click order-number copy + contextual WhatsApp assistance. Clean `tsc`, `lint`, and `build` (Turbopack).
