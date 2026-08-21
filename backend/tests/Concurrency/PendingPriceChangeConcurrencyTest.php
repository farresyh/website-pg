<?php

namespace Tests\Concurrency;

use App\Models\Game;
use App\Models\Package;
use App\Models\PendingPriceChange;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-025 decision #5, flagged as an untested concurrency gap in the
 * ADR's own Consequence to track. Proves the Package row lock
 * (PackagePriceSyncService::evaluatePriceChange()) actually serializes
 * two overlapping sync runs evaluating the same package's price swing
 * at once — the real "scheduled run + a manual 'Sync All Prices Now'
 * click" scenario, not just that the skip-if-already-pending check is
 * correct in isolation (already covered on the fast sqlite suite by
 * PackagePriceSyncServiceTest::test_swing_skips_a_package_that_already_has_an_unresolved_pending_change).
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class PendingPriceChangeConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_sync_runs_flags_the_same_package_swing(): void
    {
        $supplier = Supplier::query()->create([
            'name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR',
        ]);
        $game = Game::query()->create(['name' => 'Mobile Legends', 'slug' => 'mobile-legends']);

        $package = Package::query()->create([
            'game_id' => $game->id,
            'name' => '14 Diamond',
            'cost_price' => 1000,
            'reseller_cost_price' => 1150,
            'markup_percent' => 15,
            'is_active' => true,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV-RACE-1',
        ]);

        $syncedAt = Carbon::now();
        SupplierProduct::query()->create([
            'supplier_id' => $supplier->id,
            'external_ref' => 'GV-RACE-1',
            'name' => '14 Diamond',
            'price_sen' => 1700, // 70% swing over cost_price=1000, past the 50% default threshold
            'status_raw' => 'active',
            'last_synced_at' => $syncedAt,
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'price_sync_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'price_sync_test_');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
        ];

        $command = fn (string $resultFile) => [
            PHP_BINARY, 'artisan', 'app:price-sync-test-evaluate',
            (string) $supplier->id, $syncedAt->toISOString(), $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command($resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($resultFileB));

        $processA->wait();
        $processB->wait();

        $anomaliesA = (int) file_get_contents($resultFileA);
        $anomaliesB = (int) file_get_contents($resultFileB);

        unlink($resultFileA);
        unlink($resultFileB);

        $this->assertSame(1, $anomaliesA + $anomaliesB, 'Expected exactly one process to flag the anomaly, got: '.json_encode(['A' => $anomaliesA, 'B' => $anomaliesB]));
        $this->assertSame(1, PendingPriceChange::query()->where('package_id', $package->id)->count());

        $package->refresh();
        $this->assertFalse($package->is_active);
        $this->assertSame('price_anomaly', $package->deactivated_reason);
    }
}
