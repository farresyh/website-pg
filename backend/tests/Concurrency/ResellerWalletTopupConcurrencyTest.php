<?php

namespace Tests\Concurrency;

use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * PR-G planning addendum decision 8's own consequence-to-track: proves
 * two simultaneous self-serve wallet top-up initiations for the same
 * `Reseller` can't both create a `pending` attempt — the guard is
 * enforced by locking this reseller's own `('reseller_wallet', id)`
 * `ledger_accounts` row inside the same transaction that checks +
 * creates the row, mirroring `ResellerOrderPlacementConcurrencyTest`'s
 * own real-subprocess proof of `LedgerService::debit()`'s lock.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class ResellerWalletTopupConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_topup_initiations_creates_a_pending_attempt(): void
    {
        PaymentMethod::query()->create([
            'channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip',
            'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100,
        ]);

        $reseller = Reseller::query()->create(['business_name' => 'Race Test Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        $resultFileA = tempnam(sys_get_temp_dir(), 'wallet_topup_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'wallet_topup_test_');

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

        $command = fn (string $resultFile) => [
            PHP_BINARY, 'artisan', 'app:reseller-wallet-topup-test-initiate',
            (string) $reseller->id, $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command($resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($resultFileB));

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
        $this->assertSame(1, WalletTopupAttempt::query()->where('reseller_id', $reseller->id)->count());
    }

    /**
     * ADR-131 decision 5: `credit()` takes no lock, so the limits check takes the reseller's mutex
     * itself. Two credits that each fit under the cap but not together must not both pass.
     * (A pending CHIP attempt is deliberately not counted, decision 6, so a top-up start racing a
     * manual credit may legitimately let both through; only two credits are asserted here.)
     *
     * @return array{0: list<string>, 1: Reseller}
     */
    private function raceTwoManualCredits(): array
    {
        PaymentMethod::query()->create([
            'channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip',
            'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100,
        ]);
        $admin = \App\Models\AdminUser::factory()->superAdmin()->create();
        $reseller = Reseller::query()->create(['business_name' => 'Cap Race', 'is_active' => true, 'balance_cap_sen' => 300_000]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        $files = [tempnam(sys_get_temp_dir(), 'cap_race_'), tempnam(sys_get_temp_dir(), 'cap_race_')];
        $env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox', 'DB_USERNAME' => 'kerox', 'DB_PASSWORD' => 'kerox', 'QUEUE_CONNECTION' => 'sync',
        ];
        $base = fn (string $file, array $extra) => array_merge(
            [PHP_BINARY, 'artisan', 'app:reseller-wallet-topup-test-initiate', (string) $reseller->id, $file, '--amount=200000'],
            $extra,
        );

        $a = Process::path(base_path())->env($env)->start($base($files[0], ['--manual-admin='.$admin->id]));
        $b = Process::path(base_path())->env($env)->start($base($files[1], ['--manual-admin='.$admin->id]));
        $a->wait();
        $b->wait();

        $outcomes = array_map('file_get_contents', $files);
        array_map('unlink', $files);

        return [$outcomes, $reseller];
    }

    public function test_two_simultaneous_manual_credits_cannot_both_pass_the_balance_cap(): void
    {
        [$outcomes, $reseller] = $this->raceTwoManualCredits();

        $this->assertCount(1, array_filter($outcomes, fn ($r) => str_starts_with($r, 'success:')), json_encode($outcomes));
        $this->assertCount(1, array_filter($outcomes, fn ($r) => str_starts_with($r, 'failed:')), json_encode($outcomes));
        $this->assertSame(200_000, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
    }
}
