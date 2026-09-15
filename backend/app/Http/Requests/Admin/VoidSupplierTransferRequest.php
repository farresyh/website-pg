<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-083 2026-09-15 addendum — the "Void Entirely" correction mode: the
 * money behind this transfer never reached the supplier at all (a
 * genuinely failed send). `reason` is the only input — the reversal
 * amount is always the transfer's own full net credit, computed by
 * `SupplierFundingService::voidTransfer()`, never admin-typed (a void
 * is definitionally "all of it", not a number to get wrong).
 */
class VoidSupplierTransferRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
