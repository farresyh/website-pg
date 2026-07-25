<?php

namespace Tests\Concurrency;

use App\Models\AdminUser;
use App\Models\LedgerEntry;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use App\Services\Withdrawal\WithdrawalStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Proves the fix for the WithdrawalController::approve() race found during
 * the 2026-07-25 codebase audit: without a row lock, two admins approving
 * the same Withdrawal at nearly the same time could both pass the pending
 * check and both write a ledger debit for one request — double-spending a
 * single withdrawal. Same rationale as LedgerWithdrawConcurrencyTest /
 * OrderFulfillmentConcurrencyTest: proves the lock actually serializes
 * concurrent attempts, not just that the logic is correct in isolation.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class WithdrawalApproveConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_only_one_of_two_simultaneous_approvals_succeeds(): void
    {
        $ledger = app(LedgerService::class);
        $ledger->openAccount('platform', null);
        $ledger->credit('platform', null, 50_000, 'adjustment');

        $requester = AdminUser::factory()->create(['role' => 'admin']);
        $approver = AdminUser::factory()->create(['role' => 'admin']);

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 30_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Pending,
            'requested_by' => $requester->id,
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'withdrawal_approve_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'withdrawal_approve_test_');

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
            PHP_BINARY, 'artisan', 'app:withdrawal-test-approve', (string) $withdrawal->id, (string) $approver->id, $resultFile,
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

        $this->assertSame(20_000, $ledger->balance('platform', null));
        $this->assertSame(
            1,
            LedgerEntry::query()->where('reference_type', 'withdrawal')->where('reference_id', $withdrawal->id)->count(),
        );

        $fresh = Withdrawal::query()->findOrFail($withdrawal->id);
        $this->assertSame(WithdrawalStatus::Approved, $fresh->status);
    }
}
