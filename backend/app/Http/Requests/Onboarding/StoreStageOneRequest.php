<?php

namespace App\Http\Requests\Onboarding;

use App\Models\PartnerApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-133 decision 3: the public stage-1 form. No document; one consent box. */
class StoreStageOneRequest extends FormRequest
{
    public const SALES_RANGES = ['under_1k', '1k_5k', '5k_20k', 'over_20k'];

    public const ORDER_CHANNELS = ['api', 'whatsapp', 'not_sure'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'applicant_type' => ['required', Rule::in([PartnerApplication::TYPE_COMPANY, PartnerApplication::TYPE_INDIVIDUAL])],
            'owner_name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'required_if:applicant_type,'.PartnerApplication::TYPE_COMPANY, 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'experience' => ['nullable', 'string', 'max:2000'],
            'monthly_sales_range' => ['nullable', Rule::in(self::SALES_RANGES)],
            'main_games' => ['nullable', 'string', 'max:500'],
            'order_channel' => ['nullable', Rule::in(self::ORDER_CHANNELS)],
            'heard_from' => ['nullable', 'string', 'max:255'],
            'consent' => ['accepted'],
            'turnstile_token' => ['required', 'string', 'max:2048'],
        ];
    }
}
