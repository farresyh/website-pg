<?php

namespace Tests\Concurrency;

use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * 2026-09-28 audit finding M-2: ResellerWalletService::completeTopup()
 * checked `$attempt->status === Paid` with no row lock, so the CHIP
 * webhook and ReconcilePendingWalletTopupsCommand's 15-minute backstop
 * could both observe `pending` and both credit the wallet — a real
 * double credit, proven here with two genuinely separate processes
 * racing to complete the SAME attempt (mirrors
 * MembershipCheckoutWebhookReconcileConcurrencyTest's shape for the
 * sibling membership-fee path).
 *
 * Requires: docker compose up -d (backend/docker-compose.yml)
 * Run with: php artisan test -c phpunit.concurrency.xml
 */
class ResellerWalletTopupCompleteConcurrencyTest extends TestCase
{
    // Not RefreshDatabase — see LedgerWithdrawConcurrencyTest for why.
    use DatabaseMigrations;

    public function test_two_simultaneous_completions_credit_the_wallet_only_once(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Race Test Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        $attempt = WalletTopupAttempt::query()->create([
            'reseller_id' => $reseller->id,
            'reference' => 'WTT-RACE-TEST-0001',
            'amount_sen' => 5_000_00,
            'total_charged_sen' => 5_000_00,
            'channel_code' => 'fpx',
            'status' => WalletTopupAttemptStatus::Pending->value,
            'expires_at' => now()->addMinutes(30),
        ]);

        $resultFileA = tempnam(sys_get_temp_dir(), 'wallet_topup_complete_test_');
        $resultFileB = tempnam(sys_get_temp_dir(), 'wallet_topup_complete_test_');

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
            PHP_BINARY, 'artisan', 'app:reseller-wallet-topup-test-complete',
            $attempt->reference, $resultFile,
        ];

        $processA = Process::path(base_path())->env($env)->start($command($resultFileA));
        $processB = Process::path(base_path())->env($env)->start($command($resultFileB));

        $processA->wait();
        $processB->wait();

        $resultA = file_get_contents($resultFileA);
        $resultB = file_get_contents($resultFileB);

        unlink($resultFileA);
        unlink($resultFileB);

        $this->assertStringNotContainsString('error:', $resultA, "Process A errored: {$resultA}");
        $this->assertStringNotContainsString('error:', $resultB, "Process B errored: {$resultB}");

        $balance = app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id);
        $this->assertSame(5_000_00, $balance, "Wallet was credited more than once — balance is {$balance} sen.");

        $creditCount = \App\Models\LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('owner_id', $reseller->id)
            ->where('type', 'wallet_topup')
            ->count();
        $this->assertSame(1, $creditCount, 'Expected exactly one wallet_topup ledger entry.');

        $attempt->refresh();
        $this->assertSame(WalletTopupAttemptStatus::Paid, $attempt->status);
    }
}
