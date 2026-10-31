<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-133 2026-11-01 addendum: the "Public applications open" switch. */
class UpdatePartnerApplicationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['partner_applications_open' => ['required', 'boolean']];
    }
}
