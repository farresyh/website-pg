# ADR-119: Package delete guards — block a hard-delete that already has order history or is a live combo component

> **Standing (2026-11-05):** In force; the 2026-11-05 addendum moves the rule into the database (deleting a game bypassed it). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted & built, 2026-10-02 (grilled with the founder, three short rounds via `/mattpocock-skills:grilling`)

**Context:** A founder question about `middleware/product-manager`'s "Add to Catalog" flow led to tracing what happens to a promoted item once its `Package` is later deleted via `admin/games`. The Product Manager side turned out fine — "already in catalog" is computed live by matching `supplier_package_ref` against `packages`, not a cached flag, so a deleted Package's item correctly becomes re-promotable with no orphaned state. But tracing `PackageController::destroy()` (`app/Http/Controllers/PackageController.php`) surfaced a real, unguarded gap: `Package` has no `SoftDeletes`, `destroy()` calls a plain hard `->delete()`, and it checks nothing first. Two DB-level foreign keys back this with very different, both-bad failure modes: `orders.package_id` is `nullOnDelete()` (a package with real order history silently orphans every one of those orders' `package_id` the moment it's deleted — no warning, no trace left of what was actually ordered) and `package_components.component_package_id` is `restrictOnDelete()` (deleting a package that's a live combo component throws a raw, unhandled `QueryException` — a 500 with no admin-readable message, unlike `updateStatus()`'s own combo-aware guard for deactivation, which already handles the equivalent case cleanly).

**Decision:**

1. **`destroy()` blocks entirely — no force-delete escape hatch — if the package has ever had any order, regardless of that order's status.** Delete is reserved for "this package was never actually used"; removing a used package from the storefront while preserving its order history is already `updateStatus()`'s job (deactivate). A package with real order history must never be deletable, full stop — not just its *active* orders, since even a long-settled historical order's audit trail matters on a money-critical platform.
2. **`destroy()` blocks if the package is a component of any combo, active or inactive**, mirroring `updateStatus()`'s own deactivate-cascade guard exactly (`ValidationException::withMessages()`, naming the dependent combo(s) by name) rather than inventing a new error shape. Scoped to "any combo" rather than "any active combo" because the underlying FK itself doesn't distinguish — a combo being inactive doesn't make its stored composition any less real.
3. **No conversion to `SoftDeletes`.** Converting `Package` to soft-delete would ripple into every existing query/relationship across the codebase for a problem these two targeted guards already solve — out of scope for what was actually asked, and not something either guard needs.

**Rationale:** Both guards fix a root cause already proven correct elsewhere in this exact codebase — `updateStatus()`'s combo-cascade guard is the working precedent decision 2 copies, and the no-cash-refund/voucher-instead philosophy (`ADR-004`) is the same "never destroy the paper trail of something real" reasoning decision 1 applies to delete. Neither guard was invented from scratch; both close a gap between `destroy()` and patterns this project had already built and tested.

**Consequence to track:**
- 2 new tests in `PackageControllerTest` (`test_destroy_rejects_a_package_with_order_history`, `test_destroy_rejects_a_package_used_as_a_combo_component`). Full backend suite 2463/2463 green.
- Verified via local manual smoke test (real HTTP request against the local dev server) that the `422` response's JSON shape carries the specific guard message both at the top-level `message` and under `errors.package[0]` — Laravel 13's `ValidationException::summarize()` already promotes the first field error to the top-level message, so no separate frontend change was needed to surface it (an initial assumption that `admin/`'s generic-catch error display would need a fix for this was checked against a real request and found false before any frontend code was touched).
- No existing admin-games delete flow was found to depend on the old silent/crashing behavior — nothing else to migrate.

### 2026-11-05 addendum — the rule moves into the database: deleting a game bypassed it

**Context.** A founder stress test of catalog management (2026-11-05) asked what deleting a game in `/admin/games` does. `GameController::destroy()` calls a plain `$game->delete()`, and the database then acts on its own:
- `packages.game_id` is `CASCADE`, so every package of the game is deleted, including packages with order history. Decision 1's guard lives only in `PackageController::destroy()`, so this path never meets it.
- `orders.package_id` and `orders.game_id` are `SET NULL`, so the orders lose both links.
- `price_change_logs`, `deactivation_logs` and `package_ref_changes` cascade away.
- A package that is a combo component or has a delivery leg is `RESTRICT`, so the delete fails as a raw 500.

`GameControllerTest` and `GameTest` even pinned "cascades to the game's packages" as intended. Prod (read-only, 2026-11-05) has 10 of 40 games with orders (102 orders), and its foreign keys match the migrations. Order #7 (a combo, 2026-09-15) already lost its `package_id` before decision 1 existed.

**Root cause.** The rule "a game or package with order history is never deleted" was enforced at one entry point, while the schema declared the opposite. Any other delete path skipped it: today a parent's cascade, tomorrow a console command or a job. A second guard in `GameController` alone would repeat the same patch.

**Decisions.**
1. **The database enforces it.** One migration changes three foreign keys to `RESTRICT`: `packages.game_id`, `orders.package_id` and `orders.game_id`. No path can then remove a used package or game, or orphan an order. Rows that are already null (order #7) are unaffected.
2. **The controllers only explain the refusal.** `PackageController::destroy()` keeps decision 1 and 2's messages. `GameController::destroy()` refuses with "deactivate it instead" when any order references the game or one of its packages.
3. **An unused game is deleted with its packages, in one transaction.** Combos go first: they are always same-game (`StoreComboPackageRequest`), and their components are `RESTRICT`. Then the remaining packages, then the game. The game's catalog groups in Product Manager are unlinked (`supplier_products.game_id` stays `SET NULL`). A race with a new order hits the restrict, rolls back, and answers 422 with the same message.

**Stress test.**
- **A guard in `GameController` only:** rejected. That is the same one-site patch decision 1 was; the schema would still cascade for the next path.
- **`SoftDeletes` on Game or Package:** rejected again (decision 3). A global scope touches every reader, for a case that restrict closes.
- **Forbid deleting games altogether:** rejected. A game created by mistake (Product Manager's "new game" in the link modal) must stay removable.
- **What restrict could break:** only the two delete paths remove packages or games, and the e2e harness deletes neither. Three tests deleted a used game or package on purpose. They now model the only legacy shape (a null link) or assert the refusal.

**Consequence to track.** Item 163 (the seam audit) adds catalog structure to its list (game, package, group link, supplier reference); this is its first finding.
