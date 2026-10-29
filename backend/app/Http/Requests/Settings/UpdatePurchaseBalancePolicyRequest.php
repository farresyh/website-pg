<?php

namespace App\Http\Requests\Settings;

use App\Services\Reseller\ResellerWalletTopupService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-131 decision 4: the per-top-up maximum and the default balance cap, in sen. The cap
 * is never below the maximum, or a single top-up of the allowed size could never fit.
 */
class UpdatePurchaseBalancePolicyRequest extends FormRequest
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
            'topup_max_sen' => ['required', 'integer', 'min:'.ResellerWalletTopupService::MIN_AMOUNT_SEN, 'max:100000000'],
            'balance_cap_default_sen' => ['required', 'integer', 'gte:topup_max_sen', 'max:10000000000'],
        ];
    }
}
