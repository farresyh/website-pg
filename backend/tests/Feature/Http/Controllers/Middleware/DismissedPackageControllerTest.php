<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DismissedPackageControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/price-sync/dismissed-packages')->assertForbidden();
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

    private function dismissedByAdmin(Supplier $supplier, Game $game, string $ref = 'GV733'): Package
    {
        return Package::query()->create([
            'game_id' => $game->id,
            'name' => '14 Diamond',
            'cost_price' => 1000,
            'standard_selling_price' => 1150,
            'is_active' => false,
            'deactivated_reason' => 'admin',
            'deactivated_at' => now()->subDay(),
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => $ref,
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/middleware/price-sync/dismissed-packages')->assertUnauthorized();
    }

    /**
     * ADR-016 decision #3: only deactivated_reason='admin' packages
     * show here — never a supplier_sync one still pending review.
     */
    public function test_index_only_lists_manually_dismissed_packages(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $dismissed = $this->dismissedByAdmin($supplier, $game);
        $pendingReactivation = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Pending', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => now(),
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV999',
        ]);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/middleware/price-sync/dismissed-packages');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($dismissed->id));
        $this->assertFalse($ids->contains($pendingReactivation->id));
    }

    public function test_restore_reactivates_and_clears_deactivation_fields(): void
    {
        $supplier = $this->supplier();
        $package = $this->dismissedByAdmin($supplier, $this->game());
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/middleware/price-sync/dismissed-packages/{$package->id}/restore");

        $response->assertOk();
        $package->refresh();
        $this->assertTrue($package->is_active);
        $this->assertNull($package->deactivated_reason);
        $this->assertNull($package->deactivated_at);
    }

    /**
     * A restore doesn't require the supplier item to be active again —
     * unlike Pending Reactivation's approve(), this is a pure admin
     * judgement call, not gated on live supplier status.
     */
    public function test_restore_works_even_when_supplier_item_is_still_inactive(): void
    {
        $supplier = $this->supplier();
        $package = $this->dismissedByAdmin($supplier, $this->game());
        \App\Models\SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => $package->supplier_package_ref,
            'name' => '14 Diamond', 'status_raw' => 'inactive', 'last_synced_at' => now(),
        ]);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/middleware/price-sync/dismissed-packages/{$package->id}/restore");

        $response->assertOk();
        $this->assertTrue($package->refresh()->is_active);
    }

    public function test_restore_rejects_a_package_that_was_not_manually_dismissed(): void
    {
        $supplier = $this->supplier();
        $package = Package::query()->create([
            'game_id' => $this->game()->id, 'name' => 'Active One', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV1',
        ]);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/middleware/price-sync/dismissed-packages/{$package->id}/restore");

        $response->assertUnprocessable();
        $this->assertTrue($package->refresh()->is_active);
    }
}
