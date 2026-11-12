<?php

namespace App\Console\Commands\Testing;

use App\Models\Voucher;
use App\Services\Voucher\InvalidVoucherException;
use App\Services\Voucher\VoucherService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;

/**
 * Test-only helper, same pattern as VoucherTestMerge: a separate OS process
 * so a cash refund races a second refund or a redeem for real (ADR-004
 * 2026-11-12 addendum). The voucher lock in refundInCash() must serialize them.
 */
#[Signature('app:voucher-test-refund-in-cash {voucherId} {adminId} {resultFile}')]
#[Description('Test-only: attempt a single voucher cash refund and write the outcome to a result file.')]
class VoucherTestRefundInCash extends Command
{
    public function handle(VoucherService $vouchers): int
    {
        $resultFile = $this->argument('resultFile');
        $proof = tempnam(sys_get_temp_dir(), 'refund_proof_');
        file_put_contents($proof, '%PDF-1.4 concurrency test');

        try {
            $refund = $vouchers->refundInCash(
                Voucher::query()->findOrFail((int) $this->argument('voucherId')),
                (int) $this->argument('adminId'),
                now('Asia/Kuala_Lumpur')->toDateString(),
                ['bank_name' => 'Maybank', 'bank_account_no' => '112233445566', 'bank_account_holder' => 'Race Test', 'bank_reference' => 'RACE-'.getmypid()],
                new UploadedFile($proof, 'proof.pdf', 'application/pdf', null, true),
                'concurrency test',
            );
            file_put_contents($resultFile, 'success:'.$refund->amount_sen);
        } catch (InvalidVoucherException|UniqueConstraintViolationException $e) {
            file_put_contents($resultFile, 'failed:'.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
