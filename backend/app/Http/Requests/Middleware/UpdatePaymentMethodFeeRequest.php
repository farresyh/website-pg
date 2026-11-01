<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PaymentMethodController::updateFee — extracted from an inline
 * $request->validate() 2026-08-21 to match this project's own
 * FormRequest-per-mutating-route convention (AGENTS.md).
 */
class UpdatePaymentMethodFeeRequest extends FormRequest
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
            // lt:100: the fee is grossed up over (100 − rate) (ADR-024 2026-11-01 addendum).
            'percentage_rate' => ['required', 'numeric', 'min:0', 'lt:100'],
            'flat_fee_sen' => ['required', 'integer', 'min:0'],
        ];
    }
}
