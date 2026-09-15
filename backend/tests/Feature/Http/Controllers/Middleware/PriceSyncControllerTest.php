<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Jobs\SyncSupplierPricesJob;
use App\Models\AdminUser;
use App\Models\DeactivationLog;
use App\Models\Game;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\PriceChangeLog;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PriceSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson('/api/middleware/price-sync')->assertUnauthorized();
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson('/api/middleware/price-sync')->assertForbidden();
    }

    /**
     * ADR-015 decision #5: "Sync All Prices Now" never blocks on a
     * live Gamevion call — it only ever queues a run.
     */
    public function test_store_creates_a_queued_run_and_dispatches_the_job(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/price-sync');

        $response->assertCreated();
        $response->assertJsonPath('status', 'queued');

        $this->assertSame(1, PriceSyncRun::query()->count());
        Queue::assertPushed(SyncSupplierPricesJob::class, fn (SyncSupplierPricesJob $job) => $job->run->id === PriceSyncRun::query()->firstOrFail()->id);
    }

    /**
     * ADR-016 decision #5: `triggered_by` distinguishes a manual run
     * from a scheduled one — Sync History (once built) needs this to
     * show who started each run.
     */
    public function test_store_records_the_triggering_admins_name(): void
    {
        Queue::fake();
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/middleware/price-sync')->assertCreated();

        $this->assertSame($admin->name, PriceSyncRun::query()->firstOrFail()->triggered_by);
    }

    public function test_show_returns_a_runs_current_status(): void
    {
        $run = PriceSyncRun::query()->create([
            'status' => 'success',
            'stats' => ['price_changed' => 2, 'deactivated' => 1],
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/middleware/price-sync/runs/{$run->id}");

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('stats.price_changed', 2);
    }

    /**
     * ADR-016 decision #1: Sync History, most recent run first.
     */
    public function test_index_lists_runs_most_recent_first(): void
    {
        $older = PriceSyncRun::query()->create(['status' => 'success', 'created_at' => now()->subHour()]);
        $newer = PriceSyncRun::query()->create(['status' => 'failed', 'created_at' => now()]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/price-sync/runs');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$newer->id, $older->id], $ids->all());
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

    /**
     * ADR-016 decision #1: the five stat cards.
     */
    public function test_stats_reports_platform_wide_counts_and_last_run(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Active', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV1',
        ]);
        Package::query()->create([
            'game_id' => $game->id, 'name' => 'Pending', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => now()->subMinutes(20),
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV2',
        ]);
        // last_synced_at must be AFTER deactivated_at — PendingReactivationFinder
        // only counts a supplier_products row as confirming reactivation when it
        // was touched by a sync run that happened after the package went offline.
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => 'GV2', 'name' => 'Pending', 'status_raw' => 'active', 'last_synced_at' => now(),
        ]);
        $run = PriceSyncRun::query()->create(['status' => 'success', 'finished_at' => now()]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/price-sync/stats');

        $response->assertOk();
        $response->assertJsonPath('total_games', 1);
        $response->assertJsonPath('active_packages', 1);
        $response->assertJsonPath('pending_reactivation_count', 1);
        $response->assertJsonPath('last_sync_status', 'success');
        $response->assertJsonPath('last_sync_at', $run->finished_at->toJSON());
    }

    /**
     * ADR-025 decision #8: same live-count pattern as
     * pending_reactivation_count, for the sixth section's stat card.
     */
    public function test_stats_reports_the_pending_price_change_count(): void
    {
        $supplier = $this->supplier();
        $package = Package::query()->create([
            'game_id' => $this->game()->id, 'name' => 'Flagged', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => false, 'deactivated_reason' => 'price_anomaly', 'deactivated_at' => now(),
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV1',
        ]);
        PendingPriceChange::query()->create([
            'package_id' => $package->id, 'old_cost_price' => 1000, 'proposed_cost_price' => 1600,
            'old_standard_selling_price' => 1150, 'proposed_standard_selling_price' => 1840, 'status' => 'pending',
        ]);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/middleware/price-sync/stats');

        $response->assertOk();
        $response->assertJsonPath('pending_price_change_count', 1);
    }

    /**
     * ADR-016 decision #1/#2: Sync Details modal groups both price
     * changes and deactivations by game for one specific run.
     */
    public function test_details_groups_price_changes_and_deactivations_by_game(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $repriced = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Repriced', 'denomination' => 14, 'cost_price' => 1200, 'standard_selling_price' => 1380,
            'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV1',
        ]);
        $deactivated = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Deactivated', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => now(),
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV2',
        ]);
        $run = PriceSyncRun::query()->create(['status' => 'success']);
        PriceChangeLog::query()->create([
            'price_sync_run_id' => $run->id, 'package_id' => $repriced->id,
            'old_cost_price' => 1000, 'new_cost_price' => 1200,
            'old_standard_selling_price' => 1150, 'new_standard_selling_price' => 1380,
        ]);
        DeactivationLog::query()->create(['price_sync_run_id' => $run->id, 'package_id' => $deactivated->id]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/middleware/price-sync/runs/{$run->id}/details");

        $response->assertOk();
        $response->assertJsonPath('games_touched', 1);
        $response->assertJsonPath('games.0.game.id', $game->id);
        $response->assertJsonPath('games.0.price_changes.0.package.id', $repriced->id);
        $response->assertJsonPath('games.0.price_changes.0.package.code', 14);
        $response->assertJsonPath('games.0.price_changes.0.package.is_combo', false);
        $response->assertJsonPath('games.0.deactivated_packages.0.id', $deactivated->id);
    }
}
