<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-110 PR-B addendum (automatic reconciliation) — `status` is now
 * fully computed at ingest (see `SettlementReconciliationService`) and
 * never admin-typed, so it is deliberately not accepted here anymore.
 * `actual_bank_amount_sen` is a purely optional founder annotation
 * (no cadence, never derived from the uploaded file or drives
 * `status`); `variance_note` is a free-text note the founder can attach
 * whenever, not an auto-generated message tied to a bank-figure
 * mismatch.
 */
class UpdatePaymentSettlementRequest extends FormRequest
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
            'actual_bank_amount_sen' => ['nullable', 'integer', 'min:0'],
            'variance_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
