<?php

namespace App\Services\Fulfillment;

use App\Models\Voucher;

/** What OrderSettlementService::settle() gave back: a new voucher, a wallet refund, or (restore-only) neither. */
final readonly class SettlementResult
{
    public function __construct(
        public ?Voucher $voucher,
        public int $walletRefundSen,
    ) {}
}
