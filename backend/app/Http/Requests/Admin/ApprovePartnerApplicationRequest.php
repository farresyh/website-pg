<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-133 decision 11: approval always picks a tier (the service checks it is active). */
class ApprovePartnerApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reseller_tier_id' => ['required', 'integer']];
    }
}
