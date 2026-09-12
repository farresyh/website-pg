<?php

namespace Tests\Concurrency;

use App\Models\Affiliate;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Reseller-family harden item B's own consequence-to-track: proves two
 * simultaneous withdrawal requests for the same `Affiliate` can't both
 * create a pending `Withdrawal` — the guard is enforced by locking this
 * affiliate's own `('affiliate', id)` `ledger_accounts` row inside the
 * same transaction that checks + creates the row
 * (AffiliateWithdrawalService::request()), mirroring
 * ResellerWalletTopupConcurrencyTest's own real-subprocess proof of the
 * identical class of race.
 *
 * The balance itself (50000 sen) covers either single request (30000)
 * comfortably but not both at once — if the race weren't closed, both
 * could land as `pending` before the "already has an open request"
 * check ever saw the other's row.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class AffiliateWithdrawalConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_withdrawal_requests_creates_a_pending_row(): void
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Race Test Affiliate',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
        app(LedgerService::class)->credit(LedgerOwnerType::Affiliate, $affiliate->id, 50000, 'order_profit', 'order', 1);

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
            PHP_BINARY, 'artisan', 'app:affiliate-withdrawal-test-request',
            (string) $affiliate->id, '30000', $resultFile,
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
        $this->assertSame(1, Withdrawal::query()->where('owner_type', 'affiliate')->where('owner_id', $affiliate->id)->count());
    }
}
