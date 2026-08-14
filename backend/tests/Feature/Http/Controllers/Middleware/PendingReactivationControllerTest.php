<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PendingReactivationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/price-sync/pending-reactivations')->assertForbidden();
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

    private function deactivatedBySync(Supplier $supplier, Game $game, string $ref = 'GV733'): Package
    {
        return Package::query()->create([
            'game_id' => $game->id,
            'name' => '14 Diamond',
            'cost_price' => 1000,
            'reseller_cost_price' => 1150,
            'is_active' => false,
            'deactivated_reason' => 'supplier_sync',
            'deactivated_at' => now()->subDay(),
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => $ref,
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/middleware/price-sync/pending-reactivations')->assertUnauthorized();
    }

    public function test_index_only_lists_supplier_sync_deactivated_packages_whose_supplier_item_is_active_again(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();

        $pending = $this->deactivatedBySync($supplier, $game, 'GV733');
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'GV733', 'name' => '14 Diamond', 'status_raw' => 'active', 'last_synced_at' => now(),
        ]);

        $stillInactiveAtSupplier = $this->deactivatedBySync($supplier, $game, 'GV999');
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'GV999', 'name' => '14 Diamond', 'status_raw' => 'inactive', 'last_synced_at' => now(),
        ]);

        $adminDeactivated = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Admin Off', 'cost_price' => 500, 'reseller_cost_price' => 575,
            'is_active' => false, 'deactivated_reason' => 'admin', 'deactivated_at' => now(),
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV1',
        ]);
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'GV1', 'name' => '14 Diamond', 'status_raw' => 'active', 'last_synced_at' => now(),
        ]);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/middleware/price-sync/pending-reactivations');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($pending->id));
        $this->assertFalse($ids->contains($stillInactiveAtSupplier->id));
        $this->assertFalse($ids->contains($adminDeactivated->id));
    }

    public function test_approve_reactivates_and_clears_deactivation_fields(): void
    {
        $supplier = $this->supplier();
        $package = $this->deactivatedBySync($supplier, $this->game());
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/middleware/price-sync/pending-reactivations/{$package->id}/approve");

        $response->assertOk();
        $package->refresh();
        $this->assertTrue($package->is_active);
        $this->assertNull($package->deactivated_reason);
        $this->assertNull($package->deactivated_at);
    }

    public function test_approve_rejects_a_package_that_is_not_pending(): void
    {
        $supplier = $this->supplier();
        $package = Package::query()->create([
            'game_id' => $this->game()->id, 'name' => 'Active One', 'cost_price' => 1000, 'reseller_cost_price' => 1150,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV1',
        ]);
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/middleware/price-sync/pending-reactivations/{$package->id}/approve");

        $response->assertUnprocessable();
        $this->assertTrue($package->refresh()->is_active);
    }

    /**
     * ADR-015 decision #3: Dismiss converts it into an admin decision
     * — stays inactive, but stops being monitored for reactivation.
     */
    public function test_dismiss_converts_the_reason_to_admin_and_keeps_it_inactive(): void
    {
        $supplier = $this->supplier();
        $package = $this->deactivatedBySync($supplier, $this->game());
        $this->actingAsAdmin();

        $response = $this->patchJson("/api/middleware/price-sync/pending-reactivations/{$package->id}/dismiss");

        $response->assertOk();
        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('admin', $package->deactivated_reason);
    }

    public function test_bulk_approve_only_processes_ids_that_are_actually_pending(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();

        $pending = $this->deactivatedBySync($supplier, $game, 'GV733');
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'GV733', 'name' => '14 Diamond', 'status_raw' => 'active', 'last_synced_at' => now(),
        ]);

        $notPending = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Active One', 'cost_price' => 1000, 'reseller_cost_price' => 1150,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV1',
        ]);

        $this->actingAsAdmin();
        $response = $this->postJson('/api/middleware/price-sync/pending-reactivations/bulk-approve', [
            'package_ids' => [$pending->id, $notPending->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('processed', 1);
        $this->assertTrue($pending->refresh()->is_active);
        $this->assertTrue($notPending->refresh()->is_active); // untouched, was already active
    }

    public function test_bulk_dismiss_converts_reason_to_admin_for_every_pending_id(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();

        $first = $this->deactivatedBySync($supplier, $game, 'GV733');
        $second = $this->deactivatedBySync($supplier, $game, 'GV734');
        foreach (['GV733', 'GV734'] as $ref) {
            SupplierProduct::query()->create([
                'supplier_id' => $supplier->id, 'external_ref' => $ref, 'name' => '14 Diamond', 'status_raw' => 'active', 'last_synced_at' => now(),
            ]);
        }

        $this->actingAsAdmin();
        $response = $this->postJson('/api/middleware/price-sync/pending-reactivations/bulk-dismiss', [
            'package_ids' => [$first->id, $second->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('processed', 2);
        $this->assertSame('admin', $first->refresh()->deactivated_reason);
        $this->assertSame('admin', $second->refresh()->deactivated_reason);
    }
}
