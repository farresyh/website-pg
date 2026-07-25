<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * MUI-5 — adds one country->Game routing row under the validator
 * profile resolved from the route (`{validator}` — see
 * PlayerRegionMappingController::store()). `country_code` is
 * normalized to uppercase before validation; uniqueness is scoped per
 * validator profile (player_region_mappings' own unique index) since
 * a different validator profile's region split must not collide with
 * this one's rows.
 */
class StorePlayerRegionMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('country_code')) {
            $this->merge(['country_code' => strtoupper((string) $this->input('country_code'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country_code' => [
                'required',
                'string',
                'size:2',
                'alpha',
                Rule::unique('player_region_mappings')
                    ->where(fn ($query) => $query->where('player_validator_profile_id', $this->route('validator')?->id)),
            ],
            'country_name' => ['required', 'string', 'max:255'],
            'game_id' => ['required', 'integer', 'exists:games,id'],
        ];
    }
}
