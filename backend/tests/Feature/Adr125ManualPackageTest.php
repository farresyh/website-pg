<?php

namespace Tests\Feature;

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
 * ADR-125 decisions 4/34/45: a manual package is priced from its agreed
 * cost like any package, and every cost change, by hand or by sync
 * approval, goes through one audited seam that records who made it.
 */
class Adr125ManualPackageTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
    }

    private function loginGame(): Game
    {
        return Game::query()->create([
            'name' => 'Roblox', 'slug' => 'roblox', 'delivery_mode' => 'manual', 'delivery_subtext' => 'Within 30 minutes',
            'validation_rules' => ['credential_fields' => [
                ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'secret' => false, 'is_identifier' => true],
            ]],
        ]);
    }

    private function supplier(string $driver): Supplier
    {
        return Supplier::query()->create([
            'name' => $driver, 'slug' => $driver === 'manual' ? 'roblox-a' : $driver, 'driver' => $driver,
            'api_config' => [], 'currency' => 'MYR',
        ]);
    }

    public function test_store_prices_a_manual_package_from_its_agreed_cost(): void
    {
        $game = $this->loginGame();
        $supplier = $this->supplier('manual');

        $response = $this->postJson("/api/games/{$game->id}/packages/manual", [
            'supplier_id' => $supplier->id, 'name' => '800 Robux', 'cost_price' => 4000, 'markup_percent' => 15,
        ])->assertCreated();

        $package = Package::query()->findOrFail($response->json('id'));
        $this->assertSame(4600, $package->standard_selling_price);
        $this->assertSame("MANUAL-{$package->id}", $package->supplier_package_ref);
    }

    public function test_store_refuses_a_uid_game_an_api_supplier_and_a_zero_cost(): void
    {
        $uid = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $login = $this->loginGame();
        $manual = $this->supplier('manual');
        $api = $this->supplier('gamevion');
        $payload = ['name' => '800 Robux', 'cost_price' => 4000, 'markup_percent' => 15];

        $this->postJson("/api/games/{$uid->id}/packages/manual", [...$payload, 'supplier_id' => $manual->id])->assertJsonValidationErrors('supplier_id');
        $this->postJson("/api/games/{$login->id}/packages/manual", [...$payload, 'supplier_id' => $api->id])->assertJsonValidationErrors('supplier_id');
        $this->postJson("/api/games/{$login->id}/packages/manual", [...$payload, 'supplier_id' => $manual->id, 'cost_price' => 0])->assertJsonValidationErrors('cost_price');

        $this->assertSame(0, Package::query()->count());
    }

    public function test_a_cost_edit_reprices_and_logs_the_admin_with_no_sync_run(): void
    {
        $game = $this->loginGame();
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '800 Robux', 'cost_price' => 4000, 'standard_selling_price' => 4600, 'markup_percent' => 15,
            'supplier_id' => $this->supplier('manual')->id, 'supplier_package_ref' => 'MANUAL-1',
        ]);

        $this->patchJson("/api/packages/{$package->id}/cost", ['cost_price' => 4200])->assertOk();

        $this->assertSame(4830, $package->fresh()->standard_selling_price);
        $log = PriceChangeLog::query()->sole();
        $this->assertNull($log->price_sync_run_id);
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertSame([4000, 4200], [$log->old_cost_price, $log->new_cost_price]);

        $this->getJson("/api/packages/{$package->id}/price-history")
            ->assertOk()->assertJsonPath('0.admin_user.id', $this->admin->id);
    }

    public function test_an_api_packages_cost_is_not_edited_by_hand(): void
    {
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '86 Diamonds', 'cost_price' => 400, 'standard_selling_price' => 460,
            'supplier_id' => $this->supplier('gamevion')->id, 'supplier_package_ref' => 'GV1',
        ]);

        $this->patchJson("/api/packages/{$package->id}/cost", ['cost_price' => 1])->assertJsonValidationErrors('cost_price');
        $this->assertSame(400, $package->fresh()->cost_price);
    }

    public function test_approving_a_pending_change_records_the_admin(): void
    {
        $game = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '86 Diamonds', 'cost_price' => 400, 'standard_selling_price' => 460, 'markup_percent' => 15,
            'supplier_id' => $this->supplier('gamevion')->id, 'supplier_package_ref' => 'GV1', 'is_active' => false,
            'deactivated_reason' => 'price_anomaly',
        ]);
        $pending = PendingPriceChange::query()->create([
            'package_id' => $package->id, 'old_cost_price' => 400, 'proposed_cost_price' => 600,
            'old_standard_selling_price' => 460, 'proposed_standard_selling_price' => 690,
        ]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));

        $this->patchJson("/api/middleware/price-sync/pending-price-changes/{$pending->id}/approve")->assertOk();

        $package->refresh();
        $this->assertSame([600, 690, true, null], [$package->cost_price, $package->standard_selling_price, $package->is_active, $package->deactivated_reason]);
        $this->assertNotNull(PriceChangeLog::query()->sole()->admin_user_id);
    }
}
