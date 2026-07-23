<?php

namespace App\Console\Commands\Testing;

use App\Services\Ledger\InsufficientBalanceException;
use App\Services\Ledger\LedgerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper: invoked as a genuinely separate OS process by
 * tests/Concurrency/LedgerWithdrawConcurrencyTest.php so two withdrawals
 * race for real (separate PHP process + separate DB connection each),
 * rather than being simulated within a single-threaded test process.
 */
#[Signature('app:ledger-test-withdraw {ownerType} {ownerId} {amount} {resultFile}')]
#[Description('Test-only: attempt a single ledger withdrawal and write the outcome to a result file.')]
class LedgerTestWithdraw extends Command
{
    public function handle(LedgerService $ledger): int
    {
        $ownerType = $this->argument('ownerType');
        $ownerId = (int) $this->argument('ownerId');
        $amount = (int) $this->argument('amount');
        $resultFile = $this->argument('resultFile');

        try {
            $ledger->withdraw($ownerType, $ownerId, $amount, 'test', null);
            file_put_contents($resultFile, 'success');
        } catch (InsufficientBalanceException $e) {
            file_put_contents($resultFile, 'failed:' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
