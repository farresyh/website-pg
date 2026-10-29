<?php

namespace App\Http\Requests\ResellerPortal;

use App\Services\Payment\Chip\FpxBankRedirect;
use App\Services\Reseller\ResellerWalletTopupService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PR-G planning addendum decision 3: free-form amount, minimum RM10
 * (1000 sen). The maximum per top-up and the balance cap are enforced
 * where the credit is written (ADR-131 decision 5,
 * `ResellerWalletService::assertWithinLimits()`), not here: the portal
 * and the WhatsApp bot both reach `ResellerWalletTopupService::initiate()`
 * and the limits are settings, so this request carries no constant for them.
 */
class TopupRequest extends FormRequest
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
            'amount_sen' => ['required', 'integer', 'min:'.ResellerWalletTopupService::MIN_AMOUNT_SEN],
            'channel_code' => [
                'required',
                'string',
                Rule::exists('payment_methods', 'channel_code')->where('is_active', true),
            ],
            // ADR-130: the bank picked on the portal.
            'fpx_bank_code' => FpxBankRedirect::rules(),
            'channel_properties' => ['sometimes', 'array'],
        ];
    }
}
