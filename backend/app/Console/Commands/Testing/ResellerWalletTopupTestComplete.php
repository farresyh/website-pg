<?php

namespace App\Console\Commands\Testing;

use App\Models\WalletTopupAttempt;
use App\Services\Reseller\ResellerWalletService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * ResellerWalletTopupCompleteConcurrencyTest so the CHIP webhook path
 * and the ReconcilePendingWalletTopupsCommand backstop racing to
 * complete the SAME WalletTopupAttempt is a real race (separate PHP
 * process + separate DB connection each), same shape as
 * MembershipTestCompleteAttempt / ResellerWalletTopupTestInitiate.
 */
#[Signature('app:reseller-wallet-topup-test-complete {reference} {resultFile}')]
#[Description('Test-only: complete a paid wallet top-up attempt and write the outcome to a result file.')]
class ResellerWalletTopupTestComplete extends Command
{
    public function handle(ResellerWalletService $wallets): int
    {
        $attempt = WalletTopupAttempt::query()
            ->where('reference', $this->argument('reference'))
            ->firstOrFail();
        $resultFile = $this->argument('resultFile');

        try {
            $wallets->completeTopup($attempt);
            file_put_contents($resultFile, 'done');
        } catch (\Throwable $e) {
            file_put_contents($resultFile, 'error: '.get_class($e).': '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
