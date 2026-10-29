# ADR-131: CHIP activation conditions — "Purchase Balance", top-up and balance limits, top-up receipts, account closure, and a payment gate on affiliate domains

> **Standing (2026-10-29):** Accepted (design). Extends [ADR-073](./ADR-073-reseller-wallet.md) (the prepaid balance gets a display name, limits, receipts and a closure path) and adds an addendum-level change to [ADR-060](./ADR-060-multi-tenant-branded-storefront-custom-domain.md) decision D ("no admin gate" no longer holds for *payments*). Does not touch [ADR-004](./ADR-004-no-cash-refunds-ever.md) or PRD §16 item 129.

**Status:** Accepted (design), 2026-10-29. Grilled with the founder (two rounds, `/mattpocock-skills:grilling`), then code-traced by three read-only reviewers, one per layer (limits and checkout; ledger and closure; downstream readers and public surface), per the money-critical rule in the root `AGENTS.md`. The reviewers' top findings were re-checked against code by the author (`LedgerService::credit()` takes no lock; `OrderSettlementService::settle()` has no reseller-state check; `ResellerController::destroy()` is a non-atomic read that revokes nothing). Nothing here is built.

## Context

CHIP is reviewing LWF Group Sdn. Bhd.'s merchant account. Its latest reply makes activation of the payment methods conditional on seven things, summarised:

1. **Rename "Wallet" / "Wallet balance" / "Top up"** on the reseller portal to "Purchase Balance" or a neutral equivalent ("wallet" and "e-wallet" suggest a regulated e-money product), and confirm in writing: the credit buys goods or services on our own platform only; it cannot be cashed out, withdrawn or transferred; there is a reasonable cap on top-ups (max per top-up, max balance per customer); refunds go back to the original payment method only.
2. **An invoice or receipt for every top-up.**
3. **A maximum per top-up, blocked by the system.** "No maximum, the payment provider's limit is the only ceiling" is not acceptable.
4. **The reseller onboarding procedure**, the documents collected from each reseller, and the list of current resellers.
5. **No affiliate or branded store may use CHIP without CHIP's prior written approval**; every new domain is submitted before it accepts payments.
6. **Screenshots** of the updated portal showing the rename and the limits.

What the code does today (all traced 2026-10-29):

- "Wallet" and "Top up" appear in the portal (nav, dashboard, `/wallet`, order detail, toasts, ledger labels), the Reseller API's error text and Scramble descriptions (and so the generated `openapi.json` and the docs-site), the WhatsApp bot's replies, and the **description sent to CHIP** (`"PekanGame reseller wallet top-up (ref)"`).
- `TopupRequest` has `min:1000` and no maximum; the portal says "Your bank's own limit is the only maximum". No balance cap exists. A fourth place, the bot, accepts up to RM9,999,999 by regex.
- The balance is raised by **three writers that all call `LedgerService::credit()`, which takes no lock**: `completeTopup` (CHIP webhook and reconcile), `manualCredit` (admin, bank transfer), and `wallet_refund` (`OrderSettlementService::settle()`). Only `debit()` and `ResellerWalletTopupService::initiate()` lock the `ledger_accounts` mutex row.
- There is no system receipt. `WalletTopupReceipt` is an optional proof file an admin attaches to a manual credit.
- There is no account-closure path. `ResellerController::destroy()` soft-deletes at a zero balance, reading the balance without a lock, and revokes no API key, portal user, WhatsApp group or webhook.
- An affiliate adds a custom domain from the portal with no admin step (`Affiliate\DomainController`, ADR-060 D). An `Active` domain is served and can take a CHIP payment.
- Reseller wallet orders never call CHIP; only wallet top-ups do. The customer-side `createPayment` callers are `CheckoutService` (checkout and resume) and `MembershipSubscriptionService`.
- The Transaction Register shows CHIP top-ups (`WalletTopupAttempt`, Paid) but **not manual admin credits** (a bank-transfer cash-in with no attempt row).

## Decision

### Naming (CHIP condition 1)

1. **The customer-visible name is "Purchase Balance"; the action is "Add Balance"; the history is "Balance history".** "Top up" disappears from every string a Reseller sees. The scope is the reseller portal, the Reseller API's messages and Scramble descriptions (`openapi.json` regenerated; the `scramble-drift` CI job enforces it), the docs-site prose, the bot's reply text, and the description sent to CHIP (`"PekanGame purchase balance (ref)"`). The product "game top-up" is unchanged.
2. **What stays:** the admin panel, code names, `ledger_entries.type` values (`wallet_topup`, `wallet_debit`, `wallet_refund`), table names, the API's JSON field names (`wallet_refunded`, `wallet_refund`) and error code `INSUFFICIENT_BALANCE`, the bot commands `.baki` and `.topupbaki`, and the storefront's "e-Wallet" payment-method icon (a customer payment channel, not this feature). Public contract and internals do not churn for a display name.
3. **The portal route moves from `/wallet` to `/balance`, with a permanent redirect from `/wallet`.** An in-flight CHIP purchase (up to 30 minutes) stores `…/wallet?topup=success|failed` as its return URL; new attempts use `/balance`. The docs-site slug `wallet` keeps its URL and changes its label, or gets a redirect; its internal links are checked by `astro check`.

### Limits (CHIP condition 3 and the cap in 1)

4. **Per top-up: RM10,000. Balance cap: RM100,000 default, overridable per reseller.** Both numbers are configuration, not constants: `topup_max_sen` and `balance_cap_default_sen` on `PlatformSettings` (the singleton row, uncached, so no invalidation), and a nullable `resellers.balance_cap_sen` (null = the default). Validation: `balance_cap_default_sen >= topup_max_sen`. All three are changed through **dedicated routes behind `mfa.stepup`**, not the generic settings/reseller update, which writes every field in the payload and would reset an override that the payload omits.
5. **One `assertWithinLimits(Reseller, int $amountSen)`, called by `ResellerWalletTopupService::initiate()` and by `ResellerWalletService::manualCredit()`.** It takes the reseller's `ledger_accounts` row with `lockForUpdate()` (the mutex `initiate()` and `debit()` already use), reads the balance and the cap, and throws. `manualCredit` must open its own transaction and lock; today it does neither. Compared amount: the sum credited (`amount_sen`), never `total_charged_sen` (the channel fee is not credited). A pending attempt is not counted (it is not credited, and `initiate()` allows one pending attempt per reseller).
6. **`completeTopup` and the `wallet_refund` credit never refuse.** The money is already received or already owed. The overshoot is bounded by one in-flight top-up (≤ RM10,000) plus refunds. If either credits a reseller who is closed or soft-deleted, it logs at critical level so stranded money is visible. What we tell CHIP is accurate: limits are enforced when a top-up starts and when an admin credits; a payment already made is never rejected.
7. **Distinct exceptions, distinct replies.** Two subclasses of `InvalidWalletTopupAmountException` (over the per-top-up maximum; over the balance cap), each carrying the numbers. The portal maps both to 422 through the existing catch. The bot gets its own Malay replies and a pre-check like the existing minimum check; reusing the base class would answer a RM15,000 request with "minimum RM10". The portal reads the effective maximum and the reseller's remaining headroom from `GET /wallet`; the frontend keeps no second constant. The minimum (RM10) stays a constant, validated in `TopupRequest`, the service and the bot.

### Receipt (CHIP condition 2)

8. **A "Payment Receipt", not an invoice.** The balance is a liability, not revenue (ADR-083 decision 8; ADR-127 recognises revenue at the order). An invoice for a deposit implies a supply that has not happened. LHDN e-Invoice submission stays out of our code (ADR-083 decision 6). The receipt carries the note "Purchase Balance is prepaid credit. An invoice is issued for each order."
9. **One receipt per paid CHIP top-up and per manual credit,** generated with the already-installed `barryvdh/laravel-dompdf` (as `ReportController` does), from `resources/views/receipts/`. Number: `PB-<attempt reference>` or `PB-M-<ledger entry id>`; no sequence table. Endpoint `GET /api/reseller-portal/wallet/receipts/{ref}` inside the existing `auth:affiliate` + `account.type:reseller` group, filtered by the caller's own `reseller_id`; another reseller's receipt is a 404. A manual credit is a `wallet_topup` ledger entry whose `reference_type` is not `wallet_topup_attempt`, so a CHIP credit is never listed twice.
10. **The fee printed is `total_charged_sen − amount_sen`** (what the reseller actually bore). **Never `gateway_fee_sen`:** that is CHIP's fee to the platform, nullable, and platform cost structure is private. A manual credit has no fee line. Paid time is the ledger credit's `created_at` (the fact the Register and balance read, ADR-127 F5).
11. **Seller details** (legal name, SSM number, registered address) are three new fields on `PlatformSettings`, filled by the founder in `/admin/settings`. The receipt is rendered on demand from immutable facts plus the current seller details and reseller name. `ponytail:` if a reseller is renamed or the company address changes, an old receipt prints the new value; upgrade to a stored snapshot if the accountant requires it.

### Account closure (CHIP condition 1, refunds)

12. **"Close account" is the only way to remove a Reseller; it replaces the bare `ResellerController::destroy()`.** One action, `super_admin` + `mfa.stepup`, one `DB::transaction`: lock the wallet `ledger_accounts` row first; then check the preconditions; then write the debit; then deactivate and soft-delete; then revoke every API key and portal login, unlink the WhatsApp groups and disable the delivery webhook.
13. **Preconditions (a closure is refused otherwise):** no wallet order is non-terminal (`NotStarted`, `Processing`, `Pending`, `NeedsReview`); **no wallet order is `Failed` or `PartiallyDelivered` without its `wallet_refund` yet** (an open claim; `isCompensable()` and `isAlreadyCompensated()`); **no `WalletTopupAttempt` is `Pending`, whatever its `expires_at`** (a payment made at minute 29 with a delayed webhook is still pending in our database and would credit a closed account).
14. **The transfer happens first.** The form requires the date the money was sent (`transferred_on`) and the bank reference, and checks `AccountingPeriod::assertOpen(transferred_on)`. The debit is `wallet_closure_refund` for the whole balance, written only when the balance is above zero (no RM0 row), dated the same day as the cash. Reason: the cash equation reads the wallet claim from the ledger and cash from a manual snapshot; a debit written before the money leaves would show a false surplus. No "approved, not yet paid" state is built (affiliate withdrawals have one because they are frequent; closures are rare).
15. **A guard at the one seam.** `OrderSettlementService::settle()` refuses to credit a wallet of a closed reseller (`refundToWallet`, the voucher-from-order action and the test command all route through it). With decision 13, this closes the path that would re-credit a closed account.
16. **Idempotent.** A second call on a closed reseller is a 422, not a second debit (`debit()` has no idempotency key). The refund goes to the account the money came from, outside the system; nothing is refunded through CHIP.

### Transaction Register (accounting)

17. **Two rows added to `TransactionRegisterService`:** `walletClosureRows` (cash out, from the ledger entry, mirroring `withdrawalRows`), and `walletManualCreditRows` (cash in by bank transfer, from `wallet_topup` entries that are not tied to a CHIP attempt), the latter closing the pre-existing gap where the Register, meant to be complete, omits bank-transfer cash-in. The admin label and badge maps (`admin/src/lib/transaction-register.ts`, the transactions page) and the portal's `format.ts` get the new type. `WalletTopupAttempt::reseller()` and the Register's reseller lookups use `withTrashed()`, so a closed reseller is not shown as "unknown reseller".
18. **Proven by a `FinancialScenario` step** (ADR-127): top up, place an order, deliver it, fail and refund another, close with a bank reference; assert the cash-equation gap is unchanged, the Register net equals the cash movement and the wallet claim is zero. A negative step: closure refused while a `Failed` wallet order has no refund. Balance readers (Dashboard, Cash Position, Monthly Summary) sum the ledger and need no change.

### Domain payment gate (CHIP condition 5)

19. **A branded domain does not take a CHIP payment until it is approved.** `affiliate_domains.payments_approved_at` (nullable) and `payments_approved_by`. Browsing is unaffected (CHIP must be able to open the site to review it). The gate is **one method, `StorefrontBrand::assertPaymentsEnabled()`**, fed by `ResolveStorefrontBrand`, which already loads the matched `AffiliateDomain` row (no second query). It applies iff the request resolved a custom hostname; the primary host (`primary_hosts` config) resolves with no hostname and every non-storefront caller (console, queue, API, bot) has none, so no config list of exemptions is needed. A spoofed `X-Storefront-Host` cannot grant approval, because approval is read from the matched row.
20. **Where it is called:** the top of `CheckoutService::initiate()` (before the Order row exists, so no orphan Pending order; skipped when a voucher covers the whole order, since no CHIP call follows); the `payment_ref === null` branch of `CheckoutService::resume()`; and the top of `MembershipSubscriptionService::initiate()` (covers its replay, dedup and fresh paths). It throws a coded error the storefront turns into "Payments are not yet enabled on this store". `BrandingController` exposes `payments_enabled` so the storefront can say so before checkout.
21. **Approve and revoke:** `POST /admin/affiliates/{affiliate}/domains/{domain}/payments-approval`, `super_admin` + `mfa.stepup`, stored with the approving admin. Backfill: the rows of the `is_primary` affiliate are approved; every other row, including an `is_owned` brand's, stays null (CHIP's condition covers any branded domain). A purchase already created while a domain was approved stays payable until CHIP's `due` (about 30 minutes) after a revoke; accepted.
22. **Before the release,** a read-only production check of every `Active` domain: any besides the one being deactivated and the one submitted to CHIP would stop taking payments on deploy.

### Build and rollout

23. **Three PRs off `staging`, one release, one reply to CHIP.** (a) rename, limits, receipt; (b) account closure plus the two Register rows and the scenario; (c) the domain gate. They ship together; CHIP is answered once, after the release, with screenshots from production taken on a dedicated test reseller account (zero balance, an obviously-test name), never a real reseller's.
24. **Outside the repository** (the repo is public): the onboarding procedure, the written confirmations and any reseller documents or lists. Nothing identifying a real reseller or applicant is committed. The written confirmation states the refund rule in two parts so it cannot be read as a contradiction: a failed order is refunded to the Purchase Balance (an internal credit, ADR-073 decision 7, "no cash leaves the platform"); the unused balance is refunded to the original payment method **only on account closure**.
25. **Not changed:** ADR-004 and item 129 (the refund-to-original-method path for storefront orders) stay as they are; this ADR only cross-references them.

## What the code trace changed

- **The cap check cannot live in `TopupRequest` or only in `initiate()`.** `credit()` is lock-free, so a check in `initiate()` serialises only against other `initiate()` calls and order debits. Two admin credits, or a credit and a top-up start, could both pass and overshoot silently. Hence one `assertWithinLimits()` under the mutex, used by both (decision 5), and `manualCredit` now takes the lock.
- **The bot would have answered the wrong sentence.** Its existing catch of `InvalidWalletTopupAmountException` returns the "minimum" reply (decision 7).
- **The closure preconditions were too weak** ("non-terminal orders and unexpired attempts"): a `Failed` order not yet refunded is an open claim, and a pending attempt must block closure whatever its `expires_at` (decision 13).
- **Debit-then-transfer-later would have opened a false cash surplus** until the transfer was recorded; the order is reversed (decision 14).
- **`destroy()` and the new action would have been two ways to close**, drifting apart; there is one (decision 12).
- **The receipt's fee column had two candidates;** `gateway_fee_sen` is the platform's cost and must not be printed (decision 10).
- **The route rename would have broken in-flight CHIP returns** (decision 3).
- **The domain gate must run before the Order row exists,** or an unapproved store leaves orphan Pending orders (decision 20).
- **The "no maximum" and "no admin gate" statements in `foundation-security.md`, ADR-060 D, the `TopupRequest` docblock and the portal page are now false** and are corrected in the build PRs.

## Rationale

CHIP's conditions are narrow and mostly cheap: a display name, two numbers, a document, a closure path and a payment gate. The value is in doing each at the seam every caller already routes through, so that nothing is a patch: one limits function under the existing mutex, one gate method fed by the resolver that already loads the row, one closure action in place of the half-closing `destroy()`, one `settle()` guard, one Register mapping. A receipt, not an invoice, because the accounting model says the balance is a liability and the compliance question of e-Invoicing a deposit belongs to a reviewer, not to our code. Limits are configuration with a per-reseller override because the founder's rule is that decisions are changeable from the panel, and because a high-volume reseller legitimately needs a high balance; RM100,000 is a default, not a ceiling for everyone.

## Consequence to track

- **Tests (red first):** a concurrency test for `assertWithinLimits()` against `manualCredit`, `initiate()` and an order debit on the same reseller (real MySQL, `phpunit.concurrency.xml`); bot tests for both limit replies; closure tests for each precondition and the idempotent second call; the `FinancialScenario` step and its negative case; gate tests for checkout, resume, membership, voucher-covered order, primary host and a spoofed host.
- **Docs to update with the build:** `docs/foundation-security.md` (the limits; a domain-gate line; "no cash-refund code path" reworded to "no refund through the payment gateway"), ADR-060 (decision D addendum), ADR-073 banner, `prd.md` §13 (a "Purchase Balance" display-name note on the Glossary entries), §15 and §16 (items 13, 24, 104 use the new name), `README.md`, `docs/build-log.md`.
- **Open for a reviewer, not blocked on:** whether a Purchase Balance deposit needs an e-Invoice (extends item 74; the founder has no accountant yet, item 81). The receipt's title can change without changing its data.
- **Prod migrations:** `platform_settings` (five columns), `resellers.balance_cap_sen`, `affiliate_domains.payments_approved_at/by`. The backfill approves the primary affiliate's rows only; verify with `SHOW INDEX` as the migration rules require.
- **Item 129 stays on hold.** If it is built, closure and the refund-to-original-method path should share one refund record.
- **The gate protects payments, not browsing.** If CHIP later asks that an unapproved brand not be reachable at all, the resolver is the single place to change.

### 2026-10-29 addendum — what the closure build settled (PR B)

- **Transfer date (decision 14).** A ledger row is always dated now, so "debit the day the cash left" is enforced by the form instead: `transferred_on` must be a day of the current Kuala Lumpur month and not in the future, and both that day and today must be in an open accounting month. A refund sent on the last day of a month cannot be recorded in the next; close it before midnight (`ponytail:` in `ResellerClosureService`; add a dated ledger write if it ever bites). The transfer date and the bank reference are kept in the debit's `reason`, and the Register row prints it.
- **A repeat closure (decision 16).** The route no longer resolves a soft-deleted reseller, so a second call is a **404**, not a 422. The service keeps its own 422 ("already closed") for a repeat that races past the route. Either way there is no second debit (proved with two real processes).
- **The bare `DELETE /resellers/{id}` is removed** (a request now gets 405), so closure is the only way. A new read-only `GET /resellers/{id}/close-check` returns the balance and the blockers for the modal.
- **What blocks, as built (decision 13).** One query: a wallet order that is not `closed()` (delivered, or already compensated). That covers the four unfinished statuses and Failed or PartiallyDelivered with no refund yet. Any `Pending` Add Balance attempt, whatever its `expires_at`.
- **The one seam (decision 15).** `OrderSettlementService::settle()` refuses a closed reseller's order; `completeTopup` stays unconditional and logs `critical` if it credits a closed account.
- **The Register (decision 17)** gained `reseller_wallet_manual_credit` and `reseller_wallet_closure`. Production check before the build (read-only, 2026-10-29): 3 `wallet_topup` rows, all CHIP, 0 manual, so the new manual-credit rows change no existing figure.
- **Proofs.** `FinancialScenarioTest` closes the scenario reseller: the cash-equation gap is unchanged, and it **fails** if the bank movement is left out. Two real-process tests: a closure racing an order placement conserves money, and two closures refund once; both **fail with the two row locks removed**.

