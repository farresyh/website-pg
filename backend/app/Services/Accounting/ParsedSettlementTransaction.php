<?php

namespace App\Services\Accounting;

use Illuminate\Support\Carbon;

/**
 * ADR-110 PR-B — one transaction row from a settlement `.xlsx`'s
 * per-acquirer sheet, normalized to sen. `referenceCode` is CHIP's own
 * `Reference` column — the value this platform passed as
 * `PaymentRequest::referenceId` (`order_number`/`subscription_number`/
 * `wallet_topup_attempts.reference`).
 */
final class ParsedSettlementTransaction
{
    public function __construct(
        public readonly string $transactionId,
        public readonly ?string $referenceCode,
        public readonly int $amountSen,
        public readonly int $feeSen,
        public readonly int $netAmountSen,
        public readonly string $acquirer,
        public readonly Carbon $settledOn,
    ) {}
}
