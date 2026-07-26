<?php

namespace App\Http\Requests\PlayerValidation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structure-only validation for the public "Validate Player ID" call
 * (PlayerValidationController) — mirrors CreateCheckoutRequest's split
 * (types here, cross-field/DB-dependent rules in the controller).
 * `server_id` is optional here regardless of what the game's own
 * `validation_rules.extra_field` requires at checkout — this endpoint
 * is a pre-payment lookup, not the checkout submission itself.
 */
class ValidatePlayerRequest extends FormRequest
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
            'player_id' => ['required', 'string', 'max:255'],
            'server_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
