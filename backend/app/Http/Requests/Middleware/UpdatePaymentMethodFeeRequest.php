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
            'percentage_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'flat_fee_sen' => ['required', 'integer', 'min:0'],
        ];
    }
}
