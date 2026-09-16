<?php

namespace App\Console\Commands\Testing;

use App\Http\Controllers\Admin\OrderController;
use App\Models\AdminUser;
use App\Models\Order;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Test-only helper, same pattern as WithdrawalTestApprove/
 * OrderFulfillmentTestFulfill: invoked as a genuinely separate OS
 * process so two refundToWallet() calls for the same Order race for
 * real (separate PHP process + separate DB connection each), proving
 * the row lock added there (ADR-102 decision 1/2 — found mid-build:
 * `ledger_entries` has no unique index backstop the way `vouchers`
 * does, so the pre-existing "did this already happen" check alone
 * could not have prevented a genuine double wallet-refund) actually
 * serializes them.
 */
#[Signature('app:order-test-refund-to-wallet {orderId} {adminId} {resultFile}')]
#[Description('Test-only: attempt to refund a single order to its reseller wallet and write the outcome to a result file.')]
class OrderTestRefundToWallet extends Command
{
    public function handle(): int
    {
        $order = Order::query()->findOrFail((int) $this->argument('orderId'));
        $admin = AdminUser::query()->findOrFail((int) $this->argument('adminId'));
        $resultFile = $this->argument('resultFile');

        $request = Request::create('/');
        $request->setUserResolver(fn () => $admin);

        $controller = new OrderController();

        try {
            app()->call([$controller, 'refundToWallet'], ['request' => $request, 'order' => $order]);
            file_put_contents($resultFile, 'success');
        } catch (ValidationException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
