# ADR-124: Customer account credentials — own encrypted table, super-admin reveal with MFA step-up and an audit row, wiped by one sweep once the order closes

> **Standing (2026-10-17):** Accepted (design). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-10-17. Grilled with the founder in two rounds (`/mattpocock-skills:grilling`, Q1–Q13) after a by-hand check of the order lifecycle and every exposure path. Build not started (PRD §16 item 112). Shared foundation for [ADR-125](./ADR-125-login-top-up-manual-supplier.md) (Login Top-up) and [ADR-126](./ADR-126-joki-mlbb.md) (Joki).

**Context:**

- Two planned features need the customer's game-account login, not a UID: Login Top-up (Roblox, eFootball, where the external supplier logs in to top up) and Joki (a penjoki logs in to play ranked). This is the first time the platform would hold a customer's password.
- A read-only survey of the code on 2026-10-17 (three forks, one per layer, top findings re-checked by hand) found:
  - `Order` has no `$hidden`. The admin order list returns the raw paginator (`Admin/OrderController::index`) and the detail spreads `...$order->toArray()` (`OrderController.php:812`). The sandbox list does the same. **A credential column on `orders` would reach every admin on every list load.**
  - The checkout idempotency hash (`CheckoutController::payloadHash`) is an unsalted SHA-256 over input fields. A password in it would be stored as a guessable hash.
  - `CustomerNotification.message` stores WhatsApp bodies, and `OrderStatusCard` interpolates `player_id`.
  - **Safe by construction, no change needed:** Track Order and Reverb (`customerSafePayload`), the affiliate and reseller portals and reseller webhooks (explicit `shape()` / `ResellerOrderPayload`), Horizon (`SerializesModels`, id only), the report assistant (`SqlGuard::ALLOWED_TABLES` curated views), the Excel export (explicit columns), exceptions/Pulse/Pail (no request-body logging).
  - Reusable primitives: `encrypted` casts (`Supplier.api_config`, `AdminUser.mfa_secret`), `admin.role:super_admin`, `mfa.stepup` (ADR-121, pinned by `StepUpRoutesTest`), and the precedent of leaving a table out of the DB dump (`dump.exclude_tables = ['player_validations']`).
  - No general audit table exists.
- Pre-grill check of order lifecycle and exposure (2026-10-17, by hand):
  - **`delivery_status` is written in about eight places** (`FulfillOrderJob`, `OrderFulfillmentService`, `OrderPaymentOutcomeService`, `ReconcilePendingDeliveriesCommand`, `Admin/OrderController`, `OrderFactory`, settlement, sandbox). `OrderStatusService` only validates transitions and writes nothing. Hooking a wipe into each writer would be a patch in eight places that the next new writer forgets.
  - **Failed is not final.** `startDelivery` accepts Failed and NeedsReview (retry), and `OrderResendService` resends only from Failed / NeedsReview. Wiping at Failed would break a retry. Compensation adds no status of its own: it is `Order::isAlreadyCompensated()` (voucher, wallet refund or voucher restored), with a query-level mirror per ADR-108.
  - **Abandoned checkouts:** `ReconcilePendingPaymentsCommand` checks only `payment_status = pending` orders that have a `payment_ref`. An order whose gateway call never happened stays `pending` forever, and so would its credentials.
  - The idempotency hash (`CheckoutController::payloadHash`) lists its fields explicitly, so a new field is excluded unless someone adds it.
  - Free-text admin `<script>` injection was removed by ADR-101 decision 9. The storefront still loads per-brand GA / Facebook / TikTok pixels (`AffiliateSeoSettings`), which do not read `type=password` inputs.
  - Encryption: `AES-256-CBC` with `APP_KEY`, and `config/app.php` `previous_keys` supports rotation. A separate key would sit in the same `.env`, so it adds nothing.
  - Every named scheduled task already gets an ADR-122 heartbeat. Pruning is a daily named command per table (`app:prune-player-validations`, `app:prune-report-assistant-audit-logs`, …).

**Decision:**

1. **Own table `order_credentials`**, one row per order (FK, unique), never columns on `orders`. The model has **no `credentials` relation on `Order`**; only `OrderCredential → order` exists, so no `->with()` or `toArray()` on an order can pull it.
2. **Per-field `secret` flag** from the game's credential schema ([ADR-125](./ADR-125-login-top-up-manual-supplier.md)). Secret fields (password, backup codes; a `password`-type field is always secret) are stored as one `encrypted` JSON (APP_KEY, `previous_keys` rotation). Non-secret fields (user ID, username, login email, login type) are plain and shown normally. `orders.player_id` carries the main non-secret identifier, so the blacklist, Track Order, reports and the WhatsApp status card work unchanged (Q5).
3. **Excluded from the DB dump** (`dump.exclude_tables`, like `player_validations`). A restore loses in-flight secrets; the customer re-sends them through WhatsApp CS (Q6).
4. **Kept out of every derived value:** the idempotency hash keeps its explicit field list, no log line or exception context includes credential fields, and no notification template can reach `order_credentials`.
5. **Reveal:** `super_admin` only (Q1), behind MFA step-up as a sixth guarded action (ADR-121). The value shows for 30 seconds with a copy button, then hides; each reveal writes a `credential_reveals` row (admin, order, field, IP, time). Reveals are rate-limited per admin (default 20 an hour) (Q9).
6. **Replace:** a `super_admin` can replace a secret field on an open order (customer typo, changed password, Moonton reset), behind the same step-up, and it writes a `credential_reveals` row with action `replace` (Q8).
7. **Wipe by one sweep with one rule**, not by hooks in each status writer. A named command `app:wipe-order-credentials` runs every 15 minutes (ADR-122 heartbeat, critical). It nulls the secret fields and sets `purged_at` when, by one query-level scope on `Order`:
   - the order is **closed** (Delivered, or compensated per `isAlreadyCompensated()`) and at least **G hours** have passed (default 24, Q2);
   - `payment_status = failed` (immediately);
   - the order is still unpaid **H hours** after creation, including orders with no `payment_ref` (default 24, Q3);
   - the order is still open (Failed / NeedsReview / Pending) **D days** after creation, as a hard ceiling (default 14, Q4). A retry after that needs the customer to send the login again.
8. **Consent:** a required checkbox before payment. `order_credentials` keeps `consented_at` and a snapshot `consent_text`, neither encrypted nor wiped (Q7, Q11).
9. **Customer copy:** the delivered WhatsApp message for a credential order says the login will be wiped within G hours and asks the customer to change their password. No separate "wiped" message (Q13). OTP / 2FA codes are asked for through WhatsApp CS by hand.
10. **Settings** in Admin → Settings → "Customer Login Policy" (super_admin, MFA step-up): G, H, D, the consent text and the reveal rate limit (Q12).
11. **Audit retention:** `credential_reveals` rows are pruned after one year by a daily named command, the same pattern as `app:prune-report-assistant-audit-logs` (Q10).

**Rationale:** a separate table makes every existing `toArray()` path safe without anyone having to remember `$hidden`. Wiping at the order's end means a later leak exposes only in-flight orders. Reveal-with-audit reuses ADR-121's step-up instead of inventing a permission system.

**Consequence to track:**

- `StepUpRoutesTest` must list the new reveal, replace and policy-settings routes.
- One invariant test: every wipe rule in decision 7 wipes, and an open Failed / NeedsReview order inside D days keeps its secrets.
- When Sentry is set up (§16 item 11), request bodies must stay off (`send_default_pii` false, no body capture on checkout).
- Retrying a checkout with the same idempotency key and a corrected password returns the first order with the first password. CS fixes it through WhatsApp.
- No `credentials` relation may ever be added to `Order` (decision 1); a test asserts it.
- A "request OTP from customer" button is a later item, not part of this ADR.

**Build prerequisite:** the founder's `super_admin` account must have MFA enabled, or every reveal is refused (ADR-121). Check before the build.
