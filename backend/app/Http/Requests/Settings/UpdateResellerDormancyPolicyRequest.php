<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-142 decision 25: the reseller dormancy switch, the idle period, the
 * warning lead (less than the period, so the warning comes first) and the
 * WhatsApp number a deactivated reseller is told to message.
 */
class UpdateResellerDormancyPolicyRequest extends FormRequest
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
            'reseller_dormancy_enabled' => ['required', 'boolean'],
            'reseller_dormancy_days' => ['required', 'integer', 'min:2', 'max:365'],
            'reseller_dormancy_warning_days' => ['required', 'integer', 'min:1', 'lt:reseller_dormancy_days'],
            'reseller_support_whatsapp' => ['nullable', 'string', 'regex:/^\+?[0-9 -]{8,20}$/'],
        ];
    }
}
