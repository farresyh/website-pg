<?php

namespace App\Http\Requests\Campaign;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-118 V17: the string and campaign never change; the percentage only until the first redemption (checked in the controller). */
class UpdatePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return PromoCodeTermsRules::rules(required: false);
    }
}
