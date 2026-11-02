# ADR-135: A pending Purchase Balance top-up can be resumed or cancelled, and a cancel is decided by CHIP

> **Standing (2026-11-02):** Proposed. Extends [ADR-073](./ADR-073-reseller-wallet.md) (PR-G decision 8, one pending top-up per reseller), [ADR-076](./ADR-076-reseller-bot-v2.md) (PR-H decision 3, the bot hands back the pending link) and [ADR-110](./ADR-110-chip-integration-hardening.md) (the 2026-10-29 "a dead link is never handed out" rule; PR-B settlement matching). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Proposed, 2026-11-02; Accepted only after the stress test (one read-only review per layer) and its findings are folded in. Money-critical (Purchase Balance). Grilled with the founder in Malay (bot cancel, resume bank, settlement gap, remedy), with a code-trace pass over every writer and reader of `wallet_topup_attempts.status` (initiate, webhook, reconcile, bot, portal, receipts, register, closure, settlement) and a CHIP test-mode probe. The founder delegated the purely technical points (decision 8). PRD §16 item 24.

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

1. **One seam answers "what is this reseller's pending top-up".** A new `ResellerWalletTopupService::currentPending(Reseller): ?WalletTopupAttempt` first runs the existing `failPendingIfFinal()`, then returns the live pending attempt. That is a `pending` row with `expires_at` in the future and a `checkout_url`.
   - The portal's `GET /wallet` returns it as `pending_topup` (reference, amount, total charged, channel, expires at, plain checkout URL).
   - The 422 body carries the same object.
   - The bot's `PendingWalletTopupAlreadyExistsException` handler calls it, and its own duplicate query is deleted.
   - From then on the two channels cannot drift, and a dead link is never handed out (ADR-110 2026-10-29).
2. **Resume is just that link.** No new payment state and no new CHIP purchase. The portal shows a "Pending top-up" card with Pay now and Cancel. The FPX bank pre-selection of ADR-130 is not re-applied on resume: CHIP's page shows the bank grid. A bank chosen at the start lives only in that response. (Founder, 2026-11-02.)
3. **Cancel is decided by CHIP, never locally.** `ResellerWalletTopupService::cancel(Reseller, WalletTopupAttempt)`:
   1. The attempt is loaded tenant-scoped (the reseller's own, `pending`). Anything else is a 404 or 409.
   2. With no `chip_payment_ref` (the create response was lost), it refuses: "this top-up will expire by itself by HH:MM". There is no CHIP purchase to cancel, and the reconcile sweep already expires it.
   3. It calls the gateway's cancel **outside any lock** (the ADR-110 rule: no CHIP call while the wallet mutex is held), then **re-reads the purchase with `getPayment()` and acts only on that answer**. The cancel response is never parsed for meaning, so a 400 for "already cancelled", "already paid" or "error" all land in one decision:
      - final failure (`failure_is_final`: `cancelled`, `expired`, `overdue`, `blocked`, or single-attempt `error`): `leavePending(Failed)`. The conditional update loses safely to a Paid that committed first.
      - `paid`: no local write. The reseller is told the payment already went through and the balance appears shortly. Crediting stays with the webhook and reconcile, the two existing paths with the amount check; the cancel path never credits.
      - anything else (`pending_*`, `hold`, a CHIP error): refuse ("the payment is being processed at your bank, wait for it to finish"). The attempt stays `pending` and reconcile settles it as today.
   4. It is logged with the reseller id and reference ("cancelled by reseller").
4. **The gateway seam gains `cancelPayment(string $paymentRequestId): PaymentResponse`.** It is a real capability of the gateway; `ChipGateway` implements it with `POST /purchases/{id}/cancel/` and `FakePaymentGateway` mirrors it. ADR-001's thin-seam rule is kept: one method, normalised like `getPayment()`.
5. **No new attempt status.** A cancelled attempt is `failed`, like every other attempt whose CHIP purchase died; `ChipGateway` already maps `cancelled` to Failed. Receipts, the transaction register, closure and settlement already read `failed`, so nothing downstream changes. The reason lives in the log.
6. **The bot gets the same cancel** as `.batalbaki` (no argument: it cancels the group's reseller's current pending top-up), through decision 3's method, with a Malay reply per outcome. It needs no argument because a reseller can never have two pending top-ups (ADR-073 PR-G decision 8): a `.topupbaki 150` while `.topupbaki 100` is pending is refused with the RM100 link, so `.batalbaki` always means that one. The reply names the amount and reference it cancelled. (Founder, 2026-11-02.)
7. **A settled payment whose local record is not paid is a variance** (closes the gap found above, for all three record types). `resolveMatch()` also reports whether the matched record is paid (order `payment_status = paid`, attempt `status = paid`). An unpaid match marks the settlement `variance`, and the detail screen tags the row "settled but not paid here". For a top-up, the row has a **Re-check with CHIP** action (super_admin, `mfa.stepup`): it calls `getPayment()` and, only when CHIP says `paid` with the amount equal to `total_charged_sen`, runs `ResellerWalletService::completeTopup()`, the same locked, once-only seam the webhook and reconcile use. A late webhook then finds the attempt `paid` and does nothing. The admin's manual wallet credit is **not** the remedy: it is not tied to the attempt, so a webhook arriving later would credit the same payment twice (founder, 2026-11-02). An order or a membership row is flagged only; its remedy stays as today (a voucher, ADR-004). The month-end close is the last safety net behind the webhook and reconcile, so it must not report a clean match on money this platform never credited.

8. **Technical points settled in the trace** (delegated by the founder):
   - The portal route is `POST /reseller-portal/wallet/topup/{reference}/cancel`, throttled like the top-up route, and scoped by the token's reseller (another reseller's reference is a 404).
   - `.batalbaki` uses `.topupbaki`'s group-to-reseller resolution, its deactivated-reseller check and its own rate limit (`reseller-bot-batalbaki:{id}`, 5 per minute), since every call reaches CHIP.
   - Tests come first: the cancel outcomes table (cancelled, paid, in-flight, CHIP error, no purchase id, another reseller's attempt), a cancel racing a paid webhook (the Paid write wins, the cancel leaves nothing), and the settlement variance on an unpaid match for all three record types.
   - Build order: PR 1 the gateway method, `currentPending()`, `cancel()`, portal endpoint and UI; PR 2 `.batalbaki`; PR 3 the settlement variance and Re-check action.

## Rationale

- **One seam for "current pending" and one for "cancel"** follows the rule that every reader routes through one place. The bot's existing copy is the drift this removes.
- **CHIP decides, because the danger is a link that stays payable.** Re-reading after the call makes the outcome depend on CHIP's state, not on CHIP's error wording. That holds for the cases the probe could not create (a payment in flight at the bank).
- **The cancel path never credits.** Crediting already has two guarded paths with an amount check. A third would be a copy to drift (the item 63 lesson).
- **Reusing `failed`** leaves every existing reader of the status (receipts, the transaction register, closure, settlement) unchanged for a label none of them would treat differently.
- **Decision 7 sits in this ADR** because this design leans on the backstop chain (webhook, then reconcile, then the month-end settlement), and the trace showed the last link reports clean on unpaid money. Fixing it in `resolveMatch()`, the one place every settlement row goes through, covers orders and memberships too.

## Consequence to track

- PRD §16 item 24 closes when this is built. The portal's 422 for a pending top-up becomes a card, not an error.
- A `mark_as_paid` on a cancelled purchase is a merchant action. The founder should never use it for a top-up. If it is used anyway, the webhook credits it, and decision 7 flags it at the month end if the webhook was lost.
- Not built: cancelling a storefront order's or a membership's pending purchase. A guest just starts a new order and the old one expires, so nothing is blocked.
