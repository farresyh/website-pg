<?php

namespace App\Http\Requests\Campaign;

use App\Models\PromoCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-118 V3/V11/V17: the admin chooses the code (3–20 letters and digits,
 * stored uppercase, unique forever, so never a `VC-` voucher code).
 */
class StorePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => PromoCode::normalise($this->input('code'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{3,20}$/', Rule::unique('promo_codes', 'code')],
            ...PromoCodeTermsRules::rules(required: true),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use 3 to 20 letters and digits only.',
            'code.unique' => 'This code has been used before. A code is never reused, because old KOL videos keep circulating.',
        ];
    }
}
