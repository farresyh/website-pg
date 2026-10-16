<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * MUI-5 — `key` is immutable after creation: it's the identity that
 * ties this profile to a real backend implementation
 * (PlayerValidatorRegistry). Only the admin-facing display name and
 * the public Game ID Checker fields (ADR-123) are edited here.
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
            // ADR-123 decision 2: the family's public Game ID Checker
            // name, URL slug and image. All optional; no slug = not listed.
            'public_name' => ['nullable', 'string', 'max:100'],
            'public_slug' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('player_validator_profiles', 'public_slug')->ignore($this->route('validator')),
            ],
            'public_image_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
