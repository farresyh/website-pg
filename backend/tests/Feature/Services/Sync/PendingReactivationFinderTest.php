<?php

namespace Tests\Feature\Services\Sync;

use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Pricing\ComboPricingService;
use App\Services\Pricing\PackageMarkupService;
use App\Services\Sync\PackagePriceSyncService;
use App\Services\Sync\PendingReactivationFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Isolated repro for a suspected bug: PendingReactivationFinder matches
 * on SupplierProduct.status_raw alone, with no check that the row was
 * actually touched by a sync run *after* the package's own
 * deactivated_at. Uses a short (20-minute) gap deliberately, not a
 * long one, to rule out "it's just because the last real sync was
 * ages ago" as the cause — if the bug is structural, a short gap
 * reproduces it exactly the same as a long one.
 */
class PendingReactivationFinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_package_deactivated_this_run_for_vanishing_from_the_catalog_is_not_immediately_flagged_pending_reactivation_from_its_own_stale_prior_active_record(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'TESTREF',
        ]);

        // The supplier item was confirmed active 20 minutes ago — a
        // short, realistic gap (roughly two of this project's own
        // configured 10-minute sync intervals), not months.
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'TESTREF', 'name' => '14 Diamond',
            'status_raw' => 'active', 'last_synced_at' => now()->subMinutes(20),
        ]);

        // This run's catalog fetch simply doesn't include TESTREF
        // (a transient omission, e.g. pagination hiccup) — the
        // SupplierProduct row above is deliberately left untouched,
        // exactly matching what a real ProductSyncService::sync() call
        // would leave behind on a partial/incomplete response.
        $markup = new PackageMarkupService;
        $service = new PackagePriceSyncService($markup, new ComboPricingService($markup));
        $result = $service->apply($supplier, now(), priceSyncRunId: null);

        $this->assertSame(1, $result->deactivated);
        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('supplier_sync', $package->deactivated_reason);

        // The actual question: does the finder immediately re-flag it,
        // using the SAME already-stale 'active' record that predates
        // this exact deactivation?
        $pending = (new PendingReactivationFinder)->find();

        $this->assertFalse(
            $pending->contains('id', $package->id),
            'PendingReactivationFinder flagged a package for reactivation using a SupplierProduct '
            .'row that was already stale BEFORE this exact deactivation happened — not fresh '
            .'confirmation the supplier item is active again.',
        );
    }

    /**
     * The control case for the fix above: a package genuinely IS
     * confirmed active again by a real sync run that happens *after*
     * its own deactivation — this must still be flagged. Proves the
     * fix doesn't overcorrect into silently hiding real reactivations.
     */
    public function test_a_package_confirmed_active_by_a_sync_run_after_its_own_deactivation_is_flagged_pending_reactivation(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '14 Diamond', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => now()->subMinutes(20),
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'TESTREF',
        ]);

        // A real sync run happening AFTER the deactivation confirms
        // the item is active again — genuinely fresher evidence.
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'TESTREF', 'name' => '14 Diamond',
            'status_raw' => 'active', 'last_synced_at' => now(),
        ]);

        $pending = (new PendingReactivationFinder)->find();

        $this->assertTrue($pending->contains('id', $package->id));
    }
}
