# ADR-124: Customer account credentials — own encrypted table, super-admin reveal with MFA step-up and an audit row, wiped at the order's end

> **Standing (2026-10-17):** Proposed (design, grill pending). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Proposed, 2026-10-17. Shape agreed with the founder in chat; the grill (`/mattpocock-skills:grilling`) is still to run. Shared foundation for [ADR-125](./ADR-125-login-top-up-manual-supplier.md) (Login Top-up) and [ADR-126](./ADR-126-joki-mlbb.md) (Joki).

**Context:**

- Two planned features need the customer's game-account login, not a UID: Login Top-up (Roblox, eFootball, where the external supplier logs in to top up) and Joki (a penjoki logs in to play ranked). This is the first time the platform would hold a customer's password.
- A read-only survey of the code on 2026-10-17 (three forks, one per layer, top findings re-checked by hand) found:
  - `Order` has no `$hidden`. The admin order list returns the raw paginator (`Admin/OrderController::index`) and the detail spreads `...$order->toArray()` (`OrderController.php:812`). The sandbox list does the same. **A credential column on `orders` would reach every admin on every list load.**
  - The checkout idempotency hash (`CheckoutController::payloadHash`) is an unsalted SHA-256 over input fields. A password in it would be stored as a guessable hash.
  - `CustomerNotification.message` stores WhatsApp bodies, and `OrderStatusCard` interpolates `player_id`.
  - **Safe by construction, no change needed:** Track Order and Reverb (`customerSafePayload`), the affiliate and reseller portals and reseller webhooks (explicit `shape()` / `ResellerOrderPayload`), Horizon (`SerializesModels`, id only), the report assistant (`SqlGuard::ALLOWED_TABLES` curated views), the Excel export (explicit columns), exceptions/Pulse/Pail (no request-body logging).
  - Reusable primitives: `encrypted` casts (`Supplier.api_config`, `AdminUser.mfa_secret`), `admin.role:super_admin`, `mfa.stepup` (ADR-121, pinned by `StepUpRoutesTest`), and the precedent of leaving a table out of the DB dump (`dump.exclude_tables = ['player_validations']`).
  - No general audit table exists.

**Decision:**

1. **Own table `order_credentials`**, one row per order (FK, unique). Fields use the `encrypted` cast. The schema is per game (ADR-125 decision 4), stored as an encrypted JSON of `{field_key: value}` plus a `purged_at` timestamp. Never a column on `orders`.
2. **Excluded from the DB dump** (`dump.exclude_tables`), like `player_validations`. A restore loses in-flight credentials. The customer re-sends them through CS, which is the accepted cost.
3. **`orders.player_id` carries the non-secret identifier** (the login username, user ID or email the game shows), so the blacklist, Track Order, reports and the WhatsApp status card keep working unchanged. The secret fields never touch `orders`.
4. **Kept out of every derived value:** the idempotency hash covers the non-secret fields only, no log line or exception context includes the request's credential fields (a hidden-key list on the FormRequest plus Laravel's `dontFlash`), and no notification template can reach `order_credentials`.
5. **Reveal = `super_admin` + MFA step-up (a sixth guarded action under ADR-121) + one `credential_reveals` row** (admin, order, field, IP, time). The admin order detail shows field names and a "Reveal" button, never the values, unless the founder says otherwise for a given role at grill time.
6. **Wiped at the order's end:** the secret fields are nulled and `purged_at` set the moment the order reaches a terminal state (Delivered, Failed, Settled/compensated, Cancelled). A daily sweep wipes anything still un-purged more than **N days** after its terminal state, as a safety net (N is a panel setting, default 7). The `credential_reveals` rows stay, as the audit trail.
7. **After delivery the customer is told to change their password** (one line in the delivered WhatsApp message for credential-carrying orders).

**Rationale:** a separate table makes every existing `toArray()` path safe without anyone having to remember `$hidden`. Wiping at the order's end means a later leak exposes only in-flight orders. Reveal-with-audit reuses ADR-121's step-up instead of inventing a permission system.

**Consequence to track:**

- `StepUpRoutesTest` must list the new reveal route.
- A new code path that serialises an order must never eager-load `credentials`.
- 2FA/OTP codes (Moonton, Roblox new-device checks) are asked for through WhatsApp CS by hand. A "request code from customer" button is a later item, not part of this ADR.

**Open for the grill:**

- Does a plain `admin` (not `super_admin`) ever need to reveal, for example a staff member doing joki or Login Top-up fulfilment?
- Is wipe-at-terminal right for joki, where a customer may dispute the rank reached a day later? (The wipe removes only the password; user ID and username stay.)
- Is the sweep's default 7 days right?
