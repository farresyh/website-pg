<?php

namespace App\Services\Accounting;

use Illuminate\Support\Carbon;

/**
 * ADR-110 PR-B — the result of parsing one CHIP settlement `.xlsx`:
 * the "Summary" sheet's own totals (verbatim, CHIP's own numbers) plus
 * every transaction row from every other sheet (one sheet per
 * acquirer — FPX today, DuitNow QR/`fpx_b2b1` whenever activated, no
 * parser change needed since sheets are iterated by name).
 */
final class ParsedSettlementFile
{
    /**
     * @param  ParsedSettlementTransaction[]  $transactions
     */
    public function __construct(
        public readonly Carbon $dateFrom,
        public readonly Carbon $dateTo,
        public readonly int $fileGrossSen,
        public readonly int $fileFeeSen,
        public readonly int $fileNetSen,
        public readonly array $transactions,
    ) {}
}
