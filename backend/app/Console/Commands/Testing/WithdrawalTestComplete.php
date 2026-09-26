<?php

namespace App\Console\Commands\Testing;

use App\Http\Controllers\Admin\WithdrawalController;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Test-only helper, same pattern as WithdrawalTestApprove: invoked as a
 * genuinely separate OS process so two complete() calls for the same
 * Withdrawal race for real, proving the row lock added to
 * WithdrawalController::complete() actually serializes them.
 */
#[Signature('app:withdrawal-test-complete {withdrawalId} {resultFile}')]
#[Description('Test-only: attempt to mark a single withdrawal completed and write the outcome to a result file.')]
class WithdrawalTestComplete extends Command
{
    public function handle(): int
    {
        $withdrawal = Withdrawal::query()->findOrFail((int) $this->argument('withdrawalId'));
        $resultFile = $this->argument('resultFile');

        $request = Request::create('/');

        $controller = new WithdrawalController(new LedgerService());

        try {
            $controller->complete($request, $withdrawal);
            file_put_contents($resultFile, 'success');
        } catch (ValidationException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
