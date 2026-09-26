<?php

namespace App\Console\Commands\Testing;

use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Requests\Withdrawal\RejectWithdrawalRequest;
use App\Models\AdminUser;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Test-only helper, same pattern as WithdrawalTestApprove: invoked as a
 * genuinely separate OS process so two reject() (or a reject() racing an
 * approve()) calls for the same Withdrawal race for real, proving the row
 * lock added to WithdrawalController::reject() actually serializes them.
 */
#[Signature('app:withdrawal-test-reject {withdrawalId} {adminId} {resultFile}')]
#[Description('Test-only: attempt to reject a single withdrawal and write the outcome to a result file.')]
class WithdrawalTestReject extends Command
{
    public function handle(): int
    {
        $withdrawal = Withdrawal::query()->findOrFail((int) $this->argument('withdrawalId'));
        $admin = AdminUser::query()->findOrFail((int) $this->argument('adminId'));
        $resultFile = $this->argument('resultFile');

        $request = RejectWithdrawalRequest::create('/', 'POST', ['admin_note' => null]);
        $request->setContainer(app());
        $request->setUserResolver(fn () => $admin);
        $request->validateResolved();

        $controller = new WithdrawalController(new LedgerService());

        try {
            $controller->reject($request, $withdrawal);
            file_put_contents($resultFile, 'success');
        } catch (ValidationException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
