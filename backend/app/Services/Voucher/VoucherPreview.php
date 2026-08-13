<?php

namespace App\Services\Voucher;

/**
 * Read-only result of VoucherService::preview() — the Apply-button
 * check (ADR-024 decision #1's UI-side note: Apply previews, it never
 * locks). No side effect produced this; `voucherId` is what the caller
 * threads through to the real checkout submission, which is the only
 * place VoucherService::redeem() ever actually runs.
 */
final readonly class VoucherPreview
{
    public function __construct(
        public int $voucherId,
        public int $discountSen,
        public int $remainingAfterSen,
    ) {
    }
}
