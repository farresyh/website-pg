<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-124 decision 10. H is at least 1 hour: a CHIP purchase is `due`
 * +30 minutes (`due_strict`), and a Paid can land after a Failed inside
 * that window (2026-10-18 addendum, decision 2).
 */
class UpdateCustomerLoginPolicyRequest extends FormRequest
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
            'credential_close_grace_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'credential_unpaid_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'credential_max_days' => ['required', 'integer', 'min:1', 'max:90'],
            'credential_reveal_limit_per_hour' => ['required', 'integer', 'min:1', 'max:200'],
            'credential_consent_text' => ['required', 'string', 'min:20', 'max:1000'],
        ];
    }
}
