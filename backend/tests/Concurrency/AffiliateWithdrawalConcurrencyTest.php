<?php

namespace Tests\Concurrency;

use App\Models\Affiliate;
use App\Models\LedgerEntry;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Reseller-family harden item B's own consequence-to-track, carried over to
 * ADR-132's monthly payout: proves two simultaneous payout runs for the same
 * `Affiliate` can't both open a ticket — the guard is locking this
 * affiliate's own `('affiliate', id)` `ledger_accounts` row inside the same
 * transaction that plans + creates the row
 * (AffiliateWithdrawalService::openMonthly()), with the unique
 * `(owner_type, owner_id, payout_period)` index as the backstop. Mirrors
 * ResellerWalletTopupConcurrencyTest's real-subprocess proof of the
 * identical class of race.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class AffiliateWithdrawalConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_payout_runs_opens_a_ticket(): void
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Race Test Affiliate',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
            'bank_name' => 'Maybank',
            'bank_account_no' => '1234567',
            'bank_account_holder' => 'Race Test Affiliate',
        ]);
        app(LedgerService::class)->credit(LedgerOwnerType::Affiliate, $affiliate->id, 50000, 'order_profit', 'order', 1);
        // Earned last month: before this month's cut-off, so it is payable.
        LedgerEntry::query()->update(['created_at' => $this->midLastMonth()]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'affiliate_withdrawal_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'affiliate_withdrawal_test_');

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
            PHP_BINARY, 'artisan', 'app:affiliate-payout-test-open',
            (string) $affiliate->id, $resultFile,
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
        $successes = array_filter($outcomes, fn ($r) => str_starts_with($r, 'opened:'));
        $failures = array_filter($outcomes, fn ($r) => $r === 'skipped');

        $this->assertCount(1, $successes, 'Expected exactly one opened, got: '.json_encode($outcomes));
        $this->assertCount(1, $failures, 'Expected exactly one skipped, got: '.json_encode($outcomes));
        $this->assertSame(1, Withdrawal::query()->where('owner_type', 'affiliate')->where('owner_id', $affiliate->id)->count());
    }
}
