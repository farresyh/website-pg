<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-083 2026-09-15 addendum — the "Adjust" correction mode: a
 * partial, signed correction against an already-recorded transfer
 * (e.g. a supplier's own deposit fee was missed at entry time). Never
 * touches the original `TOPUP` row — writes a new `MANUAL_ADJUSTMENT`
 * ledger entry instead (`SupplierFundingService::recordManualAdjustment()`).
 */
class AdjustSupplierTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Signed — a positive correction (something was under-recorded)
            // is just as real a case as a negative one (this fix's own
            // motivating example). 0 is meaningless — reject it rather
            // than write a silent no-op ledger row.
            'amount' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
