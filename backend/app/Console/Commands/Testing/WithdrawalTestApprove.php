<?php

namespace App\Console\Commands\Testing;

use App\Http\Controllers\Admin\WithdrawalController;
use App\Models\AdminUser;
use App\Models\Withdrawal;
use App\Services\Ledger\LedgerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Test-only helper, same pattern as LedgerTestWithdraw/OrderFulfillmentTestFulfill:
 * invoked as a genuinely separate OS process so two approve() calls for the
 * same Withdrawal race for real (separate PHP process + separate DB
 * connection each), proving the row lock added to
 * WithdrawalController::approve() actually serializes them.
 */
#[Signature('app:withdrawal-test-approve {withdrawalId} {adminId} {resultFile}')]
#[Description('Test-only: attempt to approve a single withdrawal and write the outcome to a result file.')]
class WithdrawalTestApprove extends Command
{
    public function handle(): int
    {
        $withdrawal = Withdrawal::query()->findOrFail((int) $this->argument('withdrawalId'));
        $admin = AdminUser::query()->findOrFail((int) $this->argument('adminId'));
        $resultFile = $this->argument('resultFile');

        $request = Request::create('/');
        $request->setUserResolver(fn () => $admin);

        $controller = new WithdrawalController(new LedgerService());

        try {
            $controller->approve($request, $withdrawal);
            file_put_contents($resultFile, 'success');
        } catch (ValidationException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
