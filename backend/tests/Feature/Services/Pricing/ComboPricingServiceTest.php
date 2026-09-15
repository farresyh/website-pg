<?php

namespace Tests\Feature\Services\Pricing;

use App\Models\DeactivationLog;
use App\Models\Game;
use App\Models\Package;
use App\Models\PriceChangeLog;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Services\Pricing\ComboPricingService;
use App\Services\Pricing\PackageMarkupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-094 decisions 5/6/13 (2026-09-15 addendum): combo pricing math,
 * the override, and the two "component just changed" hooks — Price
 * Sync's own propagation and an admin approving a flagged price
 * change both call the same `recomputeForComponentChange()`.
 */
class ComboPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ComboPricingService
    {
        return new ComboPricingService(new PackageMarkupService);
    }

    private function game(): Game
    {
        return Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia']);
    }

    private function supplier(): Supplier
    {
        return Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
    }

    private function componentPackage(Game $game, Supplier $supplier, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $game->id, 'name' => '4810 Diamonds', 'denomination' => 4810,
            'cost_price' => 40000, 'standard_selling_price' => 44000, 'markup_percent' => 10,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-4810',
        ], $overrides));
    }

    private function combo(Game $game, Package $component, int $quantity = 1): Package
    {
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true,
            'denomination' => 0, 'cost_price' => 0, 'standard_selling_price' => 0, 'markup_percent' => 0,
        ]);
        $combo->components()->attach($component->id, ['quantity' => $quantity, 'sort_order' => 0]);

        return $combo;
    }

    public function test_recompute_sums_component_values_by_default(): void
    {
        $game = $this->game();
        $supplier = $this->supplier();
        $component = $this->componentPackage($game, $supplier);
        $combo = $this->combo($game, $component, quantity: 2);

        $changed = $this->service()->recompute($combo);

        $this->assertTrue($changed);
        $combo->refresh();
        $this->assertSame(9620, $combo->denomination);
        $this->assertSame(80000, $combo->cost_price);
        $this->assertSame(88000, $combo->standard_selling_price);
        $this->assertSame('10.00', (string) $combo->markup_percent); // same blend as the single component's own
    }

    public function test_recompute_is_a_no_op_when_nothing_changed(): void
    {
        $game = $this->game();
        $component = $this->componentPackage($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $this->service()->recompute($combo);
        $updatedAt = $combo->refresh()->updated_at;

        $changed = $this->service()->recompute($combo);

        $this->assertFalse($changed);
        $this->assertEquals($updatedAt, $combo->refresh()->updated_at);
    }

    public function test_recompute_honours_a_custom_markup_override(): void
    {
        $game = $this->game();
        $component = $this->componentPackage($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $combo->update(['combo_override_markup_percent' => 25]);

        $this->service()->recompute($combo);

        $combo->refresh();
        $this->assertSame(40000, $combo->cost_price);
        $this->assertSame(50000, $combo->standard_selling_price); // round(40000 * 1.25)
        $this->assertSame('25.00', (string) $combo->markup_percent);
    }

    public function test_recompute_honours_a_custom_fixed_price_override(): void
    {
        $game = $this->game();
        $component = $this->componentPackage($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $combo->update(['combo_override_price' => 39900]);

        $this->service()->recompute($combo);

        $combo->refresh();
        $this->assertSame(40000, $combo->cost_price);
        $this->assertSame(39900, $combo->standard_selling_price); // below cost — admin's own deliberate call
    }

    public function test_recompute_for_component_change_updates_every_active_combo_and_logs_it(): void
    {
        $game = $this->game();
        $component = $this->componentPackage($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $this->service()->recompute($combo); // seed to the pre-change baseline

        $component->update(['cost_price' => 50000, 'standard_selling_price' => 55000]);
        $run = PriceSyncRun::query()->create(['status' => 'running']);

        $affected = $this->service()->recomputeForComponentChange($component, priceSyncRunId: $run->id);

        $this->assertSame([$game->id], $affected->all());
        $combo->refresh();
        $this->assertSame(50000, $combo->cost_price);
        $this->assertSame(55000, $combo->standard_selling_price);

        $log = PriceChangeLog::query()->firstOrFail();
        $this->assertSame($combo->id, $log->package_id);
        $this->assertSame($run->id, $log->price_sync_run_id);
        $this->assertSame(40000, $log->old_cost_price);
        $this->assertSame(50000, $log->new_cost_price);
    }

    public function test_recompute_for_component_change_ignores_an_already_inactive_combo(): void
    {
        $game = $this->game();
        $component = $this->componentPackage($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $this->service()->recompute($combo);
        $combo->update(['is_active' => false, 'deactivated_reason' => 'admin']);

        $component->update(['cost_price' => 50000, 'standard_selling_price' => 55000]);
        $affected = $this->service()->recomputeForComponentChange($component, priceSyncRunId: null);

        $this->assertSame([], $affected->all());
        $this->assertSame(40000, $combo->refresh()->cost_price);
        $this->assertSame(0, PriceChangeLog::query()->count());
    }

    public function test_cascade_deactivate_turns_off_every_active_combo_and_logs_it(): void
    {
        $game = $this->game();
        $component = $this->componentPackage($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $run = PriceSyncRun::query()->create(['status' => 'running']);

        $affected = $this->service()->cascadeDeactivate($component, priceSyncRunId: $run->id);

        $this->assertSame([$game->id], $affected->all());
        $combo->refresh();
        $this->assertFalse($combo->is_active);
        $this->assertSame('combo_component_deactivated', $combo->deactivated_reason);
        $this->assertNotNull($combo->deactivated_at);

        $log = DeactivationLog::query()->firstOrFail();
        $this->assertSame($combo->id, $log->package_id);
        $this->assertSame($run->id, $log->price_sync_run_id);
    }

    public function test_cascade_deactivate_does_not_recount_an_already_inactive_combo(): void
    {
        $game = $this->game();
        $component = $this->componentPackage($game, $this->supplier());
        $combo = $this->combo($game, $component);
        $combo->update(['is_active' => false, 'deactivated_reason' => 'admin']);

        $this->service()->cascadeDeactivate($component, priceSyncRunId: null);

        $this->assertSame('admin', $combo->refresh()->deactivated_reason);
        $this->assertSame(0, DeactivationLog::query()->count());
    }
}
