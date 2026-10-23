<?php

namespace App\Http\Requests\Admin\Joki;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-126 decisions 1, 4, 7: joki's panel settings. Prices are whole
 * ringgit (`integer` refuses sen); the payout share may carry two decimals.
 * Cross-field rules (Grading Express not below Grading, the Mythic star
 * cap above the highest band) are checked in the controller.
 */
class UpdateJokiSettingsRequest extends FormRequest
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
            'is_enabled' => ['sometimes', 'boolean'],
            'payout_share_pct' => ['sometimes', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'min_stars' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'grading_price_rm' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'grading_express_price_rm' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'grading_lands_at_stars' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'max_mythic_stars' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'roles' => ['sometimes', 'array', 'max:20'],
            'roles.*' => ['required', 'string', 'max:50', 'distinct'],
            'express_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'terms_html' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'progress_message_interval_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
        ];
    }
}
