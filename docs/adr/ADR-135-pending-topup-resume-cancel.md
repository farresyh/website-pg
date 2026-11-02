# ADR-135: A pending Purchase Balance top-up can be resumed or cancelled, and a cancel is decided by CHIP

> **Standing (2026-11-02):** In force. Extends [ADR-073](./ADR-073-reseller-wallet.md) (PR-G decision 8, one pending top-up per reseller), [ADR-076](./ADR-076-reseller-bot-v2.md) (PR-H decision 3, the bot hands back the pending link) and [ADR-110](./ADR-110-chip-integration-hardening.md) (the 2026-10-29 "a dead link is never handed out" rule; PR-B settlement matching). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-11-02, by the founder after the grill and the stress test. Money-critical (Purchase Balance). Grilled with the founder in Malay (bot cancel, resume bank, settlement gap, remedy), with a code-trace pass over every writer and reader of `wallet_topup_attempts.status` and a CHIP test-mode probe, then stress-tested by three read-only reviews (payment state and races; settlement and accounting; surfaces and readers) whose findings are folded into the decisions below. The founder delegated the purely technical points. PRD §16 item 24.

## Context

A reseller adds Purchase Balance through CHIP from two channels, the portal's Balance page and the WhatsApp bot's `.topupbaki`. Both call the one seam, `ResellerWalletTopupService::initiate()`. It allows exactly one `pending` `wallet_topup_attempts` row per reseller for 30 minutes, under the wallet `ledger_accounts` mutex. A second attempt throws `PendingWalletTopupAlreadyExistsException`. The payment rail is already shared with the storefront: one `ChipGateway`, the same purchase rules (`due` +30 min, `due_strict`, `single_attempt`), one webhook controller, and a reconcile sweep per record type.

The two channels differ only in what a reseller sees when a top-up is already pending:

- **Bot:** hands back the pending attempt's own link (ADR-076 PR-H decision 3), through its own copy of the "current pending attempt" query in `ResellerBotService`.
- **Portal:** a bare 422 ("already has a pending top-up attempt #N"). `GET /reseller-portal/wallet` does not return the pending attempt, so the reseller has no link and no way out for up to 30 minutes.
- **Neither channel can cancel.**

ADR-110's 2026-10-29 addendum already removed most of the pain. `single_attempt` makes CHIP cancel a purchase after one failed bank attempt, and `failPendingIfFinal()` fails such an attempt before the 422. What is left is a reseller who opened the link and closed it without trying, or who wants a different amount or method.

Production, read-only on 2026-11-02: 9 top-up attempts ever, from 3 resellers (3 paid, 4 failed, 2 expired). 7 of the 9 came through the bot, and no reseller ever started two attempts within 30 minutes. A portal 422 creates no row, so blocked attempts are not measurable. This item prepares for partner onboarding (ADR-133). It is not a live incident.

### What CHIP offers (spec and test-mode probe, 2026-11-02)

CHIP's OpenAPI spec (`docs.chip-in.asia/openapi/chip-collect.yaml`) has `POST /purchases/{id}/cancel/`: "If a Purchase is still payable, this request guarantees that it cannot be paid". Its status becomes `cancelled` and `purchase.cancelled` is emitted. The spec also warns that `cancel_redirect` does **not** cancel: the customer can press Back and pay. A probe with the test-mode key (`is_test: true`, no callback URL, 1.00 MYR purchases) found:

| Case | CHIP's answer |
| --- | --- |
| Cancel a `created` purchase | 200, `status: cancelled` |
| Cancel it again | 400, `purchase_cancel_wrong_status` ("Only purchases that can be paid for can be canceled.") |
| Cancel a `paid` purchase | 400, `purchase_cancel_wrong_status`; it stays `paid` |
| Cancel an unknown id | 404, `not_found` |
| `mark_as_paid` on a `cancelled` purchase | 200, `status: paid` |

The last row matters. A cancelled purchase can still become `paid` by a **merchant** action (`mark_as_paid`, from the API or CHIP's portal), even though a customer can no longer pay it. A purchase sitting at a bank (`pending_execute`) cannot be produced in test mode, so the design never assumes what cancel does to one.

### What the code trace found

- **A local-only cancel can strand paid money.** The webhook credits a paid event even when the attempt is `failed` or `expired` (`ChipWebhookController::handleWalletTopupAttempt` short-circuits only on `paid`), so a late payment is honoured. But `ReconcilePendingWalletTopupsCommand` only sweeps `pending` rows. So if an attempt were marked failed locally while its CHIP link stayed payable, and the webhook for a later payment were lost, the reseller's money would be taken and never credited. No sweep would ever look at that row again.
- **The month-end settlement check would not catch it either.** `SettlementReconciliationService::resolveMatch()` matches a settled CHIP transaction to its order, membership attempt or top-up attempt by id alone. It never checks that the local record is paid, so a settled payment whose record is `failed`, `expired` or unpaid shows as a clean "matched". This gap exists today for all three record types (a webhook rejected 409 for an amount mismatch, or a merchant `mark_as_paid` on a dead purchase), not only for a cancel.

## Decision

Revised 2026-11-02 after the stress test (three read-only reviews: payment state and races; settlement and accounting; surfaces and readers). The founder's choices from the grill are marked; the rest are technical points the founder delegated.

### Reading and resuming

1. **One seam answers "what is this reseller's pending top-up", and it never calls CHIP.** `ResellerWalletTopupService::currentPending(Reseller): ?WalletTopupAttempt` is a database read: the `pending` row whose `expires_at` is in the future, **with or without a link**. `GET /reseller-portal/wallet` returns it as `pending_topup` (reference, amount, total charged, channel, expires at, and whether a link exists). It runs on every balance and dashboard load, so a CHIP call there would put CHIP's 10-second timeout and retries on each page view (stress test). A row with no link (the create response was lost) shows "Setting up, expires HH:MM" with no buttons. Without it, `initiate()` would block the reseller with nothing on screen.
2. **Resume verifies only when asked.** `POST /reseller-portal/wallet/topup/{reference}/resume` runs the existing `failPendingIfFinal()` for that attempt and returns the live link, or "this top-up has ended, start a new one". That keeps ADR-110's 2026-10-29 rule (a dead link is never handed out) without a CHIP call per page load. No new payment state and no new CHIP purchase. The link is the stored plain URL and CHIP's page shows the bank grid. A bank picked at the start is not re-applied (founder).
3. **The 422 stays plain text.** The portal's `ApiError` keeps only the message, so a `pending_topup` in the 422 body would be dropped. On a 422 from `/wallet/topup` the page re-fetches `GET /wallet` and shows the card. `GET /wallet` is the one source.
4. **The bot renders the attempt the lock already found.** `PendingWalletTopupAlreadyExistsException` carries the attempt that `initiate()` found under the wallet mutex, and `ResellerBotService` renders it. Its duplicate query is deleted, and no second CHIP call is made (`initiate()` has already run `failPendingIfFinal()`).

### Cancelling

5. **Cancel is decided by CHIP, never locally.** `ResellerWalletTopupService::cancel(Reseller, WalletTopupAttempt)`:
   1. The attempt is loaded tenant-scoped (the reseller's own, `pending`). Another reseller's reference is a 404.
   2. With no `chip_payment_ref`, it refuses: "this top-up will expire by itself at HH:MM". There is no CHIP purchase to cancel, and reconcile already expires it.
   3. It calls `cancelPayment()` **outside any lock** (the ADR-110 rule), then **re-reads with `getPayment()` and acts only on that answer**. The cancel's own response is never parsed, so CHIP's 400 for "already cancelled" or "already paid" lands in the same decision:
      - final failure: the attempt leaves `pending` with the status from decision 6.
      - `paid`: no local write. "The payment already went through; your balance will show shortly." The cancel path never credits.
      - anything else (`pending_*`, `hold`, a CHIP or network error): refuse ("the payment is being processed, wait for it to finish"). The row stays `pending` and reconcile settles it.
   4. When `leavePending()` returns false (the webhook, reconcile or a second cancel got there first), it re-reads the row and reports what it actually is.
   5. It logs the reseller id, the reference and "cancelled by reseller".
6. **One function names a dead attempt's status.** `expired` and `overdue` become `expired`; `cancelled`, `blocked` and single-attempt `error` become `failed`. `failPendingIfFinal()` and `cancel()` both use it, so the same CHIP state never gets two labels. No new status: every reader filters on `paid` or `pending` only (receipts, the transaction register, settlement, closure), no admin screen lists attempts, and portal history shows ledger rows. A `cancelled` label would change nothing anyone sees.
7. **The gateway seam gains `cancelPayment(string $paymentRequestId): PaymentResponse`.** `ChipGateway` implements it with `POST /purchases/{id}/cancel/`, normalised like `getPayment()`. A retried POST is harmless, because the cancel is idempotent and the re-read decides. `FakePaymentGateway` remembers cancelled ids and reports them as `cancelled`; today its `getPayment()` always says paid, which would make every e2e cancel say "already paid". PR 1 updates every class that implements or mocks `PaymentGateway`: the 4 `app/Console/Commands/Testing` commands, `PaymentGatewayFactoryTest`, `CheckoutControllerFixtures`, `Adr094ComboConsumingChannelsTest` and `CheckoutControllerFpxBankTest`. Otherwise they fail to load.
8. **The bot gets `.batalbaki`** (founder), with no argument. A reseller can never have two pending top-ups (ADR-073 PR-G decision 8): a `.topupbaki 150` while `.topupbaki 100` is pending is refused with the RM100 link. So `.batalbaki` always means that one, and the reply names its amount and reference.
   - It goes through decision 5 with its own rate limit (`reseller-bot-batalbaki:{id}`, 5 per minute).
   - Unlike `.topupbaki`, it is **allowed for a deactivated reseller**. Cancelling only reduces exposure, and closure is blocked while a top-up is pending (`ResellerClosureService`), so blocking it would keep a payable link alive on the account being wound down.
   - Any member of the linked group may issue it: the same trust as `.topupbaki` and `.order` (the bot resolves the reseller from the group, not the sender).
   - Help text gets the line, and failures log `topup_cancel_none` and `topup_cancel_in_flight`.
8a. **A group-only reseller sees everything a portal reseller sees** (founder, 2026-11-02). Some resellers will use only the WhatsApp group, so every state change has a bot message. The wording below is the reference text; the build may tidy it but not drop facts.
   - `.topupbaki` reply: the existing message, with "Link tamat pada HH:MM" (MYT, from `expires_at`) and "Nak batal? Taip .batalbaki".
   - Already pending (`.topupbaki` again): "Anda masih ada satu tambah baki yang belum dibayar (tamat HH:MM)", the amount, the reference and the link, then "Bayar guna link di atas, atau taip .batalbaki untuk batal dan buat yang baharu". A pending row with no link gets decision 1's "sedang disediakan, tamat sendiri pada HH:MM".
   - `.baki`: the balance, plus one line when `currentPending()` returns a row: "Tambah baki belum dibayar: RMx (Ref …, tamat HH:MM). Taip .batalbaki untuk batal." It is a database read, like the portal card.
   - `.batalbaki`, one reply per decision 5 outcome:
     - cancelled: "…telah dibatalkan. Link lama tidak boleh dibayar lagi. Anda boleh buat .topupbaki yang baharu."
     - already paid: "…sudah dibayar, jadi tidak boleh dibatalkan. Baki anda akan dikemas kini sebentar lagi."
     - in flight: "…sedang diproses oleh bank. Tunggu sehingga selesai, kemudian semak dengan .baki."
     - none pending: "Tiada tambah baki yang belum dibayar."
     - no link: "…sedang disediakan dan akan tamat sendiri pada HH:MM."
   - Help text (`commandList()`): adds `.batalbaki — batal tambah baki yang belum dibayar`.
   - **Pushed to the group, for a top-up started in the group only** (a `reseller_bot_wallet_topups` row exists):
     - cancelled from the portal: "Tambah baki RMx (Ref …) telah dibatalkan melalui portal."
     - failed at the bank or expired: "Tambah baki RMx (Ref …) tidak berjaya / tamat tempoh. Anda boleh cuba semula dengan .topupbaki."
     - Because CHIP sends no failure callback, this arrives when reconcile (or the next `.topupbaki` / resume) finds it: up to about 15 minutes late. A cancel made with `.batalbaki` gets no push, because its own reply already says it.
     - If a payment still lands after the attempt ended (decision 10), the existing "BAKI DITAMBAH" follows. Both messages are true.
8b. **One ended seam, like the one paid seam.** Today four writers move an attempt out of `pending` to failed or expired:
   - `failPendingIfFinal()`
   - the webhook's failure branch
   - reconcile
   - the gateway-call failure in `requestPayment()`

   They will all call `ResellerWalletTopupService::markEnded(attempt, status, cause)`. It does the conditional `leavePending()` (decision 6 picks the status) and, only when it won, tells `ResellerBotWalletTopupNotifier::notifyEnded()`. That mirrors `completeTopup()` calling `notifyPaid()`. `notifyEnded()` locks the tracking row and sends once, guarded by a new nullable `reseller_bot_wallet_topups.ended_notified_at` (an additive migration), through the same queued `OpenWaClient::sendText()` → `SendResellerBotReplyJob` path. The cause (`portal_cancel`, `bot_cancel`, `bank_failed`, `expired`) picks the wording, and `bot_cancel` sends nothing. No writer sends a WhatsApp message itself.
9. **The portal's top-up routes get named limits.** Resume and cancel each make CHIP calls, and `/wallet/topup` is unthrottled today, so a script can mint unlimited CHIP purchases (pre-existing; the bot caps the same action at 5 per minute). `POST /wallet/topup`, `/resume` and `/cancel` get `throttle:N,1,name` limits matching the bot. The pending card replaces the Add Balance form inside its `Panel`, built from the `components/ui` kit (ADR-038; a new primitive goes in the admin kit, then `sync-shared-files.mjs`).

### The backstops behind a cancel

10. **Reconcile also re-checks recently ended attempts.** The webhook credits a paid event even on a `failed` or `expired` attempt, but `ReconcilePendingWalletTopupsCommand` sweeps only `pending`. Without this, a payment that lands after the attempt ended, with its webhook lost, waits for the month-end settlement. That could happen after a merchant `mark_as_paid` (probed), or a bank payment CHIP settles after the cancel (not producible in test mode). The sweep also asks CHIP about attempts that left `pending` in the last 24 hours and have a `chip_payment_ref`. Paid goes through the same paid seam (decision 11). This adds no new state.
11. **One paid seam per attempt type, with the amount check inside.** Today the top-up amount check is copied in the webhook and in reconcile, and the membership one likewise (orders were moved to `OrderPaymentOutcomeService::applyPaid` in item 63). New `applyPaid(attempt, amountSen, feeSen)` methods for top-ups and memberships mirror the order seam. Each returns an outcome (credited, already paid, amount mismatch, credited to a closed account), and the webhook, reconcile and the Re-check action all call it. No caller writes its own copy.
12. **A settled payment whose local record is not paid is shown live, not stored.** A new `SettlementReconciliationService::settledButUnpaid()` joins `chip_settled_transactions` to each record type and returns those not paid. It is worked out on every view, like the existing `paidButNotSettled()`. The settlement's stored `status` is not changed, because it is written once at upload and would stay "variance" after the record was fixed. No migration. The detail screen tags those rows "settled but not paid here".
13. **Re-check with CHIP fixes it through the record's own paid seam, for all three types** (founder chose the action for top-ups; the stress test found the same seam already exists for orders). It is `POST /accounting/settlements/transactions/{chipSettledTransaction}/recheck`, super_admin plus `mfa.stepup`. It calls `getPayment()` and routes by type:
    - order → `PaymentReconciliationService::reconcileOrder()` (Paid after Failed becomes `paid` plus NeedsReview under the order lock)
    - membership → its `applyPaid`
    - top-up → its `applyPaid`

    It shows the outcome, including "credited to a closed account: refund the payer" (ADR-131 decision 6). Idempotency comes from the seams (locked, once only). The admin's manual wallet credit is **not** the remedy: it is not tied to the attempt, so a webhook arriving later would credit the same payment twice (founder). A voucher is not the remedy for an order either: a voucher answers a failed delivery, not a payment we never recorded.

### Build order

14. Tests come first: the cancel outcomes (cancelled, paid, in flight, CHIP error, no purchase id, another reseller's attempt, lost race), a cancel racing a paid webhook (Paid wins), the 24-hour re-check, each `applyPaid` outcome, and `settledButUnpaid()` per type.
    - **PR 1:** the paid seams (decision 11). A refactor with no behaviour change, proved by the existing webhook and reconcile tests.
    - **PR 2:** the gateway method, `currentPending()`, resume, cancel, the status function, throttles, the portal card, and the 24-hour re-check.
    - **PR 3:** `.batalbaki`, the bot replies and pushes of decision 8a, and the ended seam of decision 8b (its migration). The ended seam can move into PR 2 if the build finds the portal cancel needs it first.
    - **PR 4:** `settledButUnpaid()` and Re-check.

## Rationale

- **Every reader and writer routes through one seam:** current pending (decision 1), the dead status (decision 6), the ended notice (decision 8b), the paid outcome per type (decision 11), and the settlement check (decision 12). The two copies the trace found (the bot's pending query and the amount checks) are removed rather than joined by a third.
- **CHIP decides a cancel, because the danger is a link that stays payable.** Re-reading after the call makes the outcome depend on CHIP's state, not its error wording, and that holds for the cases the probe could not create.
- **Page loads never wait on CHIP.** A read is a database read; CHIP is asked only when someone acts.
- **The backstop chain is closed end to end:** the webhook, then reconcile (now including 24 hours after an attempt ends), then the month-end settlement (now flagging money we never recorded, live, with a one-click fix through the same seams).

## Consequence to track

- PRD §16 item 24 closes when this is built. The portal's 422 for a pending top-up becomes a card, and a group-only reseller gets the same facts in the group (decision 8a).
- A Re-check credit is dated when the Re-check runs: a top-up's accounting date is its ledger credit (ADR-127 F5), while an order takes CHIP's `paid_on`. The Monthly Summary's processing gain/loss counts our fee on an unpaid matched row until it is fixed. Both are small, and both are correct once the row is fixed.
- A `mark_as_paid` on a cancelled purchase is a merchant action. The founder should never use it for a top-up. If it is used anyway, the webhook, then decision 10, then decision 12 catch it.
- Pre-existing, not in this ADR: `createPayment()` is a POST that retries on a connection error, so CHIP could hold two purchases under one reference while only one link is stored. The orphan was never handed out, so no one can pay it. Recorded here so it is not rediscovered as new.
- Not built: cancelling a storefront order's or a membership's pending purchase. A guest just starts a new one, and nothing is blocked.

### 2026-11-02 addendum — what the build changed

Recorded while building PRs 1–4 (#655, #656, #657, and PR 4). Every decision above stands, except where noted here.

- **Decision 13, orders:** Re-check calls `OrderPaymentOutcomeService::applyPaid()` directly, not `PaymentReconciliationService::reconcileOrder()`. That is the seam `reconcileOrder()` itself calls on Paid, with the same lock and the same Paid-after-Failed → NeedsReview branch. Calling it directly lets Re-check report the order's own outcome (sent for delivery, sent to Needs Review, amount mismatch) without changing `reconcileOrder()`'s return. All three record types now take the same path: ask CHIP, and on Paid hand the answer to the type's `applyPaid()`.
- **Decision 13, gateway:** Re-check always asks CHIP. A settlement row is a CHIP payment by definition, so the gateway is not looked up per record.
- **Decision 13, route:** the route is in `AdminMfaStepUpTest`'s pinned set of step-up routes.
- **Decision 8a, wording:** a `.batalbaki` that races the webhook or reconcile and finds the top-up already ended gets its own reply: "…sudah tamat dan tidak boleh dibayar lagi". A pending top-up with no link, hit by `.topupbaki` again, gets decision 1's "sedang disediakan" text; until now it got "Gagal memulakan". The pending-top-up exception's message, shown in the portal toast, is now reseller-facing.
