<?php

namespace App\Http\Requests\Admin;

use App\Services\Reseller\ResellerWalletTopupService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-131 decision 4: one reseller's balance-cap override, in sen. `null` means "use the
 * platform default". `present`, so a missing field is a 422 and never a silent reset.
 */
class UpdateResellerBalanceCapRequest extends FormRequest
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
            'balance_cap_sen' => ['present', 'nullable', 'integer', 'min:'.ResellerWalletTopupService::MIN_AMOUNT_SEN, 'max:10000000000'],
        ];
    }
}
