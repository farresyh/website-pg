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
 */
#[Signature('app:voucher-test-redeem {code} {amount} {resultFile}')]
#[Description('Test-only: attempt a single voucher redemption and write the outcome to a result file.')]
class VoucherTestRedeem extends Command
{
    public function handle(VoucherService $vouchers): int
    {
        $code = $this->argument('code');
        $amount = (int) $this->argument('amount');
        $resultFile = $this->argument('resultFile');

        try {
            $vouchers->redeem($code, $amount);
            file_put_contents($resultFile, 'success');
        } catch (InvalidVoucherException $e) {
            file_put_contents($resultFile, 'failed:' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
