<?php

namespace Tests\Concurrency;

use App\Models\Voucher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Same rationale as LedgerWithdrawConcurrencyTest: proves the voucher row
 * lock (VCH-5) actually prevents double-spend, not just that the
 * arithmetic is correct in isolation.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class VoucherRedeemConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_redemptions_succeeds_when_combined_amount_exceeds_remaining(): void
    {
        Voucher::query()->create([
            'code' => 'KRS-RACE-1',
            'customer_email' => 'race@example.com',
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'concurrency test',
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'voucher_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'voucher_test_');

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
            PHP_BINARY, 'artisan', 'app:voucher-test-redeem', 'KRS-RACE-1', '700', $resultFile,
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
        $successes = array_filter($outcomes, fn ($r) => $r === 'success');
        $failures = array_filter($outcomes, fn ($r) => str_starts_with($r, 'failed:'));

        $this->assertCount(1, $successes, 'Expected exactly one success, got: ' . json_encode($outcomes));
        $this->assertCount(1, $failures, 'Expected exactly one failure, got: ' . json_encode($outcomes));

        $voucher = Voucher::query()->where('code', 'KRS-RACE-1')->first();
        $this->assertSame(300, $voucher->remaining);
    }
}
