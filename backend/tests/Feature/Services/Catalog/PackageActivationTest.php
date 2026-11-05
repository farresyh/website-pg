<?php

namespace Tests\Feature\Services\Catalog;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Catalog\PackageActivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ADR-015 2026-11-05 addendum: a package is switched on only through
 * PackageActivation, which checks the blockers that can hold while the
 * package stores one reason. Gap A (a supplier's Reactivate ignored an open
 * price swing and a vanished product) and Gap B (approving or dismissing a
 * swing switched on a package off for another reason).
 */
class PackageActivationTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsSteppedUp(AdminUser::factory()->create(['role' => 'super_admin']));
        $this->game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
    }

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::query()->create(array_merge([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ], $overrides));
    }

    private function package(Supplier $supplier, string $reason, array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $this->game->id,
            'name' => '86 Diamonds',
            'cost_price' => 1000,
            'standard_selling_price' => 1150,
            'markup_percent' => 15,
            'is_active' => false,
            'deactivated_reason' => $reason,
            'deactivated_at' => now()->subHour(),
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'REF-'.uniqid(),
        ], $overrides));
    }

    private function swing(Package $package): PendingPriceChange
    {
        return PendingPriceChange::query()->create([
            'package_id' => $package->id,
            'old_cost_price' => 1000,
            'proposed_cost_price' => 1600,
            'old_standard_selling_price' => 1150,
            'proposed_standard_selling_price' => 1840,
            'status' => 'pending',
        ]);
    }

    private function listed(Supplier $supplier, string $ref, Carbon $syncedAt, string $status = 'active'): void
    {
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id, 'external_ref' => $ref, 'name' => $ref,
            'status_raw' => $status, 'last_synced_at' => $syncedAt, 'price_sen' => 1000,
        ]);
    }

    public function test_supplier_reactivate_leaves_a_package_with_an_open_price_swing_off(): void
    {
        $supplier = $this->supplier();
        $package = $this->package($supplier, 'supplier_issue');
        $this->swing($package);

        $this->patchJson("/api/middleware/suppliers/{$supplier->id}/packages/status", ['is_active' => true])
            ->assertOk()->assertJson(['updated' => 0, 'skipped' => 1]);

        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('price_anomaly', $package->deactivated_reason);
    }

    public function test_supplier_reactivate_leaves_a_package_its_supplier_no_longer_lists_off(): void
    {
        $supplier = $this->supplier(['api_config' => ['base_url' => 'https://example.test']]);
        $vanished = $this->package($supplier, 'supplier_issue', ['supplier_package_ref' => 'OLD']);
        $listed = $this->package($supplier, 'supplier_issue', ['supplier_package_ref' => 'NEW']);
        $this->listed($supplier, 'OLD', now()->subDay());
        $this->listed($supplier, 'NEW', now());

        $this->patchJson("/api/middleware/suppliers/{$supplier->id}/packages/status", ['is_active' => true])
            ->assertOk()->assertJson(['updated' => 1, 'skipped' => 1]);

        $this->assertTrue($listed->fresh()->is_active);
        $this->assertFalse($vanished->fresh()->is_active);
        $this->assertSame('supplier_sync', $vanished->fresh()->deactivated_reason);
    }

    public function test_approving_a_swing_on_a_package_whose_supplier_is_off_updates_the_cost_but_keeps_it_off(): void
    {
        $supplier = $this->supplier(['is_active' => false]);
        $package = $this->package($supplier, 'supplier_issue');
        $swing = $this->swing($package);

        $this->patchJson("/api/middleware/price-sync/pending-price-changes/{$swing->id}/approve")->assertOk();

        $package->refresh();
        $this->assertSame(1600, $package->cost_price);
        $this->assertFalse($package->is_active);
        $this->assertSame('supplier_issue', $package->deactivated_reason);
        $this->assertSame('approved', $swing->fresh()->status);
    }

    public function test_approving_or_dismissing_a_swing_never_overrides_an_admin_switch_off(): void
    {
        $supplier = $this->supplier();
        $approved = $this->package($supplier, 'admin');
        $dismissed = $this->package($supplier, 'admin');

        $this->patchJson('/api/middleware/price-sync/pending-price-changes/'.$this->swing($approved)->id.'/approve')->assertOk();
        $this->patchJson('/api/middleware/price-sync/pending-price-changes/'.$this->swing($dismissed)->id.'/dismiss')->assertOk();

        foreach ([$approved, $dismissed] as $package) {
            $this->assertFalse($package->fresh()->is_active);
            $this->assertSame('admin', $package->fresh()->deactivated_reason);
        }
    }

    public function test_approving_a_swing_still_switches_a_price_anomaly_package_on(): void
    {
        $package = $this->package($this->supplier(), 'price_anomaly');

        $this->patchJson('/api/middleware/price-sync/pending-price-changes/'.$this->swing($package)->id.'/approve')->assertOk();

        $this->assertTrue($package->fresh()->is_active);
        $this->assertNull($package->fresh()->deactivated_reason);
    }

    public function test_an_admin_switch_on_from_games_is_refused_while_the_supplier_is_off(): void
    {
        $package = $this->package($this->supplier(['is_active' => false]), 'admin');

        $this->patchJson("/api/packages/{$package->id}/status", ['is_active' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->assertFalse($package->fresh()->is_active);
        $this->assertSame('supplier_issue', $package->fresh()->deactivated_reason);
    }

    public function test_restoring_a_dismissed_package_is_refused_while_its_price_swing_is_open(): void
    {
        $package = $this->package($this->supplier(), 'admin');
        $this->swing($package);

        $this->patchJson("/api/middleware/price-sync/dismissed-packages/{$package->id}/restore")->assertUnprocessable();

        $this->assertFalse($package->fresh()->is_active);
        $this->assertSame('price_anomaly', $package->fresh()->deactivated_reason);
    }

    public function test_a_foreign_supplier_with_no_deposit_blocks_activation(): void
    {
        $package = $this->package($this->supplier(['currency' => 'IDR']), 'admin');

        $this->assertFalse(app(PackageActivation::class)->activate($package, null));
        $this->assertSame('unfunded', $package->fresh()->deactivated_reason);
    }

    public function test_a_combo_stays_off_while_a_component_is_off(): void
    {
        $supplier = $this->supplier();
        $component = $this->package($supplier, 'admin');
        $combo = Package::query()->create([
            'game_id' => $this->game->id, 'name' => 'Combo', 'cost_price' => 2000, 'standard_selling_price' => 2300,
            'markup_percent' => 15, 'is_combo' => true, 'is_active' => false, 'deactivated_reason' => 'admin',
        ]);
        $combo->components()->attach($component->id, ['quantity' => 2]);

        $this->assertFalse(app(PackageActivation::class)->activate($combo, null));
        $this->assertSame('combo_component_deactivated', $combo->fresh()->deactivated_reason);
    }

    public function test_a_caller_never_clears_a_reason_it_does_not_own(): void
    {
        $package = $this->package($this->supplier(), 'admin');

        $this->assertFalse(app(PackageActivation::class)->activate($package, 'supplier_issue'));
        $this->assertSame('admin', $package->fresh()->deactivated_reason);
    }

    /** The seam is the only code that switches a package back on. */
    public function test_no_other_code_clears_a_package_deactivation(): void
    {
        $seam = realpath(app_path('Services/Catalog/PackageActivation.php'));
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === $seam) {
                continue;
            }

            $source = file_get_contents($file->getRealPath());
            if (preg_match("/'deactivated_reason'\s*=>\s*null/", $source) || preg_match("/\\$(package|combo)\\w*->update\(\[\s*'is_active'\s*=>\s*true/i", $source)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getRealPath());
            }
        }

        $this->assertSame([], $offenders, 'Switch a package on through PackageActivation (ADR-015 2026-11-05 addendum).');
    }
}
