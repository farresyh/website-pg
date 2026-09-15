<?php

namespace App\Services\Pricing;

use App\Models\DeactivationLog;
use App\Models\Package;
use App\Models\PriceChangeLog;
use Illuminate\Support\Collection;

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
     * Decision 13's Price-Sync-automated cascade half: a component
     * Price Sync itself just deactivated cascades onto every active
     * combo referencing it — no warn-and-acknowledge prompt (that's
     * the separate admin-manual half, no human is present here), but
     * every cascade still writes its own `DeactivationLog` row so it
     * shows up in the same Sync Details modal, never a silent
     * side-effect.
     *
     * @return Collection<int, int> affected game_ids
     */
    public function cascadeDeactivate(Package $component, ?int $priceSyncRunId): Collection
    {
        $affectedGameIds = collect();

        $component->partOfCombos()->where('is_active', true)->get()->each(function (Package $combo) use ($priceSyncRunId, $affectedGameIds) {
            $combo->update([
                'is_active' => false,
                'deactivated_reason' => 'combo_component_deactivated',
                'deactivated_at' => now(),
            ]);

            DeactivationLog::query()->create([
                'price_sync_run_id' => $priceSyncRunId,
                'package_id' => $combo->id,
            ]);

            $affectedGameIds->push($combo->game_id);
        });

        return $affectedGameIds;
    }
}
