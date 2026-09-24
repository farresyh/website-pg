<?php

namespace App\Services\Pricing;

use App\Models\DeactivationLog;
use App\Models\Package;
use App\Models\PackageReactivationLog;
use App\Models\PriceChangeLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADR-094 decisions 5/6/13 (2026-09-15 stress-test addendum): the one
 * place combo `Package` pricing is ever computed — reused by
 * `PackageController::storeCombo()` (creation), the combo-override
 * endpoint, and Price Sync's own per-component-change hook, matching
 * `PackageMarkupService`'s own "single place standard_selling_price is
 * computed" role for ordinary packages.
 */
final class ComboPricingService
{
    public function __construct(private readonly PackageMarkupService $markup) {}

    /**
     * Recomputes one combo's `denomination`/`cost_price`/
     * `standard_selling_price`/`markup_percent` from its current
     * `components` (decision 1). Decision 5's hybrid pricing: an
     * active override (`combo_override_price` or
     * `combo_override_markup_percent`, mutually exclusive — enforced
     * at the FormRequest layer) wins; otherwise `standard_selling_price`
     * is the literal sum of each component's own already-computed
     * value, and `markup_percent` is stored as the resulting
     * cost-weighted blend (decision 5's own rationale), purely for
     * display — never fed back into the sum-of-selling-prices default.
     *
     * Returns true iff anything actually changed, so callers only log
     * a `PriceChangeLog` row when there's a real change to record.
     */
    public function recompute(Package $combo): bool
    {
        $combo->loadMissing('components');

        $costPrice = 0;
        $denomination = 0;
        $sellingPriceSum = 0;

        foreach ($combo->components as $component) {
            $quantity = (int) $component->pivot->quantity;
            $costPrice += $component->cost_price * $quantity;
            $denomination += $component->denomination * $quantity;
            $sellingPriceSum += $component->standard_selling_price * $quantity;
        }

        if ($combo->combo_override_price !== null) {
            $sellingPrice = $combo->combo_override_price;
        } elseif ($combo->combo_override_markup_percent !== null) {
            $sellingPrice = $this->markup->calculateStandardSellingPrice($costPrice, (float) $combo->combo_override_markup_percent);
        } else {
            $sellingPrice = $sellingPriceSum;
        }

        $markupPercent = $costPrice > 0
            ? round((($sellingPrice - $costPrice) / $costPrice) * 100, 2)
            : 0;

        $changed = $combo->cost_price !== $costPrice
            || $combo->denomination !== $denomination
            || $combo->standard_selling_price !== $sellingPrice;

        if ($changed) {
            $combo->fill([
                'cost_price' => $costPrice,
                'denomination' => $denomination,
                'standard_selling_price' => $sellingPrice,
                'markup_percent' => $markupPercent,
            ])->save();
        }

        return $changed;
    }

    /**
     * Decision 6: called whenever a component's `cost_price` actually
     * changed — Price Sync's own propagation and an admin approving a
     * flagged `PendingPriceChange` are both real "component cost
     * changed" events, so both call this. Recomputes every active
     * combo referencing the component and, for each one whose price
     * actually moved, writes a `PriceChangeLog` row — reusing the
     * exact table/mechanism the Sync Details modal already renders
     * (`PriceSyncController::details()`), rather than inventing a
     * parallel "combo recomputed" surface.
     *
     * @return Collection<int, int> affected game_ids, for cache-forgetting
     */
    public function recomputeForComponentChange(Package $component, ?int $priceSyncRunId): Collection
    {
        $affectedGameIds = collect();

        $component->partOfCombos()->where('is_active', true)->get()->each(function (Package $combo) use ($priceSyncRunId, $affectedGameIds) {
            $oldCostPrice = $combo->cost_price;
            $oldSellingPrice = $combo->standard_selling_price;

            if ($this->recompute($combo)) {
                PriceChangeLog::query()->create([
                    'price_sync_run_id' => $priceSyncRunId,
                    'package_id' => $combo->id,
                    'old_cost_price' => $oldCostPrice,
                    'new_cost_price' => $combo->cost_price,
                    'old_standard_selling_price' => $oldSellingPrice,
                    'new_standard_selling_price' => $combo->standard_selling_price,
                ]);
                $affectedGameIds->push($combo->game_id);
            }
        });

        return $affectedGameIds;
    }

    /**
     * Decision 13: cascades a deactivation onto every active combo
     * referencing `$component`. Two callers, two provenances (mirrors
     * `DeactivationLog::adminUser()`'s own existing "null for
     * automatic, set for manual" convention — SupplierController's
     * bulk deactivate is the precedent): Price Sync's own automated
     * deactivation (`$priceSyncRunId` set, `$adminUserId` null — no
     * human is present to acknowledge) and an admin manually
     * deactivating a component Package via `PackageController::
     * updateStatus()` (`$adminUserId` set, `$priceSyncRunId` null —
     * that path requires an explicit warn-and-acknowledge first,
     * this method only executes the cascade once acknowledged). Every
     * cascade still writes its own `DeactivationLog` row either way,
     * so it's visible in the Sync Details modal or a future admin
     * audit view, never a silent side-effect.
     *
     * @return Collection<int, int> affected game_ids
     */
    public function cascadeDeactivate(Package $component, ?int $priceSyncRunId, ?int $adminUserId = null): Collection
    {
        $affectedGameIds = collect();

        $component->partOfCombos()->where('is_active', true)->get()->each(function (Package $combo) use ($priceSyncRunId, $adminUserId, $affectedGameIds) {
            $combo->update([
                'is_active' => false,
                'deactivated_reason' => 'combo_component_deactivated',
                'deactivated_at' => now(),
            ]);

            DeactivationLog::query()->create([
                'price_sync_run_id' => $priceSyncRunId,
                'package_id' => $combo->id,
                'admin_user_id' => $adminUserId,
            ]);

            $affectedGameIds->push($combo->game_id);
        });

        return $affectedGameIds;
    }

    /**
     * ADR-094 addendum (2026-09-24) — reverses decision 22's original
     * "cascade deactivation is one-directional, reactivating a
     * component never auto-reactivates a combo" call. Found live:
     * that decision only addressed the *action*, and left a combo
     * cascade-deactivated by ADR-100's automated Pending Reactivation
     * path with zero visibility AND zero way back except an admin
     * happening to find it in /admin/games — see PRD §16 item 21.
     *
     * Called from every place a component's `is_active` can flip
     * false->true (`PendingReactivationController::approve()`/
     * `bulkApprove()`, `DismissedPackageController::restore()`,
     * `PendingPriceChangeController::approve()`/`dismiss()`,
     * `PackageController::updateStatus()`, `PendingReactivationAutoApprover::approve()`).
     * For every combo still `deactivated_reason='combo_component_deactivated'`
     * that references this component, reactivates it the moment (and
     * only the moment) **every** one of its own components is active
     * again — a combo's checkout depends on every leg succeeding, so a
     * partial reactivation would silently offer an unfulfillable combo.
     * A no-op for a package that isn't part of any combo, or whose
     * combo(s) aren't in that exact deactivated state (harmless to
     * call unconditionally from every reactivation site).
     *
     * @return Collection<int, int> affected game_ids
     */
    public function cascadeReactivate(Package $component, ?int $priceSyncRunId, ?int $adminUserId = null): Collection
    {
        $affectedGameIds = collect();

        $component->partOfCombos()
            ->where('packages.deactivated_reason', 'combo_component_deactivated')
            ->get()
            ->each(function (Package $combo) use ($priceSyncRunId, $adminUserId, $affectedGameIds) {
                $combo->loadMissing('components');

                if ($combo->components->contains(fn (Package $c) => ! $c->is_active)) {
                    return;
                }

                $combo->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);

                PackageReactivationLog::query()->create([
                    'package_id' => $combo->id,
                    'admin_user_id' => $adminUserId,
                    'price_sync_run_id' => $priceSyncRunId,
                    'trigger' => 'combo_components_all_active',
                ]);

                $affectedGameIds->push($combo->game_id);
            });

        return $affectedGameIds;
    }

    /**
     * Addendum (2026-09-24) to ADR-046 decisions 9/10 — the batch
     * counterpart to `cascadeDeactivate()`, for `SupplierController::
     * updatePackagesStatus()`'s bulk "Deactivate All"/"Deactivate by
     * Game" action. That action never called the per-component method
     * (it does a single mass `Package::query()->update()`, not a
     * per-row loop) — found live: a supplier-wide bulk deactivate could
     * take every component of a combo offline while the combo itself
     * stayed listed `is_active=true`, sellable, with no leg able to
     * fulfill. Looping `cascadeDeactivate()` once per affected component
     * was rejected: Digiflazz alone has 1,088 active packages today, so
     * a naive per-row loop turns this endpoint's existing 2 bulk SQL
     * statements into 1,000+ synchronous queries in one admin HTTP
     * request. Real combos number 49 total — this finds affected combos
     * with ONE query, then bulk-writes just those (typically far fewer
     * than the component count), never looping over the component set.
     *
     * Returns the affected combos' own `id`s (not game_ids, unlike
     * `cascadeDeactivate()`/`cascadeReactivate()`) — a caller reporting
     * "N combos affected" needs an exact combo count, and two combos
     * can share a game.
     *
     * @param  Collection<int, int>  $componentPackageIds
     * @return Collection<int, int> affected combo package ids
     */
    public function cascadeDeactivateForComponents(
        Collection $componentPackageIds,
        ?int $priceSyncRunId,
        ?int $adminUserId = null,
        ?string $reason = null,
    ): Collection {
        if ($componentPackageIds->isEmpty()) {
            return collect();
        }

        // A plain `whereHas('components', ...)` self-joins `packages`
        // onto itself through the pivot — Laravel aliases that inner
        // join (e.g. `laravel_reserved_0`) to disambiguate it from the
        // outer query, so a closure filtering on the literal
        // `packages.id` column silently matches the wrong (outer) side
        // instead of the joined component row. Querying the pivot
        // table directly sidesteps the alias entirely.
        $comboIds = DB::table('package_components')
            ->whereIn('component_package_id', $componentPackageIds->all())
            ->distinct()
            ->pluck('combo_package_id');

        $comboIds = Package::query()
            ->whereIn('id', $comboIds)
            ->where('is_combo', true)
            ->where('is_active', true)
            ->pluck('id');

        if ($comboIds->isEmpty()) {
            return collect();
        }

        $now = now();

        Package::query()->whereIn('id', $comboIds)->update([
            'is_active' => false,
            'deactivated_reason' => 'combo_component_deactivated',
            'deactivated_at' => $now,
        ]);

        DeactivationLog::query()->insert($comboIds->map(fn (int $comboId) => [
            'package_id' => $comboId,
            'price_sync_run_id' => $priceSyncRunId,
            'admin_user_id' => $adminUserId,
            'reason' => $reason,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        return $comboIds;
    }

    /**
     * Addendum (2026-09-24) to ADR-046 decisions 9/10 — the batch
     * counterpart to `cascadeReactivate()`, for `SupplierController::
     * updatePackagesStatus()`'s bulk "Reactivate" action (scoped to
     * `deactivated_reason='supplier_issue'` components only). Finds
     * every still-cascade-deactivated combo referencing ANY of the
     * just-reactivated components with ONE query, then loops only over
     * that small candidate set (bounded by total combo count, currently
     * 49 system-wide — never by the potentially 1,000+-row component
     * set) checking each one's own components are ALL active before
     * reactivating it.
     *
     * Returns the reactivated combos' own `id`s — see
     * `cascadeDeactivateForComponents()`'s own doc for why (exact count,
     * two combos can share a game).
     *
     * @param  Collection<int, int>  $componentPackageIds
     * @return Collection<int, int> reactivated combo package ids
     */
    public function cascadeReactivateForComponents(
        Collection $componentPackageIds,
        ?int $priceSyncRunId,
        ?int $adminUserId = null,
    ): Collection {
        if ($componentPackageIds->isEmpty()) {
            return collect();
        }

        $reactivatedComboIds = collect();

        // See cascadeDeactivateForComponents()'s own comment — same
        // self-join alias trap, same pivot-table-first fix.
        $candidateComboIds = DB::table('package_components')
            ->whereIn('component_package_id', $componentPackageIds->all())
            ->distinct()
            ->pluck('combo_package_id');

        Package::query()
            ->whereIn('id', $candidateComboIds)
            ->where('is_combo', true)
            ->where('deactivated_reason', 'combo_component_deactivated')
            ->with('components')
            ->get()
            ->each(function (Package $combo) use ($priceSyncRunId, $adminUserId, $reactivatedComboIds) {
                if ($combo->components->contains(fn (Package $c) => ! $c->is_active)) {
                    return;
                }

                $combo->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);

                PackageReactivationLog::query()->create([
                    'package_id' => $combo->id,
                    'admin_user_id' => $adminUserId,
                    'price_sync_run_id' => $priceSyncRunId,
                    'trigger' => 'combo_components_all_active',
                ]);

                $reactivatedComboIds->push($combo->id);
            });

        return $reactivatedComboIds;
    }
}
