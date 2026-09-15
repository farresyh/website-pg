<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\PriceChangeLog;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-025 decision #8: the sixth Price Sync Center section — Approve
 * recomputes standard_selling_price from the package's live markup_percent
 * at approval time (decision #6, not whatever was frozen when the
 * anomaly was first flagged); Dismiss just reactivates at the old,
 * already-proven-safe price (decision #7).
 */
class PendingPriceChangeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/price-sync/pending-price-changes')->assertForbidden();
    }

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

    private function flaggedPackage(Supplier $supplier, Game $game, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $game->id,
            'name' => '14 Diamond',
            'cost_price' => 1000,
            'standard_selling_price' => 1150,
            'markup_percent' => 15,
            'is_active' => false,
            'deactivated_reason' => 'price_anomaly',
            'deactivated_at' => now(),
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV733',
        ], $overrides));
    }

    private function pendingChange(Package $package, array $overrides = []): PendingPriceChange
    {
        return PendingPriceChange::query()->create(array_merge([
            'package_id' => $package->id,
            'old_cost_price' => 1000,
            'proposed_cost_price' => 1600,
            'old_standard_selling_price' => 1150,
            'proposed_standard_selling_price' => 1840,
            'status' => 'pending',
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/middleware/price-sync/pending-price-changes')->assertUnauthorized();
    }

    public function test_index_only_lists_pending_rows(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->flaggedPackage($supplier, $game);
        $pending = $this->pendingChange($package);

        $resolvedPackage = $this->flaggedPackage($supplier, $game, ['supplier_package_ref' => 'GV999', 'is_active' => true, 'deactivated_reason' => null]);
        $this->pendingChange($resolvedPackage, ['status' => 'approved']);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/middleware/price-sync/pending-price-changes');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($pending->id));
        $this->assertSame(1, $ids->count());
    }

    public function test_approve_applies_the_proposed_price_and_recomputes_affiliate_price_from_live_markup(): void
    {
        $supplier = $this->supplier();
        $package = $this->flaggedPackage($supplier, $this->game(), ['markup_percent' => 20]); // admin edited markup since flagging
        $pending = $this->pendingChange($package, ['proposed_cost_price' => 1600, 'proposed_standard_selling_price' => 1840]);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/middleware/price-sync/pending-price-changes/{$pending->id}/approve");

        $response->assertOk();
        $package->refresh();
        $this->assertSame(1600, $package->cost_price);
        $this->assertSame(1920, $package->standard_selling_price); // round(1600 * 1.20), not the frozen 1840
        $this->assertTrue($package->is_active);
        $this->assertNull($package->deactivated_reason);
        $this->assertNull($package->deactivated_at);
        $this->assertSame('approved', $pending->refresh()->status);

        $log = PriceChangeLog::query()->firstOrFail();
        $this->assertSame($package->id, $log->package_id);
        $this->assertSame(1000, $log->old_cost_price);
        $this->assertSame(1600, $log->new_cost_price);
    }

    /**
     * ADR-094 decision 6: approve() bypasses PackagePriceSyncService's
     * own propagatePrice() entirely — without its own explicit hook, a
     * combo referencing this package would silently go stale.
     */
    public function test_approve_recomputes_a_dependent_combo(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->flaggedPackage($supplier, $game, ['denomination' => 14]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 14, 'cost_price' => 1000, 'standard_selling_price' => 1150, 'markup_percent' => 0,
        ]);
        $combo->components()->attach($package->id, ['quantity' => 1, 'sort_order' => 0]);
        $pending = $this->pendingChange($package, ['proposed_cost_price' => 1600, 'proposed_standard_selling_price' => 1840]);
        $this->actingAsAdmin();

        $this->patchJson("/api/middleware/price-sync/pending-price-changes/{$pending->id}/approve")->assertOk();

        $combo->refresh();
        $this->assertSame(1600, $combo->cost_price);
        $this->assertSame(1840, $combo->standard_selling_price);
        $this->assertSame(2, PriceChangeLog::query()->count()); // the component's own row + the combo's
    }

    public function test_approve_rejects_a_row_that_is_not_pending(): void
    {
        $supplier = $this->supplier();
        $package = $this->flaggedPackage($supplier, $this->game(), ['is_active' => true, 'deactivated_reason' => null]);
        $pending = $this->pendingChange($package, ['status' => 'approved']);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/middleware/price-sync/pending-price-changes/{$pending->id}/approve");

        $response->assertUnprocessable();
    }

    public function test_dismiss_reactivates_the_package_at_the_old_price_without_applying_the_proposal(): void
    {
        $supplier = $this->supplier();
        $package = $this->flaggedPackage($supplier, $this->game());
        $pending = $this->pendingChange($package);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/middleware/price-sync/pending-price-changes/{$pending->id}/dismiss");

        $response->assertOk();
        $package->refresh();
        $this->assertSame(1000, $package->cost_price); // unchanged — the old price was fine all along
        $this->assertTrue($package->is_active);
        $this->assertNull($package->deactivated_reason);
        $this->assertSame('dismissed', $pending->refresh()->status);
        $this->assertSame(0, PriceChangeLog::query()->count()); // no price was actually applied
    }

    public function test_dismiss_rejects_a_row_that_is_not_pending(): void
    {
        $supplier = $this->supplier();
        $package = $this->flaggedPackage($supplier, $this->game(), ['is_active' => true, 'deactivated_reason' => null]);
        $pending = $this->pendingChange($package, ['status' => 'dismissed']);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/middleware/price-sync/pending-price-changes/{$pending->id}/dismiss");

        $response->assertUnprocessable();
    }
}
