# ADR-138: Package sale lifecycle — one owner for whether a package is on sale, and a price swing never switches a package off

> **Standing (2026-11-06):** Accepted. Revises [ADR-025](./ADR-025-supplier-price-sync-sanity-guard.md) decisions 2–5 and 7 (swing rule), [ADR-015](./ADR-015-price-sync.md) (the 2026-11-05 addendum's creation scope), [ADR-046](./ADR-046-supplier-management-screen.md) and [ADR-125](./ADR-125-login-top-up-manual-supplier.md) decision 64 (an API supplier's switch), [ADR-134](./ADR-134-landed-cost-rate.md) decision 16 (what catches a mistyped transfer) and [ADR-137](./ADR-137-gamevion-v2-api.md) decision 21 (repoint). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted, 2026-11-06. Grilled with the founder (in Malay) in three rounds, then a money code-trace and stress test: three read-only forks, one per layer, with the top findings re-checked against code and production. Nothing was executed, so build-time tests still have to prove it.

## Context

The item 163 seam audit (PRD §16) found four defects that are one problem. `PackageActivation` is the only code that switches a package on (ADR-015 2026-11-05 addendum), but nothing owns switching one off, creating one, or what a supplier's switch means for sale. Each of those paths makes the decision itself:

| Path | Stores a reason | Writes a log | Cascades to combos |
| --- | --- | --- | --- |
| Sync: supplier no longer lists it | Yes | Yes | Yes, as a second write outside any transaction |
| Deactivate All / by Game | Yes | Yes | Yes |
| Admin switches one package off | No | Not for the package | Yes |
| Price swing (`flagPriceAnomaly`) | Yes | No | **No** |

What that produced:

- **Seam A.** A component with a cost swing above 50% goes off, but its combo stays on sale at the old cost. Legs go out with no `is_active` check and no supplier price cap, so a RM10 component at RM16 loses about RM9 per 2-leg order with no admin mistake.
- **Seam E.** Sync applies a price back in range but never closes the open `pending_price_changes` row. Approve then writes the frozen proposal.
- **Seam F.** Promote, manual and combo creation start on sale (`packages.is_active` defaults true) with no blocker check.
- **Seam G.** Switching an API supplier off changes nothing for sale but stops its price sync, so its packages keep selling at frozen costs.

Production, read-only, 2026-11-06:

- 104 orders, 3 of them combos. No active combo has an off component today.
- 66,651 cost changes on non-combo packages since 2026-09-02. Two reached 50% (one rise, one drop). 4 drops were 30–50%, 13 rises were 30–50%.
- One delivered order lost money, RM0.13.
- Package 1538 (Pokemon Unite) sat off 7 days waiting for Approve at a correct price.
- Package 412 (172 Diamonds) fell from RM9.49 to RM4.79 on 2026-11-02. At 49.5% it passed the 50% guard and sold at about RM5.27 for 3.5 hours, with no order. The price then returned and the guard flagged the return as a rise. Dismissing that row would put a RM9.35 item back on sale at RM5.27.
- The other four drops of 30% or more held for at least 41 hours.
- At most 3 packages of one supplier swung 30% or more in one run. No rise of 30% or more reversed within 24 hours.
- The 2026-11-01 landed-cost change moved 1,399 packages in one run at a median of 6.76%.

## Decision

**Ownership**

1. **`PackageActivation` owns switching off too.** One door writes the reason, the `DeactivationLog` row and the combo cascade, in one transaction. It has a single-package and a bulk form. `ComboPricingService` already depends on `PackageActivation`, so `cascadeDeactivate` and `cascadeDeactivateForComponents` move into it (they need no pricing); `cascadeReactivate` stays because it recomputes.
2. **The seam keeps what the off paths do today:** bulk stays one mass update plus one insert; an already-off package returns false and keeps its reason; logs carry run id, admin id and text reason. It returns game ids and never forgets caches, because sync batches them per run. `acknowledge_cascade` stays in the controller.
3. **An admin's switch-off records reason `admin` and a log row.** 85 Digiflazz packages are off with no reason and no `deactivated_at`, the mark of this path. A migration gives them `admin` and a `deactivated_at`, so they appear under Dismissed. The 8 on the Joki pool are left alone. Re-check the selection on production before writing it.
4. **No second sale check at checkout.** One door plus the invariant test decides; a checkout check would be a second site making the same decision.
5. **The invariant test has two layers.** A static check flags any write of `deactivated_reason` outside the seam, plus `$package`-style `is_active => false`. A behaviour test drives every off path and asserts no active combo has an off component. The existing regex can't see `$query->update()` or a mass update, so mirroring it isn't enough. `PendingReactivationController`'s reason-only write and the Joki paths are listed exceptions unless moved in.

**Price swing**

6. **A swing never switches a package off.** `price_anomaly` and the pending-row blocker in `PackageActivation::blocker()` go away. This removes seam A's cause, and it reverses ADR-025 decision 4.
7. **A rise applies at once, with no ceiling.** The selling price follows cost on every pricing basis, so a real rise costs no margin. ADR-025 decision 3 already called a rise a display risk, not a margin risk. Combos recompute on the same run.
8. **A drop of 30% or more is held.** The package keeps selling at the old, higher cost. One open row records it. Smaller drops apply as today. The floor check (price at or below zero) is unchanged.
9. **A held drop records the highest price seen during the hold.** The row's proposal only moves up. "Stayed at or below X for 24 hours" is what the hold proves, so X is what may be applied.
10. **After 24 hours the proposal applies by itself,** with row status `auto_applied`. It happens only inside the sync loop, on a run that saw the product active, while the price is still past the threshold and the breaker in decision 13 isn't tripped. A clock measured from the row's `created_at` is enough, because decision 11 closes the row whenever continuity breaks. A lower live price is then handled by normal evaluation against the new cost.
11. **An open row is checked for every package before the `price_sen !== cost_price` gate.** That gate skips a price back at exactly the held cost, the usual end of a false drop. The row closes as `superseded`, and the clock restarts next time, when the price is back in range, when the run didn't see the product or it isn't active, or when the supplier is switched off. A rise above the old cost also closes it, then applies under decision 7.
12. **Admin may Approve a held drop early. Dismiss is removed.** It never stuck: the next sync saw the same price and opened a new row. `PendingReactivationController::dismiss` and `DismissedPackageController` are different features and stay. Approve and the row close run under the same package lock, on a freshly read package. Approve no longer compares against the live price, because the row and the product refresh in the same job.

**Mass change breaker**

13. **More than 10 packages of one supplier swinging 30% or more in one run means bad data, not a price change.** All prices of that supplier come from one deposit-pool average (`ProductSyncService`), so one mistyped transfer moves the whole catalog. When tripped, for that supplier and run:
    - swings of 30% or more in **both** directions are held and the packages keep selling at the old price;
    - rise rows hold the latest price, drop rows the highest seen;
    - auto-apply is suspended;
    - one email goes out, claimed once per Malaysia day the way `SupplierBufferAlert` does;
    - changes under 30% apply as normal.

    The count is taken across all that supplier's packages, on or off, before the loop. It is recomputed every run, so a corrected transfer closes the rows through decision 11.
14. **"Approve all" per supplier** is the exit when the mass change is real. It runs each open row through the same path as Approve.
15. **The 30% threshold, the 24 hours and the limit of 10 are set in `/middleware`,** and read once per run. They replace `PRICE_SWING_THRESHOLD_PERCENT`, whose leftover `.env` line stays unused; removing it is a production write for no benefit.

**Creation**

16. **A new package is created off, then passed through `activate()`.** No blocker means it goes on, so the Promote workflow is unchanged. A blocker leaves it off with a reason that has its own way back. Sites pass `is_active => false` explicitly, or `activate()` returns early. A combo activates after its components are attached and priced, inside the transaction. The response carries `explain()`, because the modals have no "stayed off" state. Joki's pool creation paths stay outside.
17. **Promote takes the game from the supplier product** and refuses when it has none, as repoint does.

**Repoint** (revises ADR-137 decision 21)

18. **Repoint refuses a product that isn't active.** A blocker re-check afterwards would be wrong: sync's first stage writes row by row, so `listedBySupplier` is falsely negative mid-run and could switch off a live package.
19. **Repoint also refuses while an order on the package is paid or awaiting payment and not yet delivered.** Orders with a failed payment don't count, or the `not_started` rows they leave behind would block it forever.

**Supplier switch**

20. **Rule: an API supplier that is off has no package on sale.** Switching off writes the supplier flag first, so a parallel `activate()` sees the blocker, then takes its packages and combos off through the seam as `supplier_issue`. The dialog shows the counts. Open swing rows close.
21. **Switching on restores nothing.** The admin uses Reactivate. A manual supplier keeps ADR-125 decision 64 (both directions automatic). Copying that for API suppliers would put Gamevion's 71 `supplier_issue` packages on sale at once after repoint.
22. **Reactivate refuses until the supplier has synced within the existing 90-minute staleness limit.** `listedBySupplier` only compares products with each other, so it passes after a long stop, and packages would sell at frozen costs.

## Rationale

- **Root cause over a one-line fix.** Adding a cascade to `flagPriceAnomaly` closes seam A and leaves the next off path free to repeat it. Computing "may be sold" at read time was rejected: it reshapes caching, ADR-034 dedup and listing across 1,500 packages for a problem one seam solves.
- **Direction decides the risk, and the data supports it.** The founder challenged manual approval: customers pay the current price, so a rise can't lose money. That holds, and manual review of rises only lost sales. A cheaper price is the risky direction: if it's false or already gone, we sell low while the supplier charges in full, with no price cap sent. Holding the old price makes the safe answer the default, keeps selling, and leaves Dismiss nothing to protect.
- **Why 24 hours.** The one false drop lasted 3.5 hours and the four others held at least 41. That's one false sample, so it's an estimate, which is why the panel sets it.
- **Why the breaker exists.** ADR-134 decision 16 relied on the 50% guard to catch a mistyped transfer. `TransferSpread` only asks an admin to confirm, and skips the check when there's no market rate. Without the breaker, a typo would apply itself across the catalog after 24 hours, with cost and profit looking normal, which is worse than today. Holding both directions is safe because a real 30% move on more than 10 packages at once hasn't happened: the peak is 3, and the largest real mass move was under 7%. If one ever is real, we sell at the old price until the admin acts, which is why the email matters.

## Consequence to track

**Accepted risks**
- A false drop under 30% isn't caught, and neither is a staircase of smaller drops. Today's exposure is the same or larger.
- A mistyped transfer moving prices under 30% isn't caught by the breaker. `TransferSpread`'s band is the guard there.
- A false rise followed by its return stays high for up to 24 hours. It costs sales, not margin; it has never happened; Approve clears it early.
- A failed sync leaves a gap between readings, so "stayed low" isn't proved continuously. The 90-minute alert fires, and decision 9 caps the value.
- While a drop is held, delivered orders can raise the profit drift flag, because real cost is below catalog cost. Safe, but noisy.
- Promote before Gamevion's funded test order isn't blocked by code (ADR-137 decision 4). That rule stays with the founder, and so does using the single-package toggle rather than supplier Reactivate for that test.
- The window between syncs stays open: a supplier can charge more than the stored cost for up to 30 minutes. `max_price` is the real protection. It's separate work (PRD §16).

**Build order** (each PR safe to release alone)
1. Switch-off seam, admin reason, the backfill and the invariant test. This alone closes seam A: combos go off on a swing and return through the Approve and Dismiss paths already wired.
2. Swing rules, breaker, Approve all, Dismiss removal, panel settings. It keeps a transition branch: when a row closes on a package still marked `price_anomaly`, it activates that package and cascades back. A later PR deletes that branch once production shows none left.
3. Creation, repoint and Promote.
4. Supplier switch and the Reactivate guard.

**Before PR 2 releases:** the founder Approves row 2 (package 412) and never Dismisses it.

**Check at build**
- Tests pinning reversed behaviour: `PackageActivationTest` and about 19 lines across sync, controller, concurrency and supplier tests. `PendingPriceChangeConcurrencyTest` moves to a drop case.
- Tests with unsynced products will now create off packages (about 9 Promote references).
- `DismissedPackage` in `price-sync.ts` types `deactivated_at` and `supplier_package_ref` as non-null; admin-off combos have a null ref.
- Suspected, not traced: `updateStatus` on a Joki package leaves reason `admin`, which the Joki path never clears.
- Suspected, not traced: whether a combo can span two suppliers, which the bulk cascade should survive either way.

### 2026-11-06 addendum — PR 1 build notes

- **Decision 2, return values.** `deactivate()` returns a bool, not game ids. A combo shares its components' game (`StoreComboPackageRequest`, and a package can't move games), so the caller's own `game_id` covers the cache forget. `deactivateMany()` returns the combo count that Deactivate All reports.
- **Decision 3, Dismissed.** The list now also filters on `is_active = false`: an admin can switch a Joki package off in `/admin/games` and Joki settings can switch it back on without clearing the reason, so that package would have shown as dismissed while on sale.
- **A swing now writes a `DeactivationLog` row,** so it appears in Sync Details. `deactivated` in the run result still excludes it.

### 2026-11-06 addendum — PR 2 build notes

- **Decision 13, the daily email.** The claim is `Cache::add` on a key per supplier and Malaysia day, not a column as `SupplierBufferAlert` has. It's just as atomic and needs no migration. A cache flush can send a second email that day, which is harmless.
- **Decision 15, where the limits live.** `price_drop_hold_pct`, `price_hold_hours` and `mass_swing_limit` are per-supplier fields in the existing Edit modal, like `topup_lead_days`. A blank or non-positive value means the default (`PriceSwingPolicy`). The swing test compares whole numbers, so exactly 30% counts.
- **Decision 11, a product that isn't active.** A big drop on one neither applies nor opens a row, because the hold needs an active product. A rise still applies, as Price Propagation always has.
- **Decision 14.** Approve all runs row by row in one request. Fine at today's scale; move it to a queued job if a real mass change ever times out.
- **Legacy rows.** A drop row opened before this rule and still open at release has an old `created_at`, so it can apply on first sight. Production had no open row on 2026-11-06.
