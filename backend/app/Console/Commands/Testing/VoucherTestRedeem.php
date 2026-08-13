<?php

namespace App\Console\Commands\Testing;

use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper, same pattern as LedgerTestWithdraw: invoked as a
 * genuinely separate OS process so two redemptions race for real.
 * ADR-024: two distinct order IDs, same voucher — the real "two
 * browser tabs, same customer" scenario the row lock must serialize.
 */
#[Signature('app:voucher-test-redeem {voucherId} {orderId} {amount} {email} {resultFile}')]
#[Description('Test-only: attempt a single voucher redemption and write the outcome to a result file.')]
class VoucherTestRedeem extends Command
{
    public function handle(VoucherService $vouchers): int
    {
        $voucherId = (int) $this->argument('voucherId');
        $orderId = (int) $this->argument('orderId');
        $amount = (int) $this->argument('amount');
        $email = $this->argument('email');
        $resultFile = $this->argument('resultFile');

        try {
            $vouchers->redeem($voucherId, $orderId, $amount, $email, null);
            file_put_contents($resultFile, 'success');
        } catch (InvalidVoucherException $e) {
            file_put_contents($resultFile, 'failed:' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
