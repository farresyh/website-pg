# ADR-065: Guest order-status detail — masked contact + payment breakdown on a track-by-number view

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — 2026-09-01, grilled with the founder. Extends (does not replace) `TrackOrderController`'s narrow-by-design shape. Ships on `feature/pekangame-storefront-redesign` alongside ADR-064.

**Context:**
- `GET /api/track-order/{orderNumber}` and its realtime twin `OrderStatusUpdated` (ADR-047) are guest-accessible: the `order_number` ULID (~80 bits random, `OrderNumberService`) is treated as proof of ownership — no email, no 2FA, the courier-tracking-number model (ADR-011: no customer accounts). The route is rate-limited `throttle:20,1,track-order`.
- Both producers deliberately return a **narrow, customer-safe subset** of `Order` — never `cost_price` / `standard_selling_price` / `platform_profit` / `reseller_profit` / `supplier_response` / `payment_ref` / `supplier_ref`. `TrackedOrderSchema` (`storefront/src/lib/track-order.ts`) is the one shared shape for the poll and the push — ADR-047's own lesson: one schema, two producers, or they silently drift.
- ADR-064 wants the Stitch "order status detail" layout — a bento grid of **Game & Package** / **Customer Info** / **Payment Details** cards. Customer contact and a payment breakdown are not in today's response.

**Decision:**

1. **Widen the guest order-status shape — masked contact + customer-facing payment breakdown, nothing internal.** Added to `TrackOrderController::show()`, `OrderStatusUpdated::broadcastWith()`, and `TrackedOrderSchema` together:
   - `customer_name_masked`, `customer_email_masked`, `customer_phone_masked` (nullable) — decision 2.
   - `payment_method` (the human channel label), `selling_price` (the price **actually charged** — member-aware, ORD-9-snapshotted; **never** `standard_selling_price`, the counterfactual), `voucher_discount`, `transaction_fee` (all integer sen). `final_amount` is already present.
   - **Not added:** `payment_ref` or any gateway transaction id. Grilling Q2 — the Stitch "Transaction ID" row is dropped; a customer raising a bank dispute contacts support, who has the ref.

2. **Masking is server-side and lives ONLY in these two producers.** Full `customer_*` values never leave the backend on this surface. The `Order` model is untouched — no accessor, no global mutation — so `$order->customer_email` stays full everywhere else (`/admin/orders`, `CheckoutService`, `OrderFulfillmentService`, notifications, `MembershipController`'s own-email order history, the ledger). A shared `App\Support\ContactMask` helper (`name()` / `email()` / `phone()`) backs both producers so the format can't drift between poll and push. Formats:
   - **name** → first token + last-token initial (`John Doe` → `John D.`); a single-token name renders as-is.
   - **email** → first char + `••••` + the full domain (`john@gmail.com` → `j••••@gmail.com`).
   - **phone** → first 2 + `•-•••-•` + last 3 (`0123456789` → `01•-•••-•789`); `null` → `null`.

3. **No new friction on the lookup.** The existing `throttle:20,1,track-order` stays unchanged — a polling customer uses ~3/min (20 s interval); an 80-bit ULID makes enumeration futile. Masked contact is a recognition aid for the buyer, not usable PII for a stranger, so email-gating every lookup (rejected in grilling Q4) would only tax the common case (a customer clicking their own status link).

4. **Storefront (ADR-064) renders the bento grid** — **Game & Package** (Game / Package / Player ID / Server ID), **Customer Info** (the three masked fields), **Payment Details** (Method / Package Price / Transaction Fee / Voucher Deduction) + a highlighted **Amount Paid** box. The stage tracker, "Need help?" sidebar and FAQ below are unchanged.

**Rationale:** The buyer typed their own contact details at checkout, so a masked echo is all they need to confirm "this is my order"; a stranger who obtains the order number — a forwarded screenshot is the realistic vector, not brute-forcing an 80-bit ULID — gets nothing actionable. Masking server-side keeps the full-PII blast radius exactly where it is today (the model, and every admin/ops path, is unchanged). The payment breakdown is money the customer already saw at checkout, shown back to them — not a new disclosure — while `standard_selling_price`, profit, and supplier fields stay out as always.

**Consequence to track:**
- `TrackedOrderSchema` grows: both producers (`TrackOrderController` + `OrderStatusUpdated`) must emit the new fields, or the shared-schema parse fails on whichever path is missed — the exact bug ADR-047 caught with `needs_review`. Handled by editing both in this change; a test on each locks it.
- `App\Support\ContactMask` is now the single masking implementation — any later "masked contact" surface reuses it rather than re-rolling the format.
- The decision is **not** to expose `payment_ref` here. If a future bank-dispute self-service want reopens that, it reopens decision 1's "nothing internal" line — record it, don't just add the field. The `standard_selling_price` / profit / supplier-field exclusions are untouched and load-bearing.
- Tests: `TrackOrderControllerTest` gains masked-shape assertions + a leak guard (no raw `customer_*` / `standard_selling_price` / `payment_ref` in the JSON); an `OrderStatusUpdated` payload test asserts the same shape so poll and push stay identical.
- PRD §14 build-log + §15 note owed on ship (folded into the ADR-064 storefront entry).
