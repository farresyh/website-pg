# Foundation Security & Financial-Integrity Checklist

**How to use this file:** before implementing (or reviewing) any feature that touches authentication, money, pricing, supplier calls, or customer PII, check it against every rule below that applies. This is the concentrated, actionable form of the security posture defined across [`prd.md`](./prd.md) (§9 Non-Functional Requirements) and [`adr.md`](./adr.md) (why each rule exists). If a change to this file is needed, it should come from a deliberate decision — see `adr.md` for the pattern (add a new entry, don't silently rewrite history).

---

## 1. Authentication & Access

- [ ] Super Admin and Admin accounts require MFA (TOTP) — enforced before any withdrawal/voucher/refund-affecting action. (AUTH-7)
- [ ] Every backend endpoint enforces role-based access control server-side. Frontend route guards are convenience only, never the actual gate.
- [ ] Withdrawals above a configured threshold require a **second, different** Super Admin's approval (maker-checker) — the requester can never be the sole approver. (WTH-5)
- [ ] Vouchers above a configured threshold can only be created by a Super Admin — a **single-step role gate**, not the same two-person check as WTH-5. Deliberate deviation, decided 2026-07-24: voucher issuance is a store-credit obligation (never cash-out, per ADR-004), judged lower-stakes than an actual withdrawal, so the extra friction of a separate approver wasn't required. If this changes, update this line and `prd.md`'s VCH-6 row together — don't let them drift apart again. (VCH-6)
- [ ] Reseller impersonation sessions (Phase 2) are fully audit-logged: real admin identity, session start/end, visible "impersonating" banner, every action tagged. (RES-4)

## 2. Money & Pricing

- [ ] Price, fee, profit, and cost are always computed **server-side** at transaction time from stored config. No endpoint accepts a client-submitted monetary value as authoritative. (ORD-9)
- [ ] Balance for any owner (platform, or reseller in Phase 2) is derived from `SUM(LedgerEntry)` — never read from or written to a mutable `balance` column. (D2 / ADR-002)
- [ ] Every money-moving action (order profit credit, withdrawal debit, voucher issuance) writes an immutable `LedgerEntry` row. Ledger rows are never updated or deleted, only inserted.
- [ ] No cash-refund code path exists anywhere in the system. The only two resolutions for a failed order are retry-delivery or voucher issuance. (D4 / ADR-004)
- [ ] Voucher redemption decrements `remaining` via an atomic, row-locked operation — verify this cannot allow two concurrent redemptions to both succeed against an insufficient remaining balance. (VCH-5)

## 3. Idempotency & Reconciliation

- [ ] Every order generates a unique `reference_number` **before** the first supplier API call. Any retry of that same logical order reuses the same reference — it is never regenerated. (ORD-8)
- [ ] Payment webhook/callback handling is idempotent: receiving the same event twice must not create a duplicate order or duplicate credit delivery. (PAY-2)
- [ ] The system never treats a supplier or payment-gateway webhook/callback as the sole source of truth for final state. A scheduled reconciliation job independently polls/confirms ambiguous or stuck transactions. (ORD-10, PAY-3)

## 4. Fraud Prevention

- [x] Every order creation checks the customer/player against the internal blacklist before payment or supplier submission proceeds. (FRAUD-1, FRAUD-2 — `BlacklistService`/`CheckoutController::assertNotBlacklisted()`, 2026-07-29)
- [x] The checkout/payment endpoint has its own, stricter rate limiting / velocity check distinct from general API rate limiting, to reduce card-testing (carding) abuse. (FRAUD-4 — `CheckoutVelocityGuard`, counts only blacklist-triggered rejections per IP, distinct from `throttle:10,1`)
- [x] Blacklist entries always require a recorded reason and are attributable to the admin who added them. (FRAUD-3 — `blacklist_entries.reason`/`created_by`, `/admin/blacklist`)

## 5. Payment Gateway Integrity (Xendit)

- [ ] All Xendit webhook/callback payloads are cryptographically verified before being trusted. An unverified callback is logged and discarded — never actioned. (PAY-1)
- [ ] The platform never touches raw card data directly. Card entry happens only via Xendit-hosted fields/redirect — this keeps the platform out of PCI-DSS scope. (PAY-4)
- [ ] xenPlatform (sub-account splitting) is out of scope until Phase 2 — do not wire it in early "just in case." (D1 / ADR-001)

## 6. Supplier Integration

- [ ] No business logic reads a raw supplier response directly — every supplier is accessed only through its Adapter, which normalizes to one canonical internal shape. (ADAPT-1, ADAPT-2)
- [ ] Never assume a supplier's auth scheme, response envelope, or validation-endpoint availability is uniform with any other supplier. Confirm per-supplier, per-game during onboarding. (D6 / ADR-006)
- [ ] Validation-capability (`supports_validation`) is tracked per game-supplier mapping, not as a blanket flag on the Supplier record. (GAME-12)
- [x] A circuit breaker trips per-supplier after repeated failures — a down supplier must not be able to cascade into blocking the whole delivery queue. (`App\Services\CircuitBreaker\CircuitBreaker` + `CircuitBreakingSupplierAdapter`, 2026-07-29 — see ADR-019's newest addendum)
- [ ] If a supplier requires IP whitelisting, its Adapter routes through the one shared outbound proxy config (`config/services.php['proxy']`), never a supplier-specific, one-off proxy setup. (ADR-006 addendum)

## 7. Secrets & Data

- [ ] Supplier credentials (`api_config`) and payment gateway secrets are encrypted at rest, never returned in full via any API response, and never shown unmasked in Developer Tools. (SUPP-5, DEV-2)
- [ ] Request logs redact credentials and excess PII before being written. (MID-10)
- [ ] Player/customer PII returned from validation calls (e.g. in-game nickname, country) is retained only as long as operationally needed — not logged or stored beyond what's needed to confirm the order to the customer.
- [ ] No raw credentials are ever written to logs, error messages, or client-visible responses.

---

*Cross-reference: functional requirement IDs (e.g. `ORD-9`, `PAY-1`) point to their definitions in [`prd.md`](./prd.md) §6. Decision IDs (e.g. `D2`, `ADR-002`) point to full rationale in [`adr.md`](./adr.md).*
