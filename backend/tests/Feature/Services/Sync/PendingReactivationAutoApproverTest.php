<?php

namespace Tests\Feature\Services\Sync;

use App\Models\DeactivationLog;
use App\Models\Game;
use App\Models\Package;
use App\Models\PackageReactivationLog;
use App\Models\PriceSyncRun;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Sync\PendingReactivationAutoApprover;
use App\Services\Sync\PendingReactivationFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ADR-100 — PendingReactivationAutoApprover's own decision tree,
 * tested in isolation from the sync job that calls it. Every test
 * sets `pending_reactivation_auto_approve` explicitly (never relies
 * on the real default) so this file stays correct however that
 * default is ever configured.
 */
class PendingReactivationAutoApproverTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{0: Supplier, 1: Package}
     */
    private function pendingPackage(array $supplierOverrides = [], array $productOverrides = [], array $packageOverrides = []): array
    {
        $supplier = Supplier::query()->create(array_merge([
            'name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'IDR',
        ], $supplierOverrides));
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends-'.$supplier->id]);
        $package = Package::query()->create(array_merge([
            'game_id' => $game->id, 'name' => '16 Tokens', 'cost_price' => 1000, 'standard_selling_price' => 1150,
            'is_active' => false, 'deactivated_reason' => 'supplier_sync', 'deactivated_at' => now()->subHours(3),
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'HOK16',
        ], $packageOverrides));

        SupplierProduct::query()->create(array_merge([
            'supplier_id' => $supplier->id, 'external_ref' => 'HOK16', 'name' => '16 Tokens',
            'status_raw' => 'active', 'last_synced_at' => now(), 'consecutive_active_syncs' => 0,
        ], $productOverrides));

        return [$supplier, $package];
    }

    public function test_config_off_by_default_is_a_no_op(): void
    {
        config(['packages.pending_reactivation_auto_approve' => false]);
        [$supplier, $package] = $this->pendingPackage(productOverrides: ['consecutive_active_syncs' => 5]);

        $approved = (new PendingReactivationAutoApprover(new PendingReactivationFinder))->run($supplier, null);

        $this->assertSame(0, $approved);
        $this->assertFalse($package->fresh()->is_active);
        $this->assertSame(0, PackageReactivationLog::query()->count());
    }

    public function test_cutoff_window_match_approves_immediately_regardless_of_streak(): void
    {
        config(['packages.pending_reactivation_auto_approve' => true]);
        // 2026-09-16 16:30 UTC = 23:30 Asia/Jakarta — inside a
        // 23:00-01:00 (wraparound) cutoff window.
        Carbon::setTestNow(Carbon::parse('2026-09-16 16:30:00', 'UTC'));

        [$supplier, $package] = $this->pendingPackage(productOverrides: [
            'cutoff_start' => '23:00', 'cutoff_end' => '01:00', 'consecutive_active_syncs' => 0,
        ]);
        $run = PriceSyncRun::query()->create(['status' => 'running']);

        $approved = (new PendingReactivationAutoApprover(new PendingReactivationFinder))->run($supplier, $run->id);

        $this->assertSame(1, $approved);
        $package->refresh();
        $this->assertTrue($package->is_active);
        $this->assertNull($package->deactivated_reason);
        $this->assertNull($package->deactivated_at);

        $log = PackageReactivationLog::query()->where('package_id', $package->id)->firstOrFail();
        $this->assertSame('cutoff_window', $log->trigger);
        $this->assertSame($run->id, $log->price_sync_run_id);
    }

    public function test_ambiguous_cutoff_start_equals_end_falls_through_to_stability_gate(): void
    {
        config(['packages.pending_reactivation_auto_approve' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-16 16:30:00', 'UTC')); // 23:30 WIB

        // Would match a real "23:30-ish" window, but start===end is
        // Digiflazz's own "possibly unset" sentinel — must NOT
        // auto-approve via the cutoff trigger. Streak is also below
        // the stability threshold, so this package must stay pending
        // via EITHER path.
        [$supplier, $package] = $this->pendingPackage(productOverrides: [
            'cutoff_start' => '00:00', 'cutoff_end' => '00:00', 'consecutive_active_syncs' => 0,
        ]);

        $approved = (new PendingReactivationAutoApprover(new PendingReactivationFinder))->run($supplier, null);

        $this->assertSame(0, $approved);
        $this->assertFalse($package->fresh()->is_active);
    }

    public function test_stability_confirmed_approves_after_the_required_streak(): void
    {
        config([
            'packages.pending_reactivation_auto_approve' => true,
            'packages.reactivation_stability_syncs' => 2,
        ]);
        [$supplier, $package] = $this->pendingPackage(
            supplierOverrides: ['slug' => 'gamevion', 'currency' => 'MYR'],
            productOverrides: ['consecutive_active_syncs' => 2],
        );

        $approved = (new PendingReactivationAutoApprover(new PendingReactivationFinder))->run($supplier, null);

        $this->assertSame(1, $approved);
        $this->assertTrue($package->fresh()->is_active);
        $this->assertSame(
            'stability_confirmed',
            PackageReactivationLog::query()->where('package_id', $package->id)->value('trigger'),
        );
    }

    public function test_insufficient_streak_does_not_approve(): void
    {
        config([
            'packages.pending_reactivation_auto_approve' => true,
            'packages.reactivation_stability_syncs' => 2,
        ]);
        [$supplier, $package] = $this->pendingPackage(productOverrides: ['consecutive_active_syncs' => 1]);

        $approved = (new PendingReactivationAutoApprover(new PendingReactivationFinder))->run($supplier, null);

        $this->assertSame(0, $approved);
        $this->assertFalse($package->fresh()->is_active);
    }

    public function test_excessive_flap_count_blocks_approval_even_with_a_sufficient_streak(): void
    {
        config([
            'packages.pending_reactivation_auto_approve' => true,
            'packages.reactivation_stability_syncs' => 2,
            'packages.reactivation_flap_limit_per_14_days' => 2,
        ]);
        [$supplier, $package] = $this->pendingPackage(productOverrides: ['consecutive_active_syncs' => 5]);

        // 3 unexplained flaps in the last 14 days — over the limit of 2.
        // `DeactivationLog::created_at` isn't fillable, so each one is
        // created under its own frozen clock instead of a passed value.
        for ($i = 0; $i < 3; $i++) {
            Carbon::setTestNow(now()->subDays(2 + $i));
            DeactivationLog::query()->create(['package_id' => $package->id]);
        }
        Carbon::setTestNow();

        $approved = (new PendingReactivationAutoApprover(new PendingReactivationFinder))->run($supplier, null);

        $this->assertSame(0, $approved);
        $this->assertFalse($package->fresh()->is_active);
    }

    /**
     * ADR-100 decision Q6 — a package can flap far past the raw limit
     * and still auto-approve via the stability gate, as long as every
     * one of those historical flaps falls inside its OWN current
     * cutoff window: they don't count as "unexplained" instability.
     */
    public function test_flaps_inside_the_packages_own_cutoff_window_do_not_count_toward_the_limit(): void
    {
        config([
            'packages.pending_reactivation_auto_approve' => true,
            'packages.reactivation_stability_syncs' => 2,
            'packages.reactivation_flap_limit_per_14_days' => 2,
        ]);
        // "Now" is outside the cutoff window (10:00 WIB), so this run
        // is evaluated via the stability gate, not the cutoff trigger
        // — isolates exactly what's under test.
        Carbon::setTestNow(Carbon::parse('2026-09-16 03:00:00', 'UTC')); // 10:00 WIB

        [$supplier, $package] = $this->pendingPackage(productOverrides: [
            'cutoff_start' => '23:00', 'cutoff_end' => '01:00', 'consecutive_active_syncs' => 2,
        ]);

        // 5 flaps, every one at 23:30 WIB (16:30 UTC) — inside the
        // package's own cutoff window — far past the raw limit of 2.
        // `DeactivationLog::created_at` isn't fillable (Eloquent would
        // otherwise silently drop it and stamp the real test "now"),
        // so each one is created under its own frozen clock instead.
        for ($i = 0; $i < 5; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-09-'.(10 + $i).' 16:30:00', 'UTC'));
            DeactivationLog::query()->create(['package_id' => $package->id]);
        }
        Carbon::setTestNow(Carbon::parse('2026-09-16 03:00:00', 'UTC')); // back to "now" = 10:00 WIB

        $approved = (new PendingReactivationAutoApprover(new PendingReactivationFinder))->run($supplier, null);

        $this->assertSame(1, $approved);
        $this->assertTrue($package->fresh()->is_active);
        $this->assertSame(
            'stability_confirmed',
            PackageReactivationLog::query()->where('package_id', $package->id)->value('trigger'),
        );
    }

    public function test_a_supplier_product_not_confirmed_active_since_deactivation_is_never_touched(): void
    {
        config(['packages.pending_reactivation_auto_approve' => true]);

        [$supplier, $package] = $this->pendingPackage(
            productOverrides: ['status_raw' => 'inactive', 'consecutive_active_syncs' => 0],
        );

        $approved = (new PendingReactivationAutoApprover(new PendingReactivationFinder))->run($supplier, null);

        $this->assertSame(0, $approved);
        $this->assertFalse($package->fresh()->is_active);
    }

    public function test_scoped_to_the_given_supplier_only(): void
    {
        config([
            'packages.pending_reactivation_auto_approve' => true,
            'packages.reactivation_stability_syncs' => 2,
        ]);

        [$digiflazz, $digiflazzPackage] = $this->pendingPackage(productOverrides: ['consecutive_active_syncs' => 5]);
        [$gamevion, $gamevionPackage] = $this->pendingPackage(
            supplierOverrides: ['slug' => 'gamevion', 'currency' => 'MYR'],
            productOverrides: ['consecutive_active_syncs' => 5],
            packageOverrides: ['supplier_package_ref' => 'GV16'],
        );
        SupplierProduct::query()->where('supplier_id', $gamevion->id)->update(['external_ref' => 'GV16']);

        $approved = (new PendingReactivationAutoApprover(new PendingReactivationFinder))->run($digiflazz, null);

        $this->assertSame(1, $approved);
        $this->assertTrue($digiflazzPackage->fresh()->is_active);
        $this->assertFalse($gamevionPackage->fresh()->is_active, 'A different supplier\'s package must not be touched by this run.');
    }
}
