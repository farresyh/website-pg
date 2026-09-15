<?php

namespace Tests\Feature\Services\Sync;

use App\Models\DeactivationLog;
use App\Models\Game;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\PriceChangeLog;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Pricing\ComboPricingService;
use App\Services\Pricing\PackageMarkupService;
use App\Services\Sync\PackagePriceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PackagePriceSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function supplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
    }

    private function game(): Game
    {
        return Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
    }

    private function package(Supplier $supplier, Game $game, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $game->id,
            'name' => '14 Diamond',
            'cost_price' => 1000,
            'standard_selling_price' => 1150,
            'markup_percent' => 15,
            'is_active' => true,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV733',
        ], $overrides));
    }

    private function rawProduct(Supplier $supplier, Carbon $syncedAt, array $overrides = []): SupplierProduct
    {
        return SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'external_ref' => 'GV733',
            'name' => '14 Diamond',
            'price_sen' => 1000,
            'status_raw' => 'active',
            'last_synced_at' => $syncedAt,
        ], $overrides));
    }

    private function service(): PackagePriceSyncService
    {
        $markup = new PackageMarkupService;

        return new PackagePriceSyncService($markup, new ComboPricingService($markup));
    }

    public function test_propagates_a_price_increase_and_recomputes_standard_selling_price(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['cost_price' => 1000, 'standard_selling_price' => 1150, 'markup_percent' => 15]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1200]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->priceChanged);
        $this->assertSame(0, $result->deactivated);
        $this->assertSame([$game->id], $result->affectedGameIds);

        $package->refresh();
        $this->assertSame(1200, $package->cost_price);
        $this->assertSame(1380, $package->standard_selling_price); // round(1200 * 1.15)

        $log = PriceChangeLog::query()->firstOrFail();
        $this->assertSame($package->id, $log->package_id);
        $this->assertSame(1000, $log->old_cost_price);
        $this->assertSame(1200, $log->new_cost_price);
        $this->assertSame(1150, $log->old_standard_selling_price);
        $this->assertSame(1380, $log->new_standard_selling_price);
    }

    /**
     * ADR-015 decision #2: founder explicitly rejected an up/down
     * asymmetry for price — a decrease applies exactly like an
     * increase, no approval gate either direction.
     */
    public function test_propagates_a_price_decrease_the_same_as_an_increase(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['cost_price' => 1000, 'standard_selling_price' => 1150]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 800]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->priceChanged);
        $package->refresh();
        $this->assertSame(800, $package->cost_price);
        $this->assertSame(920, $package->standard_selling_price); // round(800 * 1.15)
    }

    public function test_propagates_price_even_when_the_package_is_already_inactive(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, [
            'cost_price' => 1000, 'is_active' => false, 'deactivated_reason' => 'admin', 'deactivated_at' => now(),
        ]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1200, 'status_raw' => 'active']);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->priceChanged);
        $this->assertSame(1200, $package->refresh()->cost_price);
        // Admin-deactivated stays exactly as the admin left it.
        $this->assertFalse($package->is_active);
        $this->assertSame('admin', $package->deactivated_reason);
    }

    public function test_does_not_log_or_touch_the_package_when_price_is_unchanged(): void
    {
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), ['cost_price' => 1000]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1000]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(0, $result->priceChanged);
        $this->assertSame(0, PriceChangeLog::query()->count());
    }

    public function test_deactivates_a_package_whose_supplier_item_reports_inactive(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['is_active' => true]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['status_raw' => 'inactive']);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->deactivated);
        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('supplier_sync', $package->deactivated_reason);
        $this->assertNotNull($package->deactivated_at);
    }

    /**
     * ADR-015 decision #3: "goes inactive, or disappears entirely
     * from the latest full catalog sync" are treated identically — a
     * Package whose supplier item wasn't touched this run (stale
     * last_synced_at, or no row at all) is exactly as unfulfillable
     * as one explicitly marked inactive.
     */
    public function test_deactivates_a_package_whose_supplier_item_disappeared_from_the_latest_sync(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['is_active' => true]);
        $staleSyncedAt = now()->subMinutes(10);
        $this->rawProduct($supplier, $staleSyncedAt); // not touched by this run

        $result = $this->service()->apply($supplier, now(), priceSyncRunId: null);

        $this->assertSame(1, $result->deactivated);
        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('supplier_sync', $package->deactivated_reason);
        $this->assertSame(0, PriceChangeLog::query()->count()); // no price to trust here
    }

    public function test_deactivates_a_package_with_no_matching_supplier_product_row_at_all(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['is_active' => true]);
        // No SupplierProduct row created at all for this ref.

        $result = $this->service()->apply($supplier, now(), priceSyncRunId: null);

        $this->assertSame(1, $result->deactivated);
        $this->assertFalse($package->refresh()->is_active);
    }

    /**
     * ADR-015 decision #4: an admin's own on/off decision is never
     * touched by Deactivation Detection.
     */
    public function test_never_touches_a_package_the_admin_already_deactivated(): void
    {
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), [
            'is_active' => false, 'deactivated_reason' => 'admin', 'deactivated_at' => now()->subDay(),
        ]);
        $this->rawProduct($supplier, now(), ['status_raw' => 'inactive']);

        $result = $this->service()->apply($supplier, now(), priceSyncRunId: null);

        $this->assertSame(0, $result->deactivated);
        $package->refresh();
        $this->assertSame('admin', $package->deactivated_reason);
    }

    /**
     * A package already deactivated by a previous sync run must not
     * be re-counted as newly deactivated on a later run.
     */
    public function test_does_not_recount_an_already_supplier_sync_deactivated_package(): void
    {
        $supplier = $this->supplier();
        $deactivatedAt = now()->subDay();
        $package = $this->package($supplier, $this->game(), [
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => $deactivatedAt,
        ]);
        $this->rawProduct($supplier, now(), ['status_raw' => 'inactive']);

        $result = $this->service()->apply($supplier, now(), priceSyncRunId: null);

        $this->assertSame(0, $result->deactivated);
        $this->assertSame($deactivatedAt->timestamp, $package->refresh()->deactivated_at->timestamp);
    }

    /**
     * Reactivation is never automatic here (that's the Pending
     * Reactivation queue, computed elsewhere) — a supplier item back
     * to "active" leaves a previously-deactivated package untouched.
     */
    public function test_does_not_auto_reactivate_a_package_whose_supplier_item_is_active_again(): void
    {
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), [
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => now()->subDay(),
        ]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['status_raw' => 'active', 'price_sen' => 1000]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(0, $result->deactivated);
        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('supplier_sync', $package->deactivated_reason);
    }

    /**
     * ADR-016 Sync Details modal: a DeactivationLog row is the only
     * record of which package/game a given run actually turned off —
     * price_change_logs only ever captures price changes.
     */
    public function test_writes_a_deactivation_log_tied_to_the_given_run(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['is_active' => true]);
        $run = PriceSyncRun::query()->create(['status' => 'running']);
        $this->rawProduct($supplier, now(), ['status_raw' => 'inactive']);

        $this->service()->apply($supplier, now(), priceSyncRunId: $run->id);

        $log = DeactivationLog::query()->firstOrFail();
        $this->assertSame($run->id, $log->price_sync_run_id);
        $this->assertSame($package->id, $log->package_id);
    }

    public function test_does_not_write_a_deactivation_log_when_nothing_was_actually_deactivated(): void
    {
        $supplier = $this->supplier();
        $this->package($supplier, $this->game(), [
            'is_active' => false, 'deactivated_reason' => 'admin', 'deactivated_at' => now(),
        ]);
        $run = PriceSyncRun::query()->create(['status' => 'running']);
        $this->rawProduct($supplier, now(), ['status_raw' => 'inactive']);

        $this->service()->apply($supplier, now(), priceSyncRunId: $run->id);

        $this->assertSame(0, DeactivationLog::query()->count());
    }

    /**
     * ADR-025 decision #1: cost_price is never legitimately zero or
     * negative — an unconditional hard auto-reject, no queue, the
     * package's own price stays exactly as it was.
     */
    public function test_floor_rejects_a_zero_price_and_leaves_the_package_untouched(): void
    {
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), ['cost_price' => 1000, 'standard_selling_price' => 1150]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 0]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->floorRejected);
        $this->assertSame(0, $result->priceChanged);
        $this->assertSame(0, $result->anomaliesFlagged);
        $package->refresh();
        $this->assertSame(1000, $package->cost_price);
        $this->assertTrue($package->is_active);
        $this->assertSame(0, PriceChangeLog::query()->count());
        $this->assertSame(0, PendingPriceChange::query()->count());
    }

    /**
     * ADR-025 decision #2/#3: a swing past the configured threshold
     * (symmetric) blocks the write, queues a PendingPriceChange, and
     * deactivates the package under its own distinct reason so
     * PendingReactivationFinder's 'supplier_sync' filter never picks
     * it up.
     */
    public function test_swing_flags_a_large_increase_and_deactivates_the_package_as_price_anomaly(): void
    {
        config(['packages.price_swing_threshold_percent' => 50]);
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($supplier, $game, ['cost_price' => 1000, 'standard_selling_price' => 1150, 'markup_percent' => 15, 'is_active' => true]);
        $run = PriceSyncRun::query()->create(['status' => 'running']);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1600]); // +60%, over threshold

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: $run->id);

        $this->assertSame(1, $result->anomaliesFlagged);
        $this->assertSame(0, $result->priceChanged);
        $this->assertSame(0, $result->deactivated); // separate counter from ordinary Deactivation Detection

        $package->refresh();
        $this->assertSame(1000, $package->cost_price); // unapplied — stays at last-known-good
        $this->assertFalse($package->is_active);
        $this->assertSame('price_anomaly', $package->deactivated_reason);
        $this->assertNotNull($package->deactivated_at);
        $this->assertSame(0, PriceChangeLog::query()->count());

        $pending = PendingPriceChange::query()->firstOrFail();
        $this->assertSame($package->id, $pending->package_id);
        $this->assertSame($run->id, $pending->price_sync_run_id);
        $this->assertSame(1000, $pending->old_cost_price);
        $this->assertSame(1600, $pending->proposed_cost_price);
        $this->assertSame(1150, $pending->old_standard_selling_price);
        $this->assertSame(1840, $pending->proposed_standard_selling_price); // round(1600 * 1.15)
        $this->assertSame('pending', $pending->status);
    }

    /**
     * Decision 3: a big decrease gets the same treatment as a big
     * increase — no directional asymmetry.
     */
    public function test_swing_flags_a_large_decrease_the_same_as_an_increase(): void
    {
        config(['packages.price_swing_threshold_percent' => 50]);
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), ['cost_price' => 1000]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 400]); // -60%, over threshold

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->anomaliesFlagged);
        $this->assertSame(1000, $package->refresh()->cost_price);
        $this->assertSame('price_anomaly', $package->deactivated_reason);
    }

    public function test_swing_under_threshold_propagates_normally_with_no_pending_row(): void
    {
        config(['packages.price_swing_threshold_percent' => 50]);
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), ['cost_price' => 1000]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1300]); // +30%, under threshold

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->priceChanged);
        $this->assertSame(0, $result->anomaliesFlagged);
        $this->assertSame(1300, $package->refresh()->cost_price);
        $this->assertTrue($package->is_active);
        $this->assertSame(0, PendingPriceChange::query()->count());
    }

    /**
     * Decision 5: a package with an already-unresolved pending anomaly
     * is skipped on a later run, not re-flagged into a second row.
     */
    public function test_swing_skips_a_package_that_already_has_an_unresolved_pending_change(): void
    {
        config(['packages.price_swing_threshold_percent' => 50]);
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), [
            'cost_price' => 1000, 'is_active' => false, 'deactivated_reason' => 'price_anomaly', 'deactivated_at' => now(),
        ]);
        PendingPriceChange::query()->create([
            'package_id' => $package->id,
            'old_cost_price' => 1000,
            'proposed_cost_price' => 1600,
            'old_standard_selling_price' => 1150,
            'proposed_standard_selling_price' => 1840,
            'status' => 'pending',
        ]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1700]); // yet another large swing

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(0, $result->anomaliesFlagged);
        $this->assertSame(1, PendingPriceChange::query()->count());
    }

    /**
     * A legacy row with cost_price = 0 (predating this guard) has no
     * safe baseline to diff against — apply the incoming price
     * directly rather than divide by zero, same reasoning promote()
     * already uses for a first-time creation.
     */
    /**
     * ADR-094 decision 6: a component's cost_price actually propagating
     * cascades into a recompute of every active combo referencing it —
     * end-to-end through apply(), not just ComboPricingService in
     * isolation.
     */
    public function test_a_propagated_price_change_recomputes_a_dependent_combo(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $component = $this->package($supplier, $game, ['cost_price' => 1000, 'standard_selling_price' => 1150, 'denomination' => 14]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 0, 'cost_price' => 0, 'standard_selling_price' => 0, 'markup_percent' => 0,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);
        $combo->update(['denomination' => 14, 'cost_price' => 1000, 'standard_selling_price' => 1150]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1200]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertContains($game->id, $result->affectedGameIds);
        $combo->refresh();
        $this->assertSame(1200, $combo->cost_price);
        $this->assertSame(1380, $combo->standard_selling_price);
        $this->assertSame(2, PriceChangeLog::query()->count()); // the component's own row + the combo's
    }

    /**
     * ADR-094 decision 13's Price-Sync-automated half: a component
     * Price Sync itself deactivates cascades onto every active combo
     * referencing it, with its own DeactivationLog row.
     */
    public function test_a_supplier_deactivation_cascades_to_a_dependent_combo(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $component = $this->package($supplier, $game, ['is_active' => true]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true, 'is_active' => true,
            'denomination' => 14, 'cost_price' => 1000, 'standard_selling_price' => 1150, 'markup_percent' => 0,
        ]);
        $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => 0]);
        $run = PriceSyncRun::query()->create(['status' => 'running']);
        $this->rawProduct($supplier, now(), ['status_raw' => 'inactive']);

        $this->service()->apply($supplier, now(), priceSyncRunId: $run->id);

        $combo->refresh();
        $this->assertFalse($combo->is_active);
        $this->assertSame('combo_component_deactivated', $combo->deactivated_reason);
        $this->assertSame(2, DeactivationLog::query()->count()); // the component's own row + the combo's
    }

    public function test_applies_directly_when_the_package_has_no_prior_cost_price_to_diff_against(): void
    {
        config(['packages.price_swing_threshold_percent' => 50]);
        $supplier = $this->supplier();
        $package = $this->package($supplier, $this->game(), ['cost_price' => 0, 'standard_selling_price' => 0]);
        $syncedAt = now();
        $this->rawProduct($supplier, $syncedAt, ['price_sen' => 1000]);

        $result = $this->service()->apply($supplier, $syncedAt, priceSyncRunId: null);

        $this->assertSame(1, $result->priceChanged);
        $this->assertSame(0, $result->anomaliesFlagged);
        $this->assertSame(1000, $package->refresh()->cost_price);
    }
}
