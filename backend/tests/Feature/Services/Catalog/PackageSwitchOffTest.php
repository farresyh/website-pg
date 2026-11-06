<?php

namespace Tests\Feature\Services\Catalog;

use App\Models\AdminUser;
use App\Models\DeactivationLog;
use App\Models\Game;
use App\Models\Package;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Catalog\PackageActivation;
use App\Services\Supplier\SupplierPackageStatusService;
use App\Services\Sync\PackagePriceSyncService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADR-138 decisions 1, 2, 5: PackageActivation is the one door for switching
 * a package off. It writes the reason, the log and the combo cascade together,
 * so no off path can leave a combo on sale with an off component.
 */
class PackageSwitchOffTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private Supplier $supplier;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::factory()->create(['role' => 'super_admin']);
        $this->actingAsSteppedUp($this->admin);
        $this->game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $this->supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
    }

    private function package(string $ref = 'GV1', array $overrides = []): Package
    {
        return Package::query()->create(array_merge([
            'game_id' => $this->game->id,
            'name' => $ref,
            'cost_price' => 1000,
            'standard_selling_price' => 1150,
            'markup_percent' => 15,
            'is_active' => true,
            'supplier_id' => $this->supplier->id,
            'supplier_package_ref' => $ref,
        ], $overrides));
    }

    private function comboOf(Package ...$components): Package
    {
        $combo = Package::query()->create([
            'game_id' => $this->game->id, 'name' => 'Combo '.uniqid(), 'is_combo' => true, 'is_active' => true,
            'denomination' => 14, 'cost_price' => 1000, 'standard_selling_price' => 1150, 'markup_percent' => 0,
        ]);
        foreach ($components as $i => $component) {
            $combo->components()->attach($component->id, ['quantity' => 1, 'sort_order' => $i]);
        }

        return $combo;
    }

    private function seam(): PackageActivation
    {
        return app(PackageActivation::class);
    }

    private function product(string $ref, int $price, string $status, $syncedAt): void
    {
        SupplierProduct::query()->create([
            'supplier_id' => $this->supplier->id, 'external_ref' => $ref, 'name' => $ref,
            'price_sen' => $price, 'status_raw' => $status, 'last_synced_at' => $syncedAt,
        ]);
    }

    public function test_switching_off_writes_the_reason_the_log_and_the_combo_cascade(): void
    {
        $component = $this->package();
        $combo = $this->comboOf($component);
        $run = PriceSyncRun::query()->create(['status' => 'running']);

        $this->assertTrue($this->seam()->deactivate($component, PackageActivation::SUPPLIER_SYNC, $run->id));

        $this->assertFalse($component->is_active, 'the caller\'s model reflects the switch-off');
        $component->refresh();
        $this->assertSame(PackageActivation::SUPPLIER_SYNC, $component->deactivated_reason);
        $this->assertNotNull($component->deactivated_at);
        $this->assertSame($run->id, DeactivationLog::query()->where('package_id', $component->id)->firstOrFail()->price_sync_run_id);

        $combo->refresh();
        $this->assertFalse($combo->is_active);
        $this->assertSame(PackageActivation::COMBO_COMPONENT, $combo->deactivated_reason);
        $this->assertNotNull($combo->deactivated_at);
        $this->assertSame($run->id, DeactivationLog::query()->where('package_id', $combo->id)->firstOrFail()->price_sync_run_id);
    }

    public function test_a_package_already_off_keeps_its_reason_and_gets_no_log(): void
    {
        $package = $this->package('GV1', ['is_active' => false, 'deactivated_reason' => PackageActivation::ADMIN]);

        $this->assertFalse($this->seam()->deactivate($package, PackageActivation::SUPPLIER_SYNC));

        $this->assertSame(PackageActivation::ADMIN, $package->refresh()->deactivated_reason);
        $this->assertSame(0, DeactivationLog::query()->count());
    }

    public function test_a_combo_already_off_keeps_its_reason_and_gets_no_log(): void
    {
        $component = $this->package();
        $combo = $this->comboOf($component);
        $combo->update(['is_active' => false, 'deactivated_reason' => PackageActivation::ADMIN]);

        $this->seam()->deactivate($component, PackageActivation::SUPPLIER_SYNC);

        $this->assertSame(PackageActivation::ADMIN, $combo->refresh()->deactivated_reason);
        $this->assertSame(0, DeactivationLog::query()->where('package_id', $combo->id)->count());
    }

    /** Off and cascade are one transaction: a crash can't leave the combo on with the component off. */
    public function test_a_failed_switch_off_changes_nothing(): void
    {
        $component = $this->package();
        $combo = $this->comboOf($component);
        Schema::drop('deactivation_logs');

        try {
            $this->seam()->deactivate($component, PackageActivation::SUPPLIER_SYNC);
            $this->fail('The log write should have failed.');
        } catch (QueryException) {
        }

        $this->assertTrue($component->fresh()->is_active);
        $this->assertTrue($combo->fresh()->is_active);
    }

    public function test_bulk_switch_off_logs_every_row_and_reports_the_combos(): void
    {
        $a = $this->package('GVA');
        $b = $this->package('GVB');
        $alreadyOff = $this->package('GVC', ['is_active' => false, 'deactivated_reason' => PackageActivation::ADMIN]);
        $unrelated = $this->package('GVD');
        $comboA = $this->comboOf($a);
        $comboB = $this->comboOf($b);
        $comboUnrelated = $this->comboOf($unrelated);

        $combos = $this->seam()->deactivateMany(
            collect([$a->id, $b->id, $alreadyOff->id]),
            SupplierPackageStatusService::REASON,
            $this->admin->id,
            'Supplier outage',
        );

        $this->assertSame(2, $combos);
        $this->assertSame(SupplierPackageStatusService::REASON, $a->refresh()->deactivated_reason);
        $this->assertSame(PackageActivation::ADMIN, $alreadyOff->refresh()->deactivated_reason);
        $this->assertSame(0, DeactivationLog::query()->where('package_id', $alreadyOff->id)->count());
        $this->assertFalse($comboA->refresh()->is_active);
        $this->assertFalse($comboB->refresh()->is_active);
        $this->assertTrue($comboUnrelated->refresh()->is_active);

        $log = DeactivationLog::query()->where('package_id', $comboA->id)->firstOrFail();
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertSame('Supplier outage', $log->reason);
    }

    public function test_an_admin_switch_off_records_why_so_it_can_be_found_again(): void
    {
        $package = $this->package();

        $this->patchJson("/api/packages/{$package->id}/status", ['is_active' => false])->assertOk();

        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame(PackageActivation::ADMIN, $package->deactivated_reason);
        $this->assertNotNull($package->deactivated_at);
        $this->assertSame($this->admin->id, DeactivationLog::query()->where('package_id', $package->id)->firstOrFail()->admin_user_id);
    }

    public function test_the_backfill_explains_admin_switch_offs_and_leaves_the_joki_pool_alone(): void
    {
        $unexplained = $this->package('GV-OLD', ['is_active' => false]);
        $onSale = $this->package('GV-ON');
        $explained = $this->package('GV-SYNC', ['is_active' => false, 'deactivated_reason' => PackageActivation::SUPPLIER_SYNC]);
        $pool = Supplier::query()->where('joki_role', Supplier::JOKI_POOL)->firstOrFail();
        $joki = $this->package('JOKI', ['is_active' => false, 'supplier_id' => $pool->id]);

        $migration = require database_path('migrations/2026_11_11_120000_record_admin_reason_on_unexplained_off_packages.php');
        $migration->up();
        $migration->up();

        $unexplained->refresh();
        $this->assertSame(PackageActivation::ADMIN, $unexplained->deactivated_reason);
        $this->assertNotNull($unexplained->deactivated_at);
        $this->assertFalse($unexplained->is_active);
        $this->assertNull($onSale->refresh()->deactivated_reason);
        $this->assertSame(PackageActivation::SUPPLIER_SYNC, $explained->refresh()->deactivated_reason);
        $this->assertNull($joki->refresh()->deactivated_reason);
    }

    /** Decision 5's behaviour layer: drive every off path, then look for a combo still on sale. */
    public function test_no_off_path_leaves_a_combo_on_sale_with_an_off_component(): void
    {
        $unseen = $this->package('GV-UNSEEN');
        $unlisted = $this->package('GV-INACTIVE');
        $byAdmin = $this->package('GV-ADMIN');
        $packages = [$unseen, $unlisted, $byAdmin];
        foreach ($packages as $package) {
            $this->comboOf($package);
        }

        $syncedAt = now();
        $this->product('GV-INACTIVE', 1000, 'inactive', $syncedAt);
        $this->product('GV-ADMIN', 1000, 'active', $syncedAt);
        app(PackagePriceSyncService::class)->apply($this->supplier, $syncedAt, priceSyncRunId: null);
        $this->patchJson("/api/packages/{$byAdmin->id}/status", ['is_active' => false, 'acknowledge_cascade' => true])->assertOk();

        $bulk = $this->package('GV-BULK');
        $unfunded = $this->package('GV-UNFUNDED');
        $this->comboOf($bulk);
        app(SupplierPackageStatusService::class)->deactivate($this->supplier, $this->admin->id, 'Outage');
        $this->comboOf($unfunded);
        $unfunded->update(['is_active' => true, 'deactivated_reason' => null, 'deactivated_at' => null]);
        app(SupplierPackageStatusService::class)->deactivate($this->supplier, null, null, code: SupplierPackageStatusService::UNFUNDED);

        foreach ([...$packages, $bulk, $unfunded] as $package) {
            $this->assertFalse($package->fresh()->is_active, $package->name);
        }

        $this->assertSame(0, Package::query()->where('is_combo', true)->where('is_active', true)
            ->whereHas('components', fn ($q) => $q->where('packages.is_active', false))->count());
    }

    /** Decision 5's static layer: the mirror of PackageActivationTest's switch-on check. */
    public function test_no_other_code_writes_a_package_switch_off(): void
    {
        $allowed = [
            realpath(app_path('Services/Catalog/PackageActivation.php')),
            // Reason only, on a package already off: it files a Pending Reactivation under Dismissed.
            realpath(app_path('Http/Controllers/Middleware/PendingReactivationController.php')),
        ];
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getRealPath(), $allowed, true)) {
                continue;
            }

            $source = file_get_contents($file->getRealPath());
            if (preg_match("/'deactivated_reason'\s*=>/", $source) || preg_match("/\\$(package|combo)\\w*->update\(\[\s*'is_active'\s*=>\s*false/", $source)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getRealPath());
            }
        }

        $this->assertSame([], $offenders, 'Switch a package off through PackageActivation (ADR-138).');
    }
}
