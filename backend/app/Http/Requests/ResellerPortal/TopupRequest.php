<?php

namespace App\Http\Requests\ResellerPortal;

use App\Services\Reseller\ResellerWalletTopupService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PR-G planning addendum decision 3: free-form amount, minimum RM10
 * (1000 sen), no platform-imposed maximum — CHIP's/the bank's own
 * per-transaction ceiling is the only ceiling, handled by the existing
 * generic payment-failure path.
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
            'channel_properties' => ['sometimes', 'array'],
        ];
    }
}
