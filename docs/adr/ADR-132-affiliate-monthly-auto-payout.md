# ADR-132: Affiliate monthly auto-payout — the system opens one withdrawal ticket per affiliate on the 1st; the manual request is removed

> **Standing (2026-10-30):** Accepted (design). Revises [ADR-059](./ADR-059-reseller-portal.md) (59c: the affiliate no longer *requests* a withdrawal; the approve → complete flow and the maker-checker are unchanged) and uses [ADR-056](./ADR-056-reseller-wholesale-pricing-subscription-tiers.md)'s tier fee (the fee is reserved out of the payout).

**Status:** Accepted (design), 2026-10-30. Grilled with the founder, then code-traced by three read-only reviewers, one per layer (creation and reserve; approve and accounting; portal, admin and hygiene). Their findings are folded into the decisions below.

## Context

Today an affiliate can request a payout of any amount at any time (`POST /affiliate/withdrawals`, ADR-059 59c). An earnings balance can sit for months. The founder wants the payout run by the platform: a ticket is opened automatically each month, and the admin transfers it in a fixed window. **The money concept does not change**: the ledger debit still happens only at admin approval, the maker-checker (WTH-5) is unchanged.

What the code does today (traced 2026-10-30):

- `AffiliateWithdrawalService::request()` locks the affiliate's `ledger_accounts` row, checks balance and "no Pending/Approved ticket", and creates a `Pending` `Withdrawal` with `requested_by = null`.
- `Admin\WithdrawalController::approve()` is where `LedgerService::withdraw()` debits. It fails with a 422 if the balance is below the amount; the ticket stays Pending. `reject()` and `approve()` only act on Pending, `complete()` only on Approved.
- `app:charge-affiliate-tier-fees` runs `daily()` at 00:00 UTC (08:00 MYT). `AffiliateTierFeeService::chargeCycle()` debits the fee from the earnings balance, sets `next_charge_at = now + CYCLE_DAYS (30)`, and on an insufficient balance moves the subscription to `grace` (`GRACE_DAYS = 3`, `next_charge_at` is **not** advanced), then `lapsed`.
- The app runs in UTC; `MalaysiaTime::TIMEZONE` is the business timezone. "Inactive" on an `Affiliate` only ever means soft-deleted, and deletion requires a zero balance.
- Production, 2026-10-30 (read-only): 2 affiliates, none with a balance of RM10 or more, no open tickets, 1 tier subscription. No backfill is needed.

## Decision

1. **The affiliate can no longer request a withdrawal.** `POST /affiliate/withdrawals`, `Affiliate\WithdrawalController::store()`, `CreateAffiliateWithdrawalRequest`, the portal form and the `Testing/AffiliateWithdrawalTestRequest` command are removed. The portal keeps the history table and shows the schedule.
2. **One ticket per affiliate per month, opened by a scheduled command.** The command runs **daily at 00:10 MYT** for the current Malaysia month (not only on the 1st): a missed 1st self-heals the next day, and the second run of a day changes nothing. It is `CRITICAL` in `TaskSchedule`.
3. **Idempotency by the database.** A new nullable `withdrawals.payout_period` (`YYYY-MM`) with a unique index on `(owner_type, owner_id, payout_period)`. Manual-era rows keep NULL. A Rejected ticket therefore does **not** reopen in the same month; its money simply rolls into next month's payout (founder, 2026-10-30).
4. **Cut-off is the 1st at 00:00 Malaysia time.** Orders after it belong to next month. The ticket amount is computed inside the same locked transaction as the create (the existing `ledger_accounts` mutex pattern):
   `amount = min(balance at cut-off, current balance) − fee reserve`.
   The `min` closes the case where a late or retried run sees a fee debit made after the cut-off. The cut-off balance extends `LedgerService::balances()` with an optional `$before` argument; there is no second SUM.
5. **Fee reserve: the tier fee is taken first, as a reserve.** The reserve is the sum of the tier fees that will be charged before the next month's cut-off, counted from `max(next_charge_at, cut-off)` in `CYCLE_DAYS` steps (a 30-day cycle can fall twice inside a 31-day month). Free tier, `lapsed`, or no subscription = 0. A `grace` (overdue) subscription is charged from the cut-off, so it reserves at least one fee. The cycle constant is `AffiliateTierFeeService::CYCLE_DAYS` and the fee is read the way `chargeCycle()` reads it (`tier` with trashed, `monthly_fee_sen`); nothing is copied. No change to `ChargeAffiliateTierFeesCommand` or `chargeCycle()`.
6. **Minimum RM10 (1000 sen), applied to the amount after the reserve.** Below it, no ticket; the balance accumulates.
7. **Skip rules** (each reported with its reason): `no_bank` (any of the three bank fields empty), `below_min`, `open_ticket`. **Open means Pending only.** An Approved ticket is already debited, so it does not block. A Pending ticket still open after the 7th is shown as a "stale ticket" warning to the admin.
8. **Window.** The admin transfers within the 1st to 7th. Approve → complete is unchanged, as is the maker-checker. For a system ticket `requested_by` and `affiliate_user_id` are null, so above the threshold a Super Admin approves but not a *different* one (the same as every affiliate request today).
9. **One computation, two consumers.** A single `AffiliatePayoutService` computes `{balance, reserve, amount, skip_reason}` per affiliate. The scheduled command uses it to open tickets and a read endpoint `GET /withdrawals/payout-run` uses it for the admin worklist; the portal gets a `payout` block (`min_payout_sen`, `reserved_for_fee_sen`, `fee_due_at`, `has_bank_details`) from the same service. The frontends never recompute the reserve.
10. **Admin worklist.** The Withdrawals index payload adds `affiliate{id,name}` and `payout_period` (the table is anonymous today). The page gets Affiliate and Period columns, a "this month" filter, and a section for affiliates skipped as `no_bank`, `below_min`, or with a stale ticket. Built with the existing PrimeReact kit and the `/orders` pattern. Logic lives in a service, not in `Admin\AffiliateController` (already past 500 lines).
11. **Portal.** The Withdrawal page shows the schedule note (a ticket opens on the 1st, transfer within the 1st to 7th, minimum RM10), "RM X scheduled for transfer" while a ticket is open, and "RM X kept for the tier fee (due DD/MM)". "Withdrawable earnings" is relabelled "Earnings balance". No notification is sent when a balance is below the minimum or a ticket is skipped (founder); the Withdrawal page carries the schedule note instead. A banner asks for bank details when `has_bank_details` is false. Only the fee amount and date are shown, never `markup_percent` (platform cost structure stays private).
12. **Out of scope.** Affiliate/Reseller onboarding is a separate feature (PRD §16 item 146); until then the `no_bank` flag and the banner are the whole mechanism. Resellers (Purchase Balance) cannot withdraw and are untouched.

## Rationale

- **Reserve instead of an early charge:** charging every fee before the sweep shifts billing cycles and still leaves fees due later in the month payable only from new earnings, which would push affiliates into `grace`. Holding the fee back leaves `chargeCycle()` and the fee command untouched, and the balance left behind always covers the fee.
- **Daily command with a period key** rather than a monthly cron: one missed run would mean no payouts for a month; with the unique index the repeat is free.
- **`min(cut-off, current)`:** the only debit between cut-off and run is a fee already reserved, and a refund or clawback must not make the ticket exceed the balance.
- **Pending-only blocking:** the old `hasOpenRequest` predicate would block an affiliate forever behind a ticket that cannot be approved.

## Consequence to track

- **Accepted risk (admin action, clear 422, never negative):** a tier change to a higher fee after the ticket opens, a "Charge Now", or `reactivate()` can push the balance under the ticket. The admin rejects with a note and the money rolls to next month.
- **Pending-only blocking** means a ticket left Pending past the 7th is visible but not auto-resolved.
- **A fee charged 1 to 7 days after the cut-off** is covered by the reserve; it is not charged early.
- **Tests that encode the manual request change or go:** `AffiliateWithdrawalTest::test_store_*`, `AffiliateWithdrawalConcurrencyTest` (rewritten around the opener); the ADR-127 scenario seeder (`MoneyFlows::affiliateWithdraws`) now writes the Pending ticket directly, so the scenario picks the amount, and approves and completes it as before; the opener's own rules are proven in `AffiliateMonthlyPayoutTest`.
- **Docs to update with the build:** ADR-059 addendum, PRD RES-6/WTH-1..5/§15/§16, `docs/adr.md` rows, `build-log.md`.
- **Reserve invariant test:** a table over active / grace / lapsed / free / no subscription, run against `chargeCycle()` itself, proving the balance left after approve covers the next fee.
