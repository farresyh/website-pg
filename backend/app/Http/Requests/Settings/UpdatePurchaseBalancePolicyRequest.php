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
            // ADR-076 2026-11-12 addendum: `.topupbaki`'s method; null = the first offered.
            'bot_topup_payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            // ADR-075 2026-11-14 addendum, decision 2: a money command older than this is not run.
            'bot_command_max_age_minutes' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }
}
