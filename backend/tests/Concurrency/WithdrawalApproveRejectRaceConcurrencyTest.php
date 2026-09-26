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
 * Proves the cross-action race item 32 actually cares about: an approve()
 * racing a reject() on the same Pending withdrawal. Before the fix,
 * reject()'s unconditional update() could overwrite an already-approved
 * (and already ledger-debited) withdrawal back to Rejected — money paid
 * out but the record says it wasn't. The row lock must make these two
 * mutually exclusive, and whichever one wins must leave the ledger and the
 * status in agreement.
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class WithdrawalApproveRejectRaceConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_approve_and_reject_racing_the_same_withdrawal_stay_consistent(): void
    {
        $ledger = app(LedgerService::class);
        $ledger->openAccount('platform', null);
        $ledger->credit('platform', null, 50_000, 'adjustment');

        $requester = AdminUser::factory()->create(['role' => 'admin']);
        $approver = AdminUser::factory()->create(['role' => 'admin']);
        $rejecter = AdminUser::factory()->create(['role' => 'admin']);

        $withdrawal = Withdrawal::query()->create([
            'owner_type' => 'platform',
            'amount' => 30_000,
            'bank_name' => 'Maybank',
            'bank_account_no' => '123',
            'bank_account_holder' => 'KRS',
            'status' => WithdrawalStatus::Pending,
            'requested_by' => $requester->id,
        ]);

        $resultFileApprove = tempnam(sys_get_temp_dir(), 'withdrawal_race_approve_');
        $resultFileReject = tempnam(sys_get_temp_dir(), 'withdrawal_race_reject_');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'kerox',
            'DB_USERNAME' => 'kerox',
            'DB_PASSWORD' => 'kerox',
        ];

        $approveProcess = Process::path(base_path())->env($env)->start([
            PHP_BINARY, 'artisan', 'app:withdrawal-test-approve', (string) $withdrawal->id, (string) $approver->id, $resultFileApprove,
        ]);
        $rejectProcess = Process::path(base_path())->env($env)->start([
            PHP_BINARY, 'artisan', 'app:withdrawal-test-reject', (string) $withdrawal->id, (string) $rejecter->id, $resultFileReject,
        ]);

        $approveProcess->wait();
        $rejectProcess->wait();

        $resultApprove = file_get_contents($resultFileApprove);
        $resultReject = file_get_contents($resultFileReject);

        unlink($resultFileApprove);
        unlink($resultFileReject);

        $outcomes = [$resultApprove, $resultReject];
        $successes = array_filter($outcomes, fn ($r) => $r === 'success');

        $this->assertCount(1, $successes, 'Expected exactly one of approve/reject to succeed, got: '.json_encode($outcomes));

        $fresh = Withdrawal::query()->findOrFail($withdrawal->id);
        $ledgerEntryCount = LedgerEntry::query()
            ->where('reference_type', 'withdrawal')
            ->where('reference_id', $withdrawal->id)
            ->count();

        if ($resultApprove === 'success') {
            $this->assertSame(WithdrawalStatus::Approved, $fresh->status);
            $this->assertSame(1, $ledgerEntryCount);
            $this->assertSame(20_000, $ledger->balance('platform', null));
        } else {
            $this->assertSame(WithdrawalStatus::Rejected, $fresh->status);
            $this->assertSame(0, $ledgerEntryCount);
            $this->assertSame(50_000, $ledger->balance('platform', null));
        }
    }
}
