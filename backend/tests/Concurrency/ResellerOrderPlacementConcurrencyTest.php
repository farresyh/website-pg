<?php

namespace Tests\Concurrency;

use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerTier;
use App\Models\Supplier;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * ADR-073 decision 4's own consequence-to-track: proves two simultaneous
 * wallet order placements against a near-exhausted balance can't
 * double-spend past zero — the same lock-actually-serializes proof
 * `LedgerWithdrawConcurrencyTest`/`OrderFulfillmentConcurrencyTest`
 * already give their own money paths, not just correct arithmetic in
 * isolation. `ResellerOrderPlacementService::placeOrder()` reuses
 * `LedgerService::debit()`'s existing, already-proven `lockForUpdate()`
 * — this test is the wallet-specific proof that reuse actually holds,
 * not new locking code of its own.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class ResellerOrderPlacementConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_wallet_orders_succeeds_when_combined_price_exceeds_balance(): void
    {
        $this->primaryAffiliate();

        $supplier = Supplier::query()->create([
            'name' => 'Race Test Supplier', 'slug' => 'reseller-race-test-supplier', 'api_config' => [], 'currency' => 'MYR',
        ]);

        $tier = ResellerTier::query()->create([
            'name' => 'Gold', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 1,
        ]);
        $reseller = Reseller::query()->create([
            'business_name' => 'Race Test Reseller', 'reseller_tier_id' => $tier->id, 'is_active' => true,
        ]);

        $ledger = app(LedgerService::class);
        $ledger->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        // cost 900 * 1.05 = 945/order — 1000 balance covers exactly one,
        // never both.
        $ledger->credit(LedgerOwnerType::ResellerWallet, $reseller->id, 1000, 'wallet_topup');

        $resultFileA = tempnam(sys_get_temp_dir(), 'reseller_order_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'reseller_order_test_');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
            'QUEUE_CONNECTION' => 'sync',
        ];

        $command = fn (string $idempotencyKey, string $resultFile) => [
            PHP_BINARY, 'artisan', 'app:reseller-order-test-place',
            (string) $reseller->id, (string) $supplier->id, '900', '900', $idempotencyKey, $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command('race-key-a', $resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command('race-key-b', $resultFileB));

        $processA->wait();
        $processB->wait();

        $resultA = file_get_contents($resultFileA);
        $resultB = file_get_contents($resultFileB);

        unlink($resultFileA);
        unlink($resultFileB);

        $outcomes = [$resultA, $resultB];
        $successes = array_filter($outcomes, fn ($r) => str_starts_with($r, 'success:'));
        $failures = array_filter($outcomes, fn ($r) => str_starts_with($r, 'failed:'));

        $this->assertCount(1, $successes, 'Expected exactly one success, got: '.json_encode($outcomes));
        $this->assertCount(1, $failures, 'Expected exactly one failure, got: '.json_encode($outcomes));
        $this->assertSame(1000 - 945, $ledger->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
        $this->assertSame(1, Order::query()->where('wallet_reseller_id', $reseller->id)->count());
    }
}
