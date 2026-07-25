<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * MUI-5 — `key` is immutable after creation: it's the identity that
 * ties this profile to a real backend implementation
 * (PlayerValidatorRegistry). Only the admin-facing display name is
 * ever edited here.
 */
class UpdatePlayerValidatorProfileRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
