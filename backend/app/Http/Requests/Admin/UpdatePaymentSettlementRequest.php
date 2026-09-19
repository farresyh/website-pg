<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-083 decision 7 — the founder's own manually-entered real bank
 * figure, checked against a real bank statement, never derived from
 * the uploaded file or this platform's own records.
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
            'actual_bank_amount_sen' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['matched', 'variance'])],
            'variance_note' => ['required_if:status,variance', 'nullable', 'string', 'max:2000'],
        ];
    }
}
