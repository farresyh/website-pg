<?php

namespace App\Services\Accounting;

use App\Models\PaymentSettlement;

/**
 * ADR-110 PR-B — what an admin sees after uploading one settlement
 * `.xlsx`: the created (or reused, if this exact window already has a
 * row) `PaymentSettlement`, plus per-transaction counts so a re-upload
 * or overlapping range is visibly safe rather than silently ignored.
 */
final class SettlementIngestResult
{
    public function __construct(
        public readonly PaymentSettlement $settlement,
        public readonly int $newlyMatchedCount,
        public readonly int $newlyUnmatchedCount,
        public readonly int $alreadyReconciledSkippedCount,
        /** @var string[] transaction_ids CHIP reports settled but no local record was found for */
        public readonly array $unmatchedTransactionIds,
        /** @var array<int, array{reference: string, amount_sen: int}> our own CHIP-paid records in-window whose transaction_id never appeared in this (or any prior) file */
        public readonly array $paidButNotSettled,
    ) {}
}
