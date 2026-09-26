<?php

namespace Tests\Concurrency;

use App\Models\AdminUser;
use App\Models\Withdrawal;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Proves the fix for the WithdrawalController::complete() race found during
 * the 2026-09-26 money-critical branch audit (item 32): without a row lock,
 * two admins marking the same Approved withdrawal completed at nearly the
 * same time could both pass the Approved check before either commits.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class WithdrawalCompleteConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_completions_succeeds(): void
    {
        $requester = AdminUser::factory()->create(['role' => 'admin']);

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 30_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Approved,
            'requested_by' => $requester->id,
            'approved_by' => $requester->id,
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'withdrawal_complete_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'withdrawal_complete_test_');

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
            PHP_BINARY, 'artisan', 'app:withdrawal-test-complete', (string) $withdrawal->id, $resultFile,
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

        $this->assertCount(1, $successes, 'Expected exactly one success, got: '.json_encode($outcomes));
        $this->assertCount(1, $failures, 'Expected exactly one failure, got: '.json_encode($outcomes));

        $fresh = Withdrawal::query()->findOrFail($withdrawal->id);
        $this->assertSame(WithdrawalStatus::Completed, $fresh->status);
    }
}
