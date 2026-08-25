<?php

namespace App\Console\Commands\Testing;

use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-only helper, same pattern as VoucherTestRedeem/LedgerTestWithdraw:
 * invoked as a genuinely separate OS process so two merges race for
 * real. ADR-036: two overlapping voucher selections sharing one
 * voucher — the row lock in VoucherService::merge() must serialize
 * them, not let both consume the shared voucher.
 */
#[Signature('app:voucher-test-merge {voucherIds} {mergedBy} {resultFile}')]
#[Description('Test-only: attempt a single voucher merge and write the outcome to a result file.')]
class VoucherTestMerge extends Command
{
    public function handle(VoucherService $vouchers): int
    {
        $voucherIds = array_map('intval', explode(',', $this->argument('voucherIds')));
        $mergedBy = (int) $this->argument('mergedBy');
        $resultFile = $this->argument('resultFile');

        try {
            $voucher = $vouchers->merge($voucherIds, 'concurrency test', $mergedBy, null);
            file_put_contents($resultFile, 'success:'.$voucher->id);
        } catch (InvalidVoucherException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
