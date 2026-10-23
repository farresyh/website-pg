<?php

namespace App\Http\Requests\Admin\Joki;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-126 decisions 3-4: edit the rank model's existing tiers — name,
 * shape, whole-ringgit rates (Express not below Reguler) and icon. The
 * controller re-checks the whole ladder and every route against it.
 */
class UpdateJokiTiersRequest extends FormRequest
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
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*.id' => ['required', 'integer', 'distinct', 'exists:joki_tiers,id'],
            'tiers.*.name' => ['sometimes', 'string', 'max:50'],
            'tiers.*.divisions' => ['sometimes', 'integer', 'min:1', 'max:8'],
            'tiers.*.stars_per_division' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'tiers.*.mythic_from_stars' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'tiers.*.rate_rm' => ['required', 'integer', 'min:1', 'max:10000'],
            'tiers.*.express_rate_rm' => ['required', 'integer', 'gte:tiers.*.rate_rm', 'max:10000'],
            'tiers.*.icon_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tiers.*.rate_rm.integer' => 'Whole ringgit only, no sen.',
            'tiers.*.express_rate_rm.integer' => 'Whole ringgit only, no sen.',
        ];
    }
}
