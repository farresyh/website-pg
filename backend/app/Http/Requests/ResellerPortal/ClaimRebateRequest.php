<?php

namespace App\Http\Requests\ResellerPortal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-139 decisions 11, 16: a claim carries only the month and the step
 * (0 = the month's adjustment), plus the terms tick. Every amount is
 * computed by RebateClaimService.
 */
class ClaimRebateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2026', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'step' => ['required', 'integer', 'min:0', 'max:20'],
            'accept_terms' => ['sometimes', 'boolean'],
        ];
    }
}
