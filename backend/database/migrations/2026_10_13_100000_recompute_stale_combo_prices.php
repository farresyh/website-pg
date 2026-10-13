<?php

use App\Http\Controllers\GameController;
use App\Models\Package;
use App\Services\Pricing\ComboPricingService;
use Illuminate\Database\Migrations\Migration;

/**
 * Found on prod 2026-10-13: a combo reactivated by the component cascade
 * came back on sale at the price stored before it went off, because a
 * component cost that changed meanwhile never reached it (8 active
 * combos stale). The code fix reprices on every component change and on
 * reactivation; this reprices the combos already left stale, once.
 *
 * Unlike the item-79 migration this calls app code on purpose: copying
 * the combo pricing rules (override, below-cost fallback, markup blend)
 * into SQL would be a second pricing implementation to keep in step.
 * recomputeAndLog() changes a combo only when its price moved and logs
 * each change, so a current combo and a second run write nothing. A fresh
 * database has no combos, so this is a no-op there. The storefront and
 * reseller catalog caches of each repriced game are dropped, as the admin
 * paths do.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pricing = app(ComboPricingService::class);
        $gameIds = [];

        Package::query()->where('is_combo', true)->with('components')->each(function (Package $combo) use ($pricing, &$gameIds) {
            if ($pricing->recomputeAndLog($combo, priceSyncRunId: null)) {
                $gameIds[$combo->game_id] = true;
            }
        });

        foreach (array_keys($gameIds) as $gameId) {
            GameController::forgetPackagesCache($gameId);
        }
    }

    /** A price correction: the logged old price is the record, nothing to undo. */
    public function down(): void {}
};
