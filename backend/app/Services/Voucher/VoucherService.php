<?php

namespace App\Services\Voucher;

use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

final class VoucherService
{
    /**
     * The voucher row itself is the lock anchor (unlike Ledger, a voucher
     * always exists before it can be redeemed, so there's no "zero rows to
     * lock" gap) — `lockForUpdate()` on the row directly is sufficient
     * (VCH-5).
     */
    public function redeem(string $code, int $amount): Voucher
    {
        return DB::transaction(function () use ($code, $amount) {
            $voucher = Voucher::query()->where('code', $code)->lockForUpdate()->firstOrFail();

            if ($voucher->status !== 'active') {
                throw new InvalidVoucherException(
                    "Voucher {$code} is not active (status: {$voucher->status})",
                );
            }

            if ($voucher->remaining < $amount) {
                throw new InvalidVoucherException(
                    "Voucher {$code} has insufficient remaining balance: has {$voucher->remaining}, requested {$amount}",
                );
            }

            $voucher->remaining -= $amount;

            if ($voucher->remaining === 0) {
                $voucher->status = 'exhausted';
            }

            $voucher->save();

            return $voucher;
        });
    }
}
