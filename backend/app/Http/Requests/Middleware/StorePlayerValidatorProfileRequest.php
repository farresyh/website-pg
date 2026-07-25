<?php

namespace App\Http\Requests\Middleware;

use App\Services\PlayerValidation\PlayerValidatorRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * MUI-5 — `key` is constrained to PlayerValidatorRegistry::AVAILABLE_KEYS
 * (a select in the UI, not free text) so a profile can never be
 * created pointing at a key with no real backend implementation —
 * the founder's own correction, 2026-07-25.
 */
class StorePlayerValidatorProfileRequest extends FormRequest
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
            'key' => [
                'required',
                'string',
                Rule::in(array_keys(PlayerValidatorRegistry::AVAILABLE_KEYS)),
                Rule::unique('player_validator_profiles', 'key'),
            ],
        ];
    }
}
