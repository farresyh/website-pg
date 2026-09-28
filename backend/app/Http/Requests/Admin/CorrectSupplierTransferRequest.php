<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-083 2026-09-28 addendum — "Edit Details": a metadata-only
 * correction against an already-recorded transfer (RM sent/fee/
 * channel/reference/receipt — never the FX amounts, which stay
 * Adjust/Void only). Every field is `sometimes` — a submit only needs
 * to include what actually changed.
 */
class CorrectSupplierTransferRequest extends FormRequest
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
            'source_channel' => ['sometimes', 'in:wise,airwallex,bank'],
            'amount_myr_sent' => ['sometimes', 'integer', 'min:1'],
            'fee_myr' => ['sometimes', 'integer', 'min:0'],
            'reference_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'receipt' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,pdf'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
