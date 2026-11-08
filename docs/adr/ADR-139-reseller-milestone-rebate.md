# ADR-139: Reseller milestone rebate — a monthly battle-pass ladder paid from our own profit, claimed by the reseller into Purchase Balance

> **Standing (2026-11-08):** Accepted. Extends [ADR-073](./ADR-073-reseller-wallet.md) (a new wallet credit type; decision 7 revised by its 2026-11-08 addendum: automatic refund) and [ADR-131](./ADR-131-chip-activation-conditions.md) (decision 14: closure forfeits unspent rebate, 2026-11-08 addendum). Adds a line to [ADR-083](./ADR-083-internal-accounting-financial-reconciliation.md)'s monthly summary under [ADR-127](./ADR-127-financial-truth-dated-facts.md)'s dated-fact rules. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted, 2026-11-08. PRD §16 item 161. The process was:
1. Designed with the founder in Malay.
2. A money code trace before this file was written: three read-only forks, one per layer (pricing and profit; ledger, wallet and compensation; downstream readers and accounting), with the top findings re-checked against code and production.
3. Grilled in three rounds (18 questions, decisions 31–46).
4. A ladder simulation through `PricingService` on production orders (decision 30).
5. A final stress test (eight findings, folded into decisions 7, 10–12, 14, 19, 27 and 47, and into Consequences).

Nothing was executed, so build-time tests still have to prove it. Accounting decisions 20–25 need the accountant's confirmation before PR 3 (decision 45).

## Context

**The problem.** The target resellers are WhatsApp agents of big stores. Most sell under RM10k a month, and 1–5 sell more. Asked why they don't switch, agents said our price is almost the same as their leader's. Neither side can go lower, because both are at the floor. Their leader already has a WhatsApp order bot and branded stores, so those are not a reason to switch either. **The agents themselves proposed a rebate:** reach a monthly sales milestone, claim it yourself, and have it credited straight to Purchase Balance, "like a battle pass in a game". None of them named an amount.

**What a rebate has to do here.** It is a tie-breaker at equal price. The reason to switch is that the rebate pays only for volume concentrated with us. An agent who splits buying between the leader and us reaches no milestone at either. An agent who buys everything from us does.

**Production, read-only, 2026-11-08:**

| Item | Value |
|---|---|
| Wallet tiers (admin-assigned) | 5: Supreme 1%, SSS 2%, SS 3%, S 4%, A 5% |
| Reseller accounts | 3 |
| Resellers buying | 1 (Tier SS) |
| That reseller's delivered sales and profit to date | RM446.56 sales, RM12.69 profit (2.84%, against the formula's 2.91%) |
| That reseller's October | RM336.10 sales, RM9.47 profit |
| Delivered wallet orders in November so far | 0 |
| Lowest profit on a delivered wallet order | **−13 sen** |
| Wallet orders | 11 delivered, 4 failed |
| Wallet refunds (all manual) | 4, totalling RM430.04 |
| Orders with a late supplier result that contradicted a final status | 0 of 138 orders (17 failed) |

The founder says Tier SSS is the tier closest to the leader's price.

**What the code trace found:**

1. **Closure.** Closing an account debits the whole wallet balance as `wallet_closure_refund`, and that amount is bank-transferred to the payer (`ResellerClosureService::close()`). A rebate credited into the same balance would leave as cash. That breaks ADR-131 decision 1 (the balance "cannot be cashed out").
2. **Two kinds of profit.** At checkout, profit is `round(cost × (1+m)) − cost`, with no floor (`PricingService`). At delivery, `platform_profit` is rewritten as selling price − real landed cost − affiliate profit (ADR-111, ADR-134). It can be negative, so a ladder derived from tier percentages can exceed a share of the actual profit.
3. **Delivered is final.** `markDelivered` needs Processing. Resend works only from Failed or NeedsReview, and settlement only from Failed or PartiallyDelivered. A wallet order's `platform_profit` therefore does not change after `recognised_at`. No clawback path is needed for normal operation.
4. **Readers.** Every profit report sums `order_profit` only. `MonthlyAccountingSummaryService::operatingProfit()` has no rebate line. `CashPositionService` counts the whole wallet balance as a claim. A rebate posted as a bare wallet credit would therefore:
   - overstate every profit report;
   - open a false gap equal to the month's rebates at month close.
5. **Entry type.** A `wallet_topup` row not tied to a CHIP attempt is read by the Transaction Register as bank cash received (ADR-131 decision 17). So a rebate cannot reuse that type.
6. **Balance readers.** 16 files (about 26 call sites) read the ResellerWallet balance. Splitting the wallet into two ledger accounts would touch all of them and the order debit path.
7. **No dedupe for a new type.** `LedgerService::credit()` takes no lock. The `dedupe_key` backstop covers only five named types where `reason IS NULL`. A new type gets no duplicate protection for free.
8. **Tier changes.** A tier change today is an immediate foreign-key swap with no history. Each order snapshots `wholesale_markup_pct`.

## Decision

### The ladder

1. **A monthly milestone ladder, one per Malaysia calendar month, reset every month.**
   - Steps are thresholds of the reseller's own **recognised wallet sales**, shared by every tier.
   - The starting set covers small to large sellers: RM500, RM1,500, RM3,000, RM5,000, RM10,000 and RM20,000. The final numbers come from the simulation (decision 30).
2. **Each step pays a fixed RM amount, set per tier.** The panel is a grid with steps as rows and tiers as columns. A higher-markup tier pays more at the same step, because our profit there is larger.
   - The reseller pays more per order at that tier and gets more back.
   - The founder accepted that a higher tier stays dearer net of rebate. The tier a reseller sits on matters more than the rebate.
3. **Rewards are funded from a share of our own profit, and we keep the rest.**
   - The starting ladder's guideline share rises from 10% at the first step to **32%** at the top, under a max share (the cap) of **35%**. So we keep at least 65%.
   - The top was lowered from 35% because the simulation found actual profit 5.4% below formula profit (decision 30). A 35% top step would be cut by the cap almost every month.
   - The panel stores RM amounts, not shares. The share appears only in the entitlement cap (decision 12).
   - The starting grid (cumulative RM, from decision 30) follows. It is used as is when the feature is first switched on, after the simulation is re-run (decision 46):

     | Tier | RM500 | RM1.5k | RM3k | RM5k | RM10k | RM20k |
     |---|---|---|---|---|---|---|
     | Supreme 1% | 1 | 2 | 5 | 12 | 29 | 63 |
     | SSS 2% | 1 | 4 | 11 | 24 | 58 | 125 |
     | SS 3% | 1 | 6 | 17 | 36 | 87 | 186 |
     | S 4% | 1 | 8 | 23 | 48 | 115 | 246 |
     | A 5% | 2 | 10 | 28 | 59 | 142 | 304 |
4. **Every reached step is claimable separately, any time within the claim window, including mid-month.**
   - A claim pays that step's amount. The amounts are cumulative by construction, so claiming every step pays the top reached step's total.
   - The reward is the step's fixed amount, not pro rata. An agent at RM9,999 has reached RM5,000, not part of RM10,000.
5. **What counts toward a step:** the reseller's orders recognised in that Malaysia month.
   - It is computed by `RecognisedRevenue` scoped to `wallet_reseller_id` on `recognised_at`, using `keptSen()`. A settled partial combo counts only its delivered part.
   - Test orders are excluded.
   - Month bounds come from `MonthlyAccountingSummaryService::monthBounds()`, the KL-month-to-UTC fix. Month boundaries are not hand-rolled anywhere.
   - A partial combo settled in a later month counts in that later month.
6. **Ladder versions.** The ladder (thresholds, the RM grid and the max share in decision 12) is stored as a version with an effective month.
   - An edit creates a new version effective from **next** month, never the running month.
   - Every milestone row stores the version it was reached under, so history never changes.
   - A master switch (off by default) turns the feature on from a chosen month.
7. **Tier changes take effect on the 1st (Malaysia time), recorded as history.**
   - A new table, `reseller_wallet_tier_changes` (reseller, from tier, to tier, `effective_on`, admin, created), records every change, including a new reseller's first tier, which is effective at once.
   - An admin's change on an existing reseller is stored with `effective_on` = the next 1st. A scheduled task (ADR-122, `CRITICAL`) applies due rows to `resellers.reseller_tier_id` at 00:00 KL.
   - Pricing keeps reading the current tier, unchanged.
   - **The tier of a month is the latest change effective on or before that month's 1st.** It is read from the history, never from the current column. So the finalise task (decision 10) uses the right ladder column however the tasks on the 1st are ordered (stress test F2).
   - The history doubles as the tier audit log.
8. **The reseller sees only "reach RM X → get RM Y", progress to the next step, and unspent rebate.**
   - Never a share, a percentage of profit or `markup_percent`. Those, with the tier and the reward, would let them back-compute the platform margin.
   - No share field appears in a reseller-facing API response, field name or the public docs-site.

### Claim, money and ledger

9. **Claim window.** A step reached in month M is claimable until the end of month M+1 (KL). After that it expires, and the reseller loses it.
10. **Milestone rows.** There is one row per reseller, month and step, unique in the database (`reseller_rebate_milestones`). Each month can also have one `adjustment` row (decision 12).
    - State: `available` → `claimed` | `expired`.
    - Each row stores:
      - its amount, written once when the row is created;
      - the ladder version and the month's tier (decision 7);
      - an `adjusted` flag;
      - the dated transitions (`claimed_at`, `expired_at`), as ADR-127 write-once facts.
    - A row is created at whichever comes first:
      - the claim, mid-month;
      - the month-finalise task at 00:xx KL on the 1st, which writes every reached but unclaimed step of the month just ended (insert-or-ignore on the unique key).
    - The finalise task records its run per month (`reseller_rebate_months.finalized_at`), which decision 23's month-close gate reads.
    - The claim and the finalise task take the **same per-reseller lock**: the reseller's `ledger_accounts` row.
11. **A claim** is one transaction. It locks the reseller's `ledger_accounts` row, the wallet mutex already used by `assertWithinLimits()`. Then it:
    - refuses unless the reseller is active and eligible (decisions 34, 36);
    - refuses after the claim **deadline by date** (end of M+1 KL), whatever the row's state, so a late expire task cannot extend the window (stress test F3);
    - creates or locks the milestone row, and refuses if it is not `available`;
    - pays **the entitlement minus what is already paid for the month** (decision 12), credited with ledger type **`wallet_rebate`** (`reference_type = 'rebate_milestone'`, `reference_id` = the row) through `LedgerService`;
    - marks the row `claimed` with that amount. If the amount is below the step's ladder increment, it sets `adjusted`, and the portal shows "Reward adjusted to the monthly limit".

    `wallet_rebate` is added to the `dedupe_key` backstop as a second guard. The claim request carries only the month and the step. Every amount is computed on the server.
12. **One entitlement function, `RebateEntitlement`, computes every amount** (stress test F1). Claims, the finalise task, the portal and the admin views all call it. No other code computes a rebate amount.

    `entitlement(reseller, month, asOf) = min(ladder cumulative for the steps reached, intdiv(maxShare × recognised platform_profit to date))`

    - Reach and profit both come from `RecognisedRevenue` (decision 5).
    - **Max share** is set in the panel (default 35%) and versioned with the ladder. A **hard ceiling of 50%** is a code constant the panel cannot exceed.
    - Amounts are in sen, rounded down.
    - **A cut self-heals.** A claim pays entitlement − paid, so a cut on an early claim (profit to date still low) is paid back by the next claim.
    - At finalise, the task computes the month's **final** entitlement. If it exceeds paid plus available after the reached steps are written, the difference becomes the month's `adjustment` row, which is `available` and claimable like a step. Claiming early never pays less than claiming late.
    - Every cut and every adjustment row sends every admin an email (the `BackupFailureAlerter` path that `SupplierBufferAlert` uses), so the ladder can be corrected.
13. **Purchase Balance credit only, never cash.**
    - A `wallet_rebate` credit is spend-only. ADR-131's balance cap never refuses it, because it is owed, in the same class as `wallet_refund` and `completeTopup`.
    - There is no admin "manual rebate" action. The claim is the only writer of `wallet_rebate`.
14. **Unspent rebate is derived, not a second balance.** The wallet stays one ledger account. One pure function (`UnspentRebate`) replays the reseller's wallet ledger in order:
    - `wallet_topup` adds cash;
    - `wallet_rebate` adds rebate;
    - `wallet_debit` spends **rebate first**, then cash, and remembers the split per order;
    - `wallet_refund` restores that order's rebate part first, then cash;
    - `wallet_rebate_forfeit` removes rebate;
    - `wallet_closure_refund` removes cash;
    - **any other type throws** (fail closed).

    Every writer of a ResellerWallet ledger row (six sites today) takes its type from one enum, `WalletEntryType`. The replay is a `match` over that enum, and a test fails if a case is unhandled. So a future entry type cannot silently break closure (stress test F5).

    It is used in two places only: account closure (decision 15) and the portal's "unspent rebate" figure. The order, refund and limit paths and every other balance reader are unchanged (finding 6).
15. **Closure forfeits unspent rebate (ADR-131 addendum).** `ResellerClosureService::close()` computes `UnspentRebate` under the same lock and writes two debits:
    - `wallet_rebate_forfeit` for that amount;
    - `wallet_closure_refund` for the rest.

    The admin transfers only the rest, and the closure form shows that amount. The written CHIP confirmation already says unused balance is refunded "to the original payment method", and that stays true of the paid-in money.
16. **Claim only from the portal**, through a human login. Not through Reseller API keys and not through the WhatsApp bot. The bot only tells the reseller a step is reached ("claim it in the portal"), through the existing reseller bot channel.

### Admin and security

17. **Changing the ladder, max share or master switch needs an MFA step-up** (`mfa.stepup`, ADR-121 decision 5; `StepUpRoutesTest` pins it). Every change is audit-logged with who, when, and before and after. It takes effect next month (decision 6).
18. **Admin views:**
    - the milestone rows per reseller and month, with state, amount, version and cap-cut flag;
    - the cap and anomaly alerts.

    These live under the Resellers area of `/admin`, built with the `components/ui` PrimeReact kit (ADR-038), on the `/orders` style benchmark.
19. **End-of-month check.** The finalise task compares what was paid with the month's final entitlement (decision 12).
    - A shortfall becomes the adjustment row.
    - An **overpayment** (paid > final entitlement) sends every admin an email and is never clawed back. It can happen only when a negative-profit order lands after a claim: the accepted, bounded exposure in Consequences.

### Accounting (IFRS 15 / MFRS 15, as ADR-127 designs to)

20. **A rebate is consideration payable to a customer** (IFRS 15.70–72) and reduces revenue. It is not an expense. This mirrors how the KOL promo discount is treated.
    - A new line, **`reseller_rebates_sen`**, goes in `profitLinesBefore()` and is subtracted in `operatingProfit()`. Its docblock says which side it falls on, as that method requires.
    - The line is dated by the milestone's **month** (the period it was earned in), not by `created_at` or `claimed_at`. So a claim made in M+1 for a step reached in M lands in M.
21. **Rebate payable.** `CashPositionService` gains a claim line, `reseller_rebates_payable_sen`, for milestone rows `available` as of the date. A claimed reward has moved into the wallet balance claim, which is already counted. The cash equation then balances at every state:

    | Event | Profit and loss | Payable | Wallet claim |
    |---|---|---|---|
    | Reached | −x | +x | |
    | Claimed | | −x | +x |
    | Expired | +x | −x | |
    | Forfeited at closure | +x | | −x |

22. **Rebate breakage.** `reseller_rebate_breakage_sen` is income. It covers expired rows (dated `expired_at`) and closure forfeits (dated by the forfeit entry). It mirrors voucher breakage (ADR-127 F4).
23. **Month close waits for finalise.** `MonthCloseService` refuses to close month M until M's finalise task has run, which is recorded per month. Otherwise M's payable and rebate line would be missing from its snapshot.
24. **Reports.**
    - `ReportService`'s profit totals, export and reseller-wallet channel split show a separate "Reseller rebates" line. The profit bridge gets a rebate row, so its unexplained difference stays zero.
    - Per-order profit stays as delivered, because the rebate is earned per month, not per order.
    - `CustomerAnalyticsService` and the Report Assistant are checked at build time and labelled where they show gross profit.
25. **Proven by a `FinancialScenario` step** (ADR-127): reach steps, claim, let one expire, claim across a closed month, close an account with unspent rebate. ADR-127's invariants 2, 3 and 5 must hold.

### Automatic refund (ADR-073 decision 7 revised, 2026-11-08 addendum)

26. **A wallet order that ends `Failed` is refunded to Purchase Balance automatically**, through `OrderSettlementService::settle()`.
    - `settle()` already locks the order, checks `isCompensable` and `isAlreadyCompensated`, refuses a closed account, and is covered by `wallet_refund`'s dedupe key.
    - The actor is the system: `settle()` takes a nullable actor recorded as the system, with reason "Automatic refund: supplier confirmed failed".
27. **Mechanism: a scheduled sweep**, not a model event (`backend/AGENTS.md`: "Don't hang an invariant on a model event").
    - Every minute, a `CRITICAL` task in `TaskSchedule` finds wallet orders in `Failed` that are not yet compensated and settles each one.
    - This covers every one of the five paths into Failed (`markDeliveryFailed`, `finalizePendingFailure`, `markNeedsReviewAsFailed` from admin and manual supplier, and the rest) without hooking any of them.
    - Its idempotence comes from `settle()` itself.
    - **The query excludes what `settle()` refuses** (stress test F4): sandbox orders (`is_test`) and orders of closed (soft-deleted) resellers. Otherwise the task would retry and fail every minute forever.
    - Each order is settled in its own try/catch, so one failure never blocks the rest.
    - An order that fails three runs in a row sends every admin an email.
    - The existing `settle()` notification (bot group and API webhook) tells the reseller.
28. **Only `Failed`.**
    - `PartiallyDelivered` combos stay a manual admin decision.
    - `NeedsReview` and `Pending` are never refunded (ADR-102).
    - Supplier retries are unchanged. `FulfillOrderJob` retries connection-level failures; a confirmed Gagal is final.

    A refunded order can never be resent (`OrderResendService`), and the founder accepted this: the reseller simply places a new order. The manual "Refund to Wallet" button stays for `PartiallyDelivered`.
29. **Late contradicting supplier result.** If a supplier reports Success after the order was refunded, the existing guard keeps the result as evidence and logs an error, and the order stays refunded (ADR-102 2026-10-05 decision 3, ADR-127 decision 11). The automatic refund adds an admin email for this case, because it means goods were delivered and refunded. Production has had 0 such results in 138 orders.

### Simulation

30. **Ladder simulation (done 2026-11-08, read-only on production, through `OrderPricingResolver::resolveResellerWallet()`).**
    - **Seam check:** 9 of 11 delivered wallet orders reproduce their frozen selling price exactly. The two that don't had their cost change at delivery.
    - **Drift:** actual profit on wallet orders is **94.56%** of formula profit (real cost at delivery, ADR-111/134).
    - **Rounding:** repricing all 100 delivered orders at each tier gives profit/sales equal to m/(1+m) (0.992%, 1.962%, 2.914%, 3.847%, 4.764%), with no zero-profit and no thin orders.
    - **Result:** at a 35% top share, the RM20k step exceeds a 35% cap of actual profit by about 5.7%. The grid in decision 3 uses 10/15/20/25/30/32%, rounds down to whole RM with a RM1 minimum, and passes `cumulative ≤ 35% × threshold × m/(1+m) × 0.9456` for every step and tier. We keep 70% at RM10k and 68% at RM20k.

### Grill outcomes (2026-11-08, founder)

31. **A reseller who joins mid-month gets the same ladder**, not pro rata (Q1).
32. **A cut is paid with a note**, not paid in full and not held to month end (Q2). Decision 12 makes it self-heal.
33. **One bot reminder, 3 days before a reward expires** (Q3).
34. **A deactivated reseller (`is_active` false, not closed) cannot claim.** Its rows still expire on their date. If reactivated before the deadline, it can claim (Q4).
35. **The master switch starts the rebate from the 1st of the month after it is turned on** (Q5).
36. **Only Reseller (wallet) accounts are eligible**, not Affiliates. A per-reseller **eligible** switch (default on) excludes internal or test accounts. Turning it off can only reduce payouts, and every change is audit-logged (Q6).
37. **A monthly automatic tier review is out of scope.** It needs its own grill once rebate data exists (Q7).
38. **No immediate tier override.** A change on an existing reseller waits for the 1st (Q8).
39. **Orders paid from rebate credit count toward the ladder** like any order (Q9).
40. **One bot message each time a step is reached**, at most six a month, linking to the portal (Q10).
41. **Portal:** a progress card on the dashboard ("RM X more to the next reward") and a Rebate page with the ladder, Claim buttons, history and unspent rebate (Q11).
42. **No Reseller API exposure in the first version** (Q12).
43. **No receipt for a rebate.** It is a statement line, e.g. "Rebate, RM5,000 step (Nov 2026) +RM19" (Q13).
44. **A tier with no ladder column earns nothing** until a version includes it. The ladder screen warns the admin (Q14).
45. **The accountant confirms decisions 20–25 before PR 3** (the accounting part). PRs 1 and 2 are not blocked (Q15).
46. **The 5.4% drift is a first estimate from 11 orders.** The cap and alerts protect us if it is wrong. The simulation is re-run before the master switch is turned on (Q16–Q18).
47. **Step and reminder detection is a scheduled task** (`ROUTINE`, every 10 minutes), not a hook on the order or money path, so a bot failure can never touch money (stress test F6).
    - It runs one grouped query of recognised sales per reseller for the month.
    - It sends each (reseller, month, step) notification once, de-duplicated by a key.
    - It sends decision 33's reminder.

## Rationale

- **Share of our own profit, not a percentage of sales.**
  - A sales-percentage cashback of 1% loses money on the 1% tier.
  - A share of actual `platform_profit` can never exceed what we earned, and it scales with tier on its own. That is the founder's point: higher markup leaves more room to reward.
  - Fixed RM per step keeps the "battle pass" readable. The cap in decision 12 makes the fixed amounts safe.
- **Claim per step.** Immediate reward is the engagement mechanic the agents asked for. A month-end bonus reads like a salary, not a game. The money is the same either way.
- **A monthly reset rewards concentration.** Splitting volume between suppliers, or across accounts, earns less on a rising ladder. That is exactly the behaviour we want.
- **Derived unspent rebate instead of two balances.**
  - The distinction matters only at closure and on one portal figure.
  - A second ledger account would change the order debit path (two locks), refunds, limits and about 26 balance readers for that.
  - A pure replay function with a fail-closed type list is smaller, testable in isolation, and leaves the concurrency-proven paths untouched.
- **A sweep, not an observer, for automatic refunds.** Every path into Failed is covered without touching any of them. A seeder or raw update cannot skip it. `settle()` is already the one idempotent, lock-guarded compensation seam.
- **Dating by earned month.** This keeps the revenue reduction in the month the sales happened, which is what IFRS 15's variable-consideration treatment asks. The payable line keeps the cash equation closed while a claim is outstanding.
- **The tier applies from the 1st.** One tier per month makes the ladder column unambiguous and the price a reseller sees stable within a month.

## Rejected alternatives

- **Cashback as a percentage of sales**, from an earlier brainstorm. It loses money on low tiers and thin packages.
- **Paying only the highest step at month end.** The money is the same, but there is no game.
- **Two ledger accounts (cash and rebate).** The blast radius is too large (finding 6).
- **A clawback debit when an order is refunded after a claim.** Delivered orders are final (finding 3), so this would cost a negative-balance or failing-debit mechanism for a case that does not occur. Exposure to a later negative-profit order is bounded instead (decision 19).
- **Rewards that never expire.** That would leave an open liability forever and lose the urgency of a season.
- **Claim from the API or bot.** A stolen key or a hijacked WhatsApp number could claim. The portal login is the stronger identity.
- **An admin manual-rebate action.** It would be an unguarded writer. Claims are the only way in.
- **Hooking automatic refund on `OrderObserver`.** A model event can be skipped by seeders and raw writes.

## Consequences to track

- **Accepted exposure (stress test S2).** A negative-profit order recognised after a mid-month claim lowers the month's final profit below the amount already paid out. It is bounded by the 50% ceiling and by how rare such orders are (one order of −13 sen to date), and decision 19's email reports it. If it ever matters, the fix is to hold mid-month claims until month end, not to add clawback.
- **Late contradicting supplier results** after an automatic refund cost the supplier price. Watch the decision 29 email.
- **Losing Resend on Failed** is accepted: the reseller places a new order.
- **Edge accepted (stress test F7).** An order placed on the last night of a month and recognised on the 1st was priced at the old tier but counts toward the new month's ladder. It is safe, because the entitlement caps on actual profit.
- **Mid-month accounting is provisional (stress test F8).** The current month's rebate line shows only claimed rows until the finalise task writes the reached but unclaimed ones. It is labelled provisional like the other current-month lines, and month close waits for finalise (decision 23).
- **New tables (5):** ladder versions, the ladder grid, milestones, tier changes and finalised months. Each has one job.
- **Scale.** Reach is one indexed query (`orders.recognised_at` is indexed) per reseller and month. `UnspentRebate` replays one reseller's ledger, only on the portal figure and at closure. Add a cache only on measured slowness.
- **Per-order profit pages** still show profit before rebate. Only the month-level lines, Reports' rebate line and the bridge show it after.
- **Build in four PRs** into `staging`, each with its tests:
  1. Automatic refund sweep (decisions 26–29), independent and smallest.
  2. Tier from the 1st with the tier change history (decision 7).
  3. Rebate backend, after the accountant's confirmation (decision 45). It covers:
     - ladder versions, milestones, `RebateEntitlement`, the claim, `wallet_rebate` and `wallet_rebate_forfeit`;
     - `WalletEntryType`, `UnspentRebate` and the closure forfeit;
     - the finalise, expire and notify tasks;
     - the accounting lines, the month-close gate and `FinancialScenario`;
     - a two-process claim concurrency test (`DatabaseMigrations`).
  4. Admin ladder editor (MFA), claims view and alerts; portal progress and claim; bot step notification.
- **Glossary (PRD §13).** "Rebate milestone", "Unspent rebate" and "Rebate breakage" are pinned at build time.
