# ADR-124: Customer account credentials — own encrypted table, super-admin reveal with MFA step-up and an audit row, wiped by one sweep once the order closes

> **Standing (2026-10-23):** In force; the 2026-10-18 addendum revises decision 7; the 2026-10-19 addendum replaces the supplier link's signed URL with a short hashed token (ADR-125 decisions 50–52); the 2026-10-23 addendum stores the wipe ceiling per row (ADR-126). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

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

### 2026-10-17 addendum — a one-time supplier link is the second reader of the secret fields

Decided in [ADR-125](./ADR-125-login-top-up-manual-supplier.md)'s grill (Q13) and shaped by its stress test. Overrides decision 4's "no notification template can reach `order_credentials`" for this one case.

1. A supplier's order post (ADR-125 decision 13) carries a **signed URL** (`URL::temporarySignedRoute`, the pattern `OpsAccessController` already uses) for (order, supplier), served on the API domain from `routes/web.php`. No token table.
2. **GET shows only the order summary and a "Show login" button**, with `noindex`, `Cache-Control: no-store` and `Referrer-Policy: no-referrer`, so a WhatsApp link-preview fetch never sees a secret.
3. **The secret fields are returned only by a POST**, rate-limited per order, which re-checks that the order is still open, belongs to that supplier, and has `purged_at` null. The link therefore dies the moment the sweep wipes (decision 7), with no revoke list. Its expiry is the D ceiling.
4. Each POST writes a `credential_reveals` row with actor = supplier (the group's supplier). The link may be opened more than once while the order is open; every opening is logged.

### 2026-10-18 addendum — build split, and five corrections found by a code trace and stress test before the build

Before building, every decision was traced against the code and stress-tested (founder, 2026-10-18). Overrides the matching parts of decisions 7 and the "Consequence to track" list.

1. **Build split.** ADR-125 bundled this ADR into its PR 1. It is built first, as its own PR (PRD §16 item 112): tables, encryption, dump exclusion, sweep, reveal/replace, the Customer Login Policy settings, the audit prune, and the admin UI. Nothing writes an `order_credentials` row in production until item 113 adds the checkout path, because which fields are secret comes from ADR-125 decision 6's per-game schema.
2. **A failed payment is not final.** `OrderPaymentOutcomeService` accepts a Paid that arrives after a Failed (the order goes to NeedsReview and an admin delivers it), so wiping at `payment_status = failed` could wipe the login of a customer who paid. Decision 7's second and third rules become one: **not paid (pending or failed) and more than H hours since the order was created.** CHIP purchases are `due` +30 minutes with `due_strict` (`ChipGateway`), so a late Paid can only land within minutes; H is at least 1 hour.
3. **"Closed for G hours" needs a closing time no column has.** Delivered has `delivered_at`, but a compensation's time is spread over three tables. The sweep stamps `order_credentials.closed_at` the first time it sees the order closed and wipes once `closed_at` is G hours old. Closed is final: Delivered has no exit in `OrderStatusService`, and a compensated order is refused by `fulfill()` itself and by every resend, retry and settle guard (ADR-102 decision 1). The stamp can be up to 15 minutes late, which only keeps a login longer.
4. **The D ceiling counts from `secrets_set_at`** (when the secret fields were last stored or replaced), for any order, not only an open one. Counting from the order's creation would wipe a login again 15 minutes after a super_admin replaced it on an order still open after D days. A replace on an already-wiped row is allowed only while the order is open.
5. **One query-level "compensated" rule.** `Order::scopeCompensated()` is the query mirror of `isAlreadyCompensated()`. `scopeNeedsAction()` uses it instead of its own inline copy, and the sweep uses it too. A test holds the scope and the method in agreement.
6. **`credential_reveals` records `actor_type` + `actor_id`** (admin now, supplier from ADR-125 PR 2, per the 2026-10-17 addendum), so the supplier link needs no migration on the audit table. The step-up route pin lives in `AdminMfaStepUpTest::test_exactly_these_routes_require_step_up`; there is no `StepUpRoutesTest`.

Also recorded: hiding the value after 30 seconds is a UI courtesy only, since the value has reached the browser. The controls are super_admin, step-up, the rate limit and the audit row, and the reveal response is `Cache-Control: no-store`.

### 2026-10-19 addendum — the supplier link becomes a short hashed token

Decided in [ADR-125](./ADR-125-login-top-up-manual-supplier.md)'s PR 2 pre-build addendum (decisions 50–52, founder). Overrides points 1 and 3 of the 2026-10-17 addendum.

1. **The link is a short token, not a signed URL.** `https://<api host>/s/<12 random base62 chars>`; `supplier_login_links` stores only the token's SHA-256 with the order, supplier, reference and expiry (the D ceiling). A signed URL reads as noise to an outside supplier, and the stored hash keeps a working link out of every DB dump. This is a token table, which the 2026-10-17 addendum had avoided.
2. **"Still open" means Pending on that supplier with that reference**, plus `purged_at` null. A Failed order keeps its login for a retry, but its old link is dead.
3. The link is minted when the post is sent and never stored in the message text.

Points 2 and 4 (GET shows no secret; each POST is audited with actor = supplier) are unchanged.

### 2026-10-23 addendum — each login stores its own wipe ceiling (ADR-126 2026-10-23 addendum, decision 22)

The D ceiling becomes a per-row `order_credentials.secrets_expire_at`, computed by one helper from the game's optional "keep login at most (days)", else the Customer Login Policy's D. The sweep and the supplier link read it. Existing rows are backfilled with `secrets_set_at + D`. G, H and everything else here are unchanged.
