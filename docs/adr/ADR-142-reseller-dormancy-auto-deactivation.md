# ADR-142: Reseller dormancy — a reseller with no transaction for a set number of days is warned, then deactivated, until an admin reactivates it

> **Standing (2026-11-16):** In force. Extends [ADR-072](./ADR-072-reseller-system-split.md) decision 9 (`resellers.is_active`, the account kill switch) with a reason, a history and one seam, and adds an automatic deactivation. Leaves [ADR-131](./ADR-131-chip-activation-conditions.md)'s Close account as the only way to remove a reseller and refund its balance.

**Status:** Accepted (design), 2026-11-16. Grilled with the founder in Malay (`/mattpocock-skills:grilling`, three rounds, 24 questions), then stress-tested against the code (every reader and writer of `resellers.is_active`, the order placement lock, the bot, the top-up start, the portal login). Decisions 1–14 are the founder's answers; 15–26 are the design the stress test settled. PRD §16 item 193.

## Context

**The founder's goal (2026-11-16).** Approved resellers who never use the platform should not stay switched on. A reseller with no transaction for 7 days straight is deactivated: no portal login, no bot or API order. It is not deleted: deleting means closing the account and refunding the Purchase Balance (ADR-131, CHIP's condition 1). The reseller asks PekanGame to switch it back on. A warning comes first, in the reseller's bot group and by email.

**Production, 2026-11-16 (read-only).** Three resellers, all active, none internal:

| Reseller | Approved | First order | Last order | Longest gap between order days | Balance |
| --- | --- | --- | --- | --- | --- |
| #1 | 5 Sep | 5 Sep | 5 Oct | 18 days | RM3.44 |
| #2 | 18 Sep | 19 Sep | 22 Oct | 20 days | RM7.45 |
| #3 | 31 Oct | 13 Nov | 15 Nov | 1 day | RM991.22 |

A 7-day rule would have deactivated all three at some point, #3 before its first order. The founder kept 7 days as the default, settable in the panel.

**What exists.** `resellers.is_active` (ADR-072 decision 9) is a bare boolean. The admin toggles it with no reason and no record beyond a log line. It is read in seven places, each its own way: `ResellerOrderPlacementService` (before the wallet lock), `EnsureResellerApiKey`, `EnsureAccountType` (the whole portal 403s), `ResellerBotService` (only `.baki`, `.trackorder` and `.listharga`), `RebateClaimService`, `RebateNotifier`, and the profile and admin payloads. The portal login checks only the login's own `affiliate_users.is_active`, so a deactivated reseller logs in and then meets a 403 on every page.

**What the stress test found.**
1. **A live bug.** `.topupbaki` runs for a deactivated reseller: the bot does not block it and `ResellerWalletTopupService::initiate()` has no `is_active` check (a comment says it does). So a deactivated reseller can be sent a CHIP link and pay into an account it cannot use. `.info`, `.checkid`, `.list` and `.batalbaki` also run.
2. **A race.** The order placement checks `is_active` before it takes the wallet lock, so an order and a deactivation that cross can let the order through after the account is off.
3. **A missed run.** If the daily job does not run on the warning day (a deploy, an outage), a plain "day 7 → deactivate" rule switches a reseller off with no warning.
4. **A pending top-up.** A reseller deactivated while a CHIP top-up is open can pay after it.
5. **The portal.** A login that succeeds and then 403s everywhere, with no message.

## Decision

**What counts, and when**
1. **A transaction** is a placed wallet order (the `wallet_debit`, whatever its outcome, refunded or not) or money added: a CHIP top-up or an admin manual credit (both are `wallet_topup` ledger entries, ADR-131 decision 17). A login, a balance check or a refund is not.
2. **The period** is set in the panel; default 7 days.
3. **A new reseller's clock starts at approval** (`resellers.created_at`), same period.
4. **A warning** goes out 3 days before (day 4 of 7, setting below), and **a notice** on the day it is deactivated, with how to ask for reactivation. Both state the current balance, so the reseller knows the money is safe.
5. **Where:** email always (the reseller's contact email and its active portal logins), and each of its active WhatsApp bot groups as well.
6. **Deactivated means:** no portal login (refused at login, with the reason), no order or top-up from the bot, the API or the portal. Orders already placed run to the end as usual (delivered, or refunded to the balance, ADR-139). A top-up already paid still credits. API keys and webhooks are kept, so the reseller picks up where it left off after reactivation.
7. **Resellers already past the period on release day** start their clock the day the switch is turned on, so each gets the warning first.
8. **Reactivation restarts the clock** with a full period from that moment.
9. **Internal and test accounts** (`internal_reason` set) are never deactivated for dormancy. No per-reseller exemption switch until a real case asks for one.
10. **Manual and dormancy deactivation are told apart:** each deactivation stores its reason (`manual` or `dormant`), who and when, and every change is kept in a history. The admin list filters "deactivated for inactivity".
11. **Rebate (ADR-139) is unchanged:** a deactivated reseller cannot claim; a reward already reached stays and can be claimed after reactivation, inside its normal deadline.
12. **The reseller's way back:** the login error and the bot reply say "deactivated after N days with no transaction, WhatsApp us at [number] to reactivate"; a manual deactivation says only "deactivated, contact us". The number is a panel setting.
13. **The admin is emailed** a short daily digest, only on a day something happened: who was warned and who was deactivated, with each balance.
14. **Resellers only.** An affiliate holds no balance of its own; RES-5 (ADR-058) stays as it is.

**How it is built**
15. **One seam, `ResellerAccountStatus`:** `deactivate(Reseller, reason, ?AdminUser)` and `reactivate(Reseller, AdminUser)` are the only writers of `resellers.is_active`. The admin toggle, the dormancy job and Close account (ADR-131, reason `closed`) all call it. Each call, in one transaction holding the wallet `ledger_accounts` row (the same mutex as every debit, ADR-131 decision 12), writes the flag, `deactivation_reason`, `deactivated_at`, `deactivated_by` and one `reseller_status_changes` row, and on deactivation deletes the reseller's portal login tokens. `resellers.is_active` stays the one flag every reader checks; the API field names and the admin payloads keep it.
16. **One denial, `ResellerAccountStatus::denial(Reseller): ?Denial`** (a code and a message, dormant or manual wording). The portal login, `EnsureAccountType`, `EnsureResellerApiKey` (still `RESELLER_INACTIVE`, 403; only the message differs), the bot and `ResellerWalletTopupService::initiate()` all answer with it.
17. **The bot refuses every command** of a deactivated reseller with that one reply, in place of today's list of three. This closes the `.topupbaki` bug (finding 1). `initiate()` checks too, so the portal and the bot are both covered at the service.
18. **The order placement re-reads `is_active` after it takes the wallet lock** (finding 2). The early check stays as a fast refusal.
19. **The activity date is derived, never stored:** the latest `created_at` of the reseller's `wallet_topup` or `wallet_debit` ledger entries. No new column that a writer could forget to update.
20. **The anchor** is the latest of: that activity date, approval, the last reactivation, and the day the dormancy switch was turned on. Days are Malaysia calendar days (`MalaysiaTime`).
21. **No deactivation without a warning** (finding 3): a reseller is deactivated only when the anchor is at least the period old **and** a warning for this same anchor was sent at least the warning lead earlier. A missed warning is sent late, and the deactivation waits the full lead after it.
22. **A pending top-up defers it** (finding 4): no dormancy deactivation while a `WalletTopupAttempt` is pending.
23. **The daily job** `app:deactivate-dormant-resellers`, at 10:00 MYT (02:00 UTC), `ROUTINE` in `TaskSchedule`, `withoutOverlapping`. It reads activity for all resellers in one grouped ledger query. For each candidate it re-reads the anchor inside the seam's lock before writing, so an order or top-up that lands while the job runs wins.
24. **Each notice is recorded once:** a `reseller_dormancy_notices` row, unique on (reseller, kind `warning` or `deactivated`, anchor date). The WhatsApp messages go through the outbox (ADR-116) with the same dedupe key; the email through `PlunkMailer`. A rerun on the same day sends nothing twice. A failed send is logged and never blocks the deactivation that follows the lead.
25. **Settings** on `platform_settings` (ADR-028), in a new "Reseller inactivity" section of Admin → Settings beside Purchase Balance, saved behind the MFA step-up: the switch (default **off**), the period (default 7), the warning lead (default 3, less than the period), and the reactivation WhatsApp number. Turning the switch on stores the date it was turned on (decision 7). The Resellers page shows "Auto-deactivate: On, 7 days" linking to it.
26. **Admin:** the reseller list shows the reason and date beside "inactive", with a filter; the detail shows the history; Activate stays a super_admin action without the step-up (no money moves, and the history records who).

## Rationale

- **Deactivate, not close:** closing needs a bank refund the founder sends by hand (ADR-131 decisions 12–16); a dormant reseller with RM3 should not cost that. The balance stays the reseller's, and Close account stays available if it asks for the money.
- **Derived activity:** the ledger is already the record of every order and deposit (ADR-002). A stored "last activity" would need every writer to remember it.
- **One seam and one denial** replace seven separate checks, so the dormancy reason and its message reach every channel the same way, and a new channel has one call to make.
- **The warning rule and the lock** come from the stress test, not from preference: without them the design deactivates a reseller with no warning or right after an order.
- **Default off:** the founder turns it on after release, and the switch-on date starts everyone's clock.

## Consequence to track

- With a 7-day period, resellers #1 and #2 would be warned four days after the switch is turned on and deactivated three days later. Re-check the period after the first month against how many reactivation requests come in.
- An email to a reseller is a new kind of message from the platform (until now only invites, password resets and partner-application mail). Watch Plunk bounces.
- If a per-reseller exemption is asked for, it is a column read by decision 21's candidate query, not a second switch.
