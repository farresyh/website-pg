<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-133 decision 7: stage-2 answers, saved any number of times before submit. */
class SaveStageTwoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner_address' => ['nullable', 'string', 'max:500'],
            'business_address' => ['nullable', 'string', 'max:500'],
            'tax_number' => ['nullable', 'string', 'max:50'],
        ];
    }
}
