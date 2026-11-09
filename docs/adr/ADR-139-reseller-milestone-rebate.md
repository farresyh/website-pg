# ADR-139: Reseller milestone rebate — a monthly battle-pass ladder paid from our own profit, claimed by the reseller into Purchase Balance

> **Standing (2026-11-08):** Accepted; accountant review addendum (2026-11-08) revises decision 22 and replaces stress test F8. Extends [ADR-073](./ADR-073-reseller-wallet.md) (a new wallet credit type; decision 7 revised by its 2026-11-08 addendum: automatic refund) and [ADR-131](./ADR-131-chip-activation-conditions.md) (decision 14: closure forfeits unspent rebate, 2026-11-08 addendum). Adds a line to [ADR-083](./ADR-083-internal-accounting-financial-reconciliation.md)'s monthly summary under [ADR-127](./ADR-127-financial-truth-dated-facts.md)'s dated-fact rules. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

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

### 2026-11-08 addendum — accountant review (decision 45 met)

The founder sent the accountant a one-page note on decisions 20–25 and received a reply the same day. This addendum records the result. Decision 45 is met, so PR 3 is no longer blocked.

1. **Confirmed by the accountant:**
   - the rebate is a revenue reduction, not a marketing expense;
   - it is recognised when the step is reached, not when it is claimed;
   - a claim moves the liability from rebate payable to Purchase Balance;
   - game-credit revenue is recognised when the credit is used.

   **The programme is purely purchase-volume-based (founder):** it is an appreciation for buying with us, and the reseller has no marketing obligation. If a reseller is ever paid for marketing work, that is a separate payment recorded as an expense, never paid through the rebate.
2. **Decision 22 revised: an unclaimed reward that expires is a reversal of the revenue reduction, not breakage.**
   - It is written as a negative amount on `reseller_rebates_sen`, dated at the deadline (the start of M+2, KL), so it falls in the expiry month. The original month may already be closed, and a change in estimate is prospective.
   - `reseller_rebate_breakage_sen` now covers only rebate credit already in Purchase Balance that is forfeited at closure.
   - Decision 21's cash-equation arithmetic is unchanged. Only the line the +x lands on changes.
3. **No pattern-based breakage on credited rebate.** There is no usage history to estimate from. Credited rebate is derecognised only at closure, when the terms end the reseller's right and the chance of use is nil (IFRS 15 B44–B47).
4. **Decision 20 extended; stress test F8 replaced: the open month is live, not provisional.**
   - For a month not yet finalised, `reseller_rebates_sen` and `reseller_rebates_payable_sen` come straight from `RebateEntitlement` over every eligible reseller: reached steps to date, less what is claimed for the payable. A finalised month reads the milestone rows.
   - Both come from the one function, so they agree at finalise (decision 12's adjustment row).
   - Steps not yet reached are not forecast. The ladder resets monthly and reach is known exactly at month end, so every monthly figure is exact, not an estimate.
5. **Programme terms, required for the closure forfeit.**
   - Versioned rebate terms are shown on the portal's Rebate page.
   - A reseller's first claim, and the first claim after a new terms version, requires ticking acceptance. A new table, `reseller_rebate_terms_acceptances`, stores reseller, terms version, user and `accepted_at`.
   - The terms state:
     - purchases on the platform only;
     - never cash, withdrawal or transfer;
     - claim by the end of the next month or it lapses;
     - unspent rebate is forfeited when the account is closed;
     - rewards are limited to a monthly cap;
     - the ladder can change from a following month.
   - The founder approves the text before PR 4. New tables: 6.
6. **Records for income tax.**
   - Each milestone row stores its calculation inputs at the time it was written: recognised sales, recognised profit, the ladder cumulative amount, the cap and the as-of instant.
   - The admin view (PR 4) exports a month's rows, claims, expiries and forfeits as CSV.
   - Ledger rows are never deleted (ADR-002).
7. **SST: the business charges no SST today (founder, 2026-11-08).**
   - The rebate issues no tax document or credit note, and there is no SST gate on the master switch.
   - If the business is ever registered for SST, the rebate's tax effect (credit notes) is reviewed before anything else changes.
   - Whether the business falls within SST scope is a company-level question for a tax agent, not part of this ADR.
8. **Framework.** The treatment is consistent with MFRS 15, MPERS (2025) Section 23 (effective for periods beginning on or after 1 January 2027, ADR-127), and the earlier MPERS Section 23, under which volume rebates reduce revenue. The founder confirms the financial-year start with the accountant to know which MPERS version applies; this is information only, with no design change.

### 2026-11-08 addendum — programme terms version 1 and ladder-change notice

The founder approved the programme terms that the accountant addendum's decision 5 requires. The text below is **version 1**; PR 4 seeds it verbatim as the first terms version. Clause 2 was reconciled with decision 4 (claimable as soon as a step is reached) and decision 12 (the monthly cap). Clause 7's promise of notice is enforced in code by decision 2 of this addendum.

1. **Terms, version 1 (Malay, shown to resellers as is):**

   > **Terma Program Rebate PekanGame**
   > 1. Rebate dikira berdasarkan jumlah belian layak reseller dalam setiap bulan kalendar. Belian layak ialah belian yang berjaya diproses dan kreditnya telah dihantar. Belian yang dibatalkan, dipulangkan atau dikenakan chargeback tidak dikira.
   > 2. Jumlah belian layak dan ganjaran bagi setiap tier adalah seperti yang disenaraikan dalam jadual Program Rebate PekanGame. Sebaik sahaja jumlah belian layak bulan itu mencapai sesuatu tier, ganjaran tier tersebut boleh dituntut. Ganjaran tertakluk kepada had bulanan program dan mungkin dilaraskan.
   > 3. Ganjaran yang layak diterima boleh dituntut sehingga hari terakhir bulan berikutnya. Ganjaran yang tidak dituntut sebelum tarikh itu akan luput.
   > 4. Ganjaran yang dituntut akan dikreditkan ke Purchase Balance dan hanya boleh digunakan untuk belian di platform PekanGame. Ganjaran tidak boleh ditunaikan, dikeluarkan sebagai wang tunai atau dipindahkan kepada akaun lain.
   > 5. Jika akaun reseller ditutup, baki ganjaran rebate yang masih ada akan luput. Baki Purchase Balance daripada top-up tunai akan dipulangkan selepas semakan akaun dan sebarang amaun yang masih perlu dibayar diselesaikan.
   > 6. PekanGame boleh membetulkan pengiraan ganjaran jika terdapat kesilapan atau transaksi yang tidak layak, seperti transaksi yang dibatalkan atau dipulangkan. Sebarang pembetulan akan dimaklumkan kepada reseller.
   > 7. PekanGame boleh mengubah tier atau jumlah ganjaran dengan memberikan notis. Perubahan hanya berkuat kuasa mulai bulan berikutnya dan tidak mengubah ganjaran yang reseller sudah layak terima bagi bulan sebelumnya.

   - Clause 6 is a reserved right. The system has no clawback (Rejected alternatives), and delivered orders are final, so it is rarely needed. A correction is a deliberate admin decision outside the claim flow.
   - Changing the terms text creates a new version, which needs re-acceptance at the next claim.
2. **Decision 6 extended: the ladder needs notice.**
   - A new ladder version (thresholds, grid or max share) can take effect from a month only if it is saved **at least 7 days before that month starts** (KL). A version saved later takes effect a month later. The panel shows the effective month before the admin confirms with MFA.
   - Saving a version notifies every eligible reseller at once through a portal banner and one bot message ("A new rebate table takes effect on 1 <month>").
   - Turning the master switch on is the first version, and it gets the same notice. Decision 35's "from the following 1st" becomes "from the first 1st at least 7 days away".

### 2026-11-08 addendum — PR 1 build notes (automatic refund)

1. **A late Success on a still-Failed order is skipped too, which closes a gap in decisions 27 and 29.**
   - Without this, a supplier Success arriving between Failed and the next sweep is kept as evidence (`supplier_response.late_supplier_result`) while the order stays Failed and uncompensated.
   - The sweep would then refund an order whose credit may have been delivered.
   - The sweep therefore skips any order carrying that evidence. `OrderFulfillmentService::finalizePendingDelivery()` emails every admin on **any** contradicted Success (Failed, PartiallyDelivered or compensated, wallet or retail), so a person decides.
   - The alert is best effort: a mail failure never changes the outcome.
2. **The actor is recorded.**
   - `settle()` takes `?int $adminUserId`; null means the system, and is allowed only for a wallet order (a voucher keeps a named issuer).
   - The `wallet_refund` ledger row now carries `created_by` (the admin, or null for the system). Before, no refund row recorded who gave it.
3. **Failure counting.** A per-order counter in the cache sends one email on the third failed run. A cache flush can only repeat that email, never skip a refund.

### 2026-11-08 addendum — PR 2 build notes (tier from the 1st, fixed markup)

1. **A tier's markup is fixed once the tier exists** (founder, option (b) of three; the alternatives were five hard-coded 1–5% tiers with rename only, or leaving markup editable).
   - Why: a markup edit re-priced every reseller on that tier at once, mid-month. That bypasses decision 7, and it detaches that tier's ladder column from the margin it was set on, so the cap would cut it every month.
   - `UpdateResellerTierRequest` now marks `markup_percent` as `prohibited`, with a message saying how to get a different markup: create a new tier and move resellers to it from the 1st.
   - Adding a tier and soft-deleting an empty one stay. A tier that a pending change points to cannot be deleted.
2. **Built as decision 7 describes.**
   - The table `reseller_wallet_tier_changes` is backfilled with each existing reseller's current tier, effective on its creation day (KL) and already applied.
   - `ResellerTierChangeService` is the one writer:
     - `recordInitial()` is called from `ResellerProvisioningService::create()`, which every creation path goes through;
     - `change()` holds one pending change per reseller: a new choice replaces it, choosing the current tier cancels it, and a reseller with no tier gets the new one at once;
     - `applyDue()` applies due changes.
   - `app:apply-reseller-tier-changes` runs hourly as `CRITICAL` (`reseller-tier-change-apply`), so 16:00 UTC is exactly 00:00 KL and a missed run catches up.
   - Reading a month's tier from the history is left to PR 3, which is its first reader.
3. **Gotcha.** The `date` cast stores `Y-m-d H:i:s` on sqlite, so `where('effective_on', '<=', 'Y-m-d')` missed the change at exactly midnight on the 1st. The comparison uses `whereDate`. A test pins 23:59 and 00:00 KL.

### 2026-11-08 addendum — PR 3a build notes (ladder, entitlement, claim)

1. **Built.**
   - Tables: `reseller_rebate_ladders`, `reseller_rebate_milestones`, `reseller_rebate_terms_acceptances`, and `resellers.rebate_eligible` (default true, decision 36).
   - `RebateLadderService` is the one writer of ladder versions. It validates each version and sets `effective_from` to the first 1st at least 7 days away.
   - `RebateEntitlement` returns a `RebateStanding`, the decision 12 function.
   - `RebateClaimService` is the only writer of `wallet_rebate`.
   - `RecognisedRevenue::walletResellerTotals()` gives one reseller's recognised sales and profit.
   - `ResellerTierChangeService::tierForMonth()` gives the tier a month is rewarded on.
   - `WalletEntryType` enum: all four existing writers now use it (stress test F5).
   - The `dedupe_key` backstop now includes `wallet_rebate`.
   - Programme terms v1 live in `RebateTerms`.
2. **Changes from the text above, decided at build:**
   - **The ladder grid is JSON on the version row**, not a second table. It is written once, validated as a whole and never queried by cell. The table count is 5, not 6.
   - **Steps are claimed in order.** A claim of step k pays `min(ladder cumulative at k, cap) − already paid this month`. Claiming out of order would let one claim swallow the earlier steps and leave rows paying 0. The portal can offer "claim all", which claims in order.
   - **The claiming login must be an active portal user of that reseller.** This defends the service even if a controller were wrong.
   - **Saving a ladder enforces the ceiling on paper:** each tier's cumulative reward must be ≤ 50% × threshold × m/(100+m), using that tier's fixed markup. At runtime the cap still reads actual profit.
   - **The master switch is the ladder itself.** No version in force means no rebate. Turning the rebate off is a version with no steps, which gets the same 7-day notice. No separate flag can disagree with the ladder.
3. **Proven by:**
   - 14 feature tests: credit once with stored inputs; steps in order; a capped claim is adjusted and the shortfall comes back on the next claim; negative profit pays nothing; terms accepted once per version; the window closes at 00:00 KL on the 1st of M+2; month bounds; sandbox orders are excluded; deactivated, ineligible or foreign logins are refused; a tier with no column earns nothing; the month's tier comes from history; the 7-day notice and the ceiling.
   - A two-process MySQL test (`RebateClaimConcurrencyTest`): two simultaneous claims of a step give one credit.
4. **Not in 3a:** the finalise, expire and notify tasks, `UnspentRebate` with the closure forfeit, the accounting lines and the month-close gate are PR 3b. The HTTP endpoints, admin ladder editor and portal screens are PR 4. Nothing calls the claim from outside yet, and no ladder version exists in production, so this PR changes no live behaviour.

### 2026-11-08 addendum — PR 3b split; PR 3b-i build notes (month end, closure)

1. **PR 3b is split in two.** 3b-i (this PR) covers the money rows: finalise, expire, claiming finalised rows, `UnspentRebate` and the closure forfeit. 3b-ii covers the accounting lines (Monthly Summary, Cash Position payable, Reports bridge), the month-close gate, the `FinancialScenario` step and their admin display. Reason: the accounting lines also need admin display, or the Reports bridge would show a false unexplained difference. Keeping them together in one smaller PR keeps each PR checkable.
2. **Built.**
   - `RebateMonthService`:
     - `finalize()` writes each reached but unclaimed step as `available`, with amounts allocated in order from the final entitlement, then an `adjustment` row (step 0) for any remaining shortfall. A cut, an adjustment or an overpayment emails every admin. It runs under the same wallet mutex as a claim.
     - `finalizeDue()` catches up every ended month since the first ladder that has no `reseller_rebate_months` row.
     - `expireDue()` expires rows dated at the deadline instant, not the run time (stress test F3).
   - Scheduled tasks, both `CRITICAL` and daily: `rebate-reward-expiry` at 16:05 UTC and `rebate-month-finalize` at 16:10 UTC.
   - A claim on a finalised row pays its stored amount. Step 0 claims the adjustment row.
   - `UnspentRebate` replays the wallet ledger with `WalletEntryType::from()`, which throws on an unknown type (fail closed).
   - `ResellerClosureService::refundable()` and `close()`:
     - forfeit the unspent rebate as `wallet_rebate_forfeit`;
     - refund only the rest;
     - expire the closed reseller's unclaimed rows at closure time.
   - The admin `close-check` returns `balance_sen`, `unspent_rebate_sen` and `refund_sen`. The close modal shows the refund and explains the forfeited part.
3. **Who is finalised:** every reseller with recognised wallet sales or rebate rows in the month, except closed or ineligible ones. A deactivated reseller still gets its rows, because it may claim if reactivated before the deadline (decision 34).

### 2026-11-09 addendum — PR 3b-ii build notes (accounting lines, month-close gate)

1. **Built.**
   - `RebateLiability` gives the three figures:
     - `reductionSen(from, to)`: the rows of every finalised month whose 1st (KL) is in the range, plus the live total of every month not finalised yet, less the rows that expired in the range;
     - `breakageSen(from, to)`: `wallet_rebate_forfeit` entries in the range, as a positive amount;
     - `payableSen(asOf)`: rows of started, finalised months neither claimed nor expired before the instant, plus each open month's live total less what was claimed before it.
   - `RebateMonthService::liveTotalSen()` is what finalise would leave as the month's row total: per reseller, max(final entitlement, claimed), or only the claimed amount for a reseller finalise skips. It uses finalise's own reseller set and skip rule, so finalising moves no figure.
   - Monthly Summary: `reseller_rebates_sen` (subtracted in `operatingProfit()`) and `reseller_rebate_breakage_sen` (added).
   - Cash Position: the `reseller_rebates_payable_sen` claim. A month closed before it existed reads it as 0.
   - Reports profit Bridge: the rows `reseller_rebates` (negative) and `reseller_rebate_breakage`.
   - `MonthCloseService` refuses month M while a ladder is in force for M and M has no `reseller_rebate_months` row.
   - Admin: the two Summary lines with definitions, the payable row on the cash equation, and the two Bridge rows.
2. **Decided at build:**
   - **A finalised month takes no claim for a step finalise did not write.** The code trace found that making a reseller eligible again after finalise let a claim add a new row to that month, which may already be closed. That breaks invariant 2. The claim now refuses with "That reward was not reached by the end of that month."
   - **A Reports range that cuts through a month** counts that month's rebate where the month's 1st (KL) falls. The Summary and the month close always use whole months.
   - **The expiry instant is still owed at the end of M+1.** A row expires at 00:00 KL on the 1st of M+2. It stays in the payable at the end of M+1, and its reversal falls in M+2, as the accountant addendum says.
   - **An account closed, or made ineligible, before its month is finalised.** Its unclaimed entitlement leaves the open month's live figure directly, because finalise skips that reseller. It does not appear as a reversal. The payable falls by the same amount, so the gap is unchanged.
3. **Proven by:**
   - `RebateAccountingTest`, 8 tests:
     - the open month is live, and a claim moves the payable into the balance;
     - finalise moves neither figure, including with an adjustment row;
     - an overpayment stays on the line;
     - an expiry reverses in its expiry month;
     - a closure forfeit is breakage, and the lapsed rewards reverse in the closure month;
     - the month-close gate;
     - the claim refusal above;
     - the Bridge explains both lines.
   - A `FinancialScenario` step, run on data the real system produced. The October gap does not move when a ladder starts, a step is claimed, the account closes with rebate unspent, or the month is finalised. October cannot close before finalise. The Bridge has nothing unexplained.
4. **Moved to PR 4 (screens):**
   - decision 24's rebate line in Reports' profit totals, export and reseller-wallet channel split;
   - the gross-profit labels on Customer Analytics and the Report Assistant;
   - the notify tasks.
   Until then, Reports' profit figures are before rebate. Only the Bridge and the month-level lines show it after. Nothing changes in production before PR 4, because no ladder can exist before then.
5. **PR 4 open points settled by the founder (2026-11-09):**
   - The tier is shown on the portal's Rebate page. There is no separate tier page.
   - The reseller is **not** notified when a tier change is scheduled. The 7-day ladder notice stays, because it is part of the programme terms.
   - A reseller sees only their own tier's ladder.
6. **Review before PR 4 (2026-11-09).** Three read-only reviews ran in parallel, one per layer: entitlement and claim; ledger, month end and closure; downstream readers. None found wrong money in the code as it stood. Three gaps were fixed in this PR, each test-first:
   - **A ragged ladder.** `validate()` checked only the cells a step had. A tier missing at step 2 read as 0, so its column fell. The live figure then said 0 while finalise paid step 1, and admins got a false "overpaid" alert. Every step must now reward the same tiers as step 1. This was reachable only once PR 4's editor exists.
   - **A late expiry could move a closed month.** If the expiry task were down, month M could close with rewards past their deadline still `available`. A later run dates their reversal at the deadline, inside M. The month-close check, now `RebateMonthService::closeBlocker()`, also refuses M while any reward that lapsed by M's end is unexpired.
   - **A midnight claim could split across two months.** `claimed_at` (when the payable falls) and the ledger row (when the balance rises) took separate `now()` calls. `claimed_at` now takes the ledger row's `created_at`.
   - **Hygiene:**
     - The claim deadline (period + 2 months, KL) has one home, `ResellerRebateMilestone::claimDeadline()` and `lastPeriodDueBy()`. The claim window, the expiry and the close check all use it.
     - `MonthCloseService` asks the rebate module rather than knowing its rules.
     - `CashPositionService` takes `RebateLiability` by constructor.
     - `liveTotalSen` reuses the standing's claimed sum.
     - A `ponytail:` note names the scale ceiling: one standing per active reseller per load.
     - The admin wallet-balance definition now says it includes claimed rebate credit.
   - **Checked and left as they are:**
     - Lock order: no path takes two locks in opposite orders.
     - Every other wallet-ledger reader filters by type or reads a balance. Production holds only `wallet_topup`, `wallet_debit` and `wallet_refund` (read-only check, 2026-11-09).
     - No path rewrites `platform_profit` after recognition.
     - `BackupFailureAlerter`'s name: renaming it is churn.
     - No concurrency test for claim against finalise or closure: they share the one wallet mutex.

### 2026-11-09 addendum — PR 4 split; PR 4a build notes (admin and portal API, bot messages)

1. **PR 4 is split in three**, like PR 3:
   - **4a (this PR):** the backend endpoints and the bot messages;
   - **4b:** the admin screens, plus decision 24's rebate line in Reports' profit totals, export and channel split;
   - **4c:** the portal screens.
   Each can be checked on its own. 4a changes no live behaviour, because no ladder can be saved until 4b gives the admin a screen. The endpoint is ready, but only a super admin with MFA can call it.
2. **Built.**
   - **Admin endpoints** (super admin):
     - `GET /api/rebate/ladders`: versions with in-force, upcoming or past status, tiers, `tiers_without_column` (decision 44), `next_effective_from` and the ceiling.
     - `POST /api/rebate/ladders` (MFA step-up): saves a version through `RebateLadderService` and sends the notice.
     - `GET /api/rebate/months/{y}/{m}`: each reseller's standing (sales, profit, reached step, cap, entitlement, claimed, available, expired) and the rows with their stored inputs, built by `RebateMonthReport`.
     - `GET /api/rebate/months/{y}/{m}/export`: a CSV of every reward earned, claimed or expired in the month, plus closure forfeits, each with its dates and inputs (accountant addendum, item 6).
     - `PATCH /api/resellers/{id}/rebate-eligibility` (MFA step-up, reason required).
     - The reseller list now returns `rebate_eligible`.
   - **Portal endpoints:**
     - `GET /api/reseller-portal/rebate`: the reseller's tier and terms (accepted or not); unspent rebate; this month and last month while still claimable, each step with its threshold, its reward (the ladder increment) and its state (locked, claimable, claimed or expired) plus progress to the next step; any adjustment; the upcoming version (own column only); and history.
     - `POST /api/reseller-portal/rebate/claim` (20 per minute): carries only year, month, step and the terms tick.
     - Built by `RebatePortalView`. No share, cap, profit, markup or other tier's column is in any response (decision 8, tested by a string check of the body).
   - **Statement detail.** A `wallet_rebate` line now carries `rebate: {period, kind, step, threshold_sen}`, read from its milestone row. It is in both the portal and the admin wallet history. The credit itself still carries no `reason`, because a reason would exempt it from the `dedupe_key` backstop.
   - **Bot messages** (`RebateNotifier`, sent through `WhatsAppOutbox` on the bot session to every active group of an active, eligible reseller, each once by its dedupe key):
     - a reached, unclaimed step, for months not finalised yet (task `rebate-progress-notify`, `ROUTINE`, every 10 minutes);
     - one reminder per month once its unclaimed rewards are inside the last three days;
     - the ladder notice when a version is saved.
   - **Eligibility history.** `reseller_rebate_eligibility_changes` records each change: from, to, admin, reason and time. Its one writer is `RebateEligibilityService`.
3. **Decided at build:**
   - **The eligibility switch needs the MFA step-up.** Decision 17 listed only the ladder. But turning eligibility back on can raise payouts, so it gets the same guard and a required reason.
   - **The ladder's audit log is its version list.** A version is never edited and stores who saved it, so no second audit table is needed.
   - **`SendCustomerWhatsAppJob` sent every bot-session message through `SupplierGroupNotifier::prepare()`.** That returns null when there is no order, so a rebate message would have been skipped silently. The job now routes by event: rebate events go out as queued, and everything else is unchanged.
   - **The message text lives in `RebateNotifier`.** `ResellerBotReplyFormatter` is already over 500 lines.
   - **No reminder for a reward of RM0.00.** That is a fully capped row, so there is nothing to tell the reseller.
4. **Messages, approved by the founder on 2026-11-09 as drafted.** A portal link follows each one.
   - Step: "「 GANJARAN REBATE 」 Tahniah! Belian anda bulan Disember 2026 sudah mencapai RM500.00. Ganjaran RM1.00 sedia untuk dituntut sebelum 31 Januari 2027 di portal:"
   - Reminder: "「 PERINGATAN REBATE 」 Ganjaran rebate RM1.00 bagi bulan Disember 2026 belum dituntut. Tuntut sebelum 31 Januari 2027, atau ia akan luput:"
   - Ladder notice: "「 JADUAL REBATE BARU 」 Jadual Program Rebate PekanGame yang baru berkuat kuasa mulai 1 Januari 2027. Semak ganjaran anda di portal:"
5. **Proven by:**
   - `RebateApiTest` (8 tests): the ladder screen and its warning; saving notifies only eligible groups; the boundary refuses a bad shape; the month view; the CSV; eligibility with its reason and record; the portal shows only its own column and no share; a portal claim with terms and the statement detail.
   - `RebateNotifierTest` (3 tests): each step once, with the link; claimed, ineligible and deactivated accounts hear nothing; one reminder inside three days.
   - The step-up route pin lists the two new routes.
   - A live check with `curl` on a throwaway backend: the page, a claim refused without the terms, a claim, a repeat refused, the statement detail, and 401 without a portal login.

### 2026-11-09 addendum — PR 4b build notes (admin screens, Reports rebate line)

1. **Built.**
   - **Admin → Reseller Rebate** (`/admin/resellers/rebate`, a Partners link in the sidebar). It follows the `/admin/orders` layout and the `components/ui` kit: title, Updated and Refresh, then two tabs.
     - **Ladder:** the version starting next, the version in force (steps as rows, tiers as columns, each cell the cumulative reward with "+RM x this step" under it), a warning for an active tier with no column (decision 44), and every version with who saved it and when.
     - **New version** dialog:
       - starts from the newest version, or from decision 1's thresholds;
       - shows the date it takes effect before saving;
       - flags any cell above the 50% paper ceiling;
       - names any tier left without a column;
       - asks for the MFA step-up through the api client.
     - **Month:** four cards (earned, claimed, waiting to be claimed, lapsed), each reseller's standing, every reward row with the inputs it was written with, and Export CSV.
   - **Resellers:** a **Rebate** button opens a dialog to take an account out of the programme or put it back. It needs a reason and the step-up. An account that is out shows "rebate off".
   - **Wallet history** labels `wallet_rebate` ("Rebate", with "RM5,000.00 step (November 2026)") and `wallet_rebate_forfeit`.
   - **Reports (decision 24):**
     - `ReportService::resellerRebates()` is the Monthly Summary's `reseller_rebates_sen` over the Reports range.
     - The summary returns it, and so does the reseller-wallet channel. The Overview and Profit "Owner profit" cards say "Before reseller rebates of RM x", and the Partners wallet card shows it.
     - The CSV ends with a rebate line under Platform Profit (negative), and the PDF prints it under the table.
     - Under an affiliate filter it is null, because the rebate is per reseller. Per-order profit is unchanged.
   - **Labels:** Customer Analytics' System Profit tooltip, and the Report Assistant prompt, say profit is before reseller rebates.
2. **Found in the browser check, fixed:** for a month not finalised yet, "Waiting to be claimed" read only `available` rows, so it showed RM0.00 while a reached step was unclaimed. A live month now shows entitlement minus claimed for an account in the programme, the same figure as `RebateLiability`'s payable. The finalised month still reads its rows.
3. **Proven by:**
   - `RebateReportsTest` (2 tests): the summary, the affiliate filter and the wallet channel; the CSV's closing line.
   - A `RebateApiTest` assertion for the live waiting amount.
   - A browser check (Playwright) on a throwaway backend and admin, deleted afterwards:
     - the ladder page and grid;
     - the editor's date, ceiling flag and save payload (a throwaway admin has no MFA, so the save answered `mfa_not_enabled` as designed);
     - the month cards (waiting RM5.00 after the fix), the CSV download and the eligibility dialog (also `mfa_not_enabled`);
     - the wallet history label;
     - the Reports Profit note "Before reseller rebates of RM 6.00".

### 2026-11-09 addendum — PR 4c build notes (portal screens)

1. **Built.**
   - **Portal → Rebate** (`/rebate`, reseller nav only):
     - the new-table notice (terms addendum, decision 2), own tier only;
     - unspent rebate;
     - this month and last month: purchases counted, progress to the next target, and each step with its target, its reward and its state;
     - Claim in step order and "Claim all";
     - the terms dialog;
     - reward history and the terms text.
   - **Dashboard:** a Rebate card ("RM X more to reach RM Y", plus a Claim button with the count) that also carries the new-table notice. It is hidden for an account outside the programme.
   - **Statement:** labels for `wallet_rebate` (its step and month, the admin's wording) and `wallet_rebate_forfeit`.
2. **Decided at build:**
   - **"Claim all" is the browser calling the one claim endpoint step by step, in order.** It stops at the first refusal. There is no batch endpoint, so the claim stays the single writer with one rule set (decision 11).
   - **Only the first claimable step has a Claim button**, because the server refuses claims out of order. The month's adjustment can be claimed on its own.
   - **The terms dialog opens before a claim when the page says the terms are not accepted.** A 422 keyed `terms` reopens it for the steps not yet claimed.
   - **Portal history rows carry `threshold_sen`**, read from the version the step was reached on, so a history line matches its statement line (decision 43). A target is not a share, so decision 8 holds.
   - **An account outside the programme** sees a notice and no Claim buttons. The server refuses such a claim anyway (decision 36).
3. **Proven by:**
   - `RebateApiTest`: one new assertion, that the history line carries its target.
   - The portal's `rebate.test.ts`: claim order, the claim-by date in KL, the statement label and the KL month.
   - `format.test.ts`: the rebate ledger labels.
   - A Playwright check on a throwaway backend: the terms on the first claim only, "Claim all" in order, unspent rebate, the statement labels, dark mode, and 390px.
