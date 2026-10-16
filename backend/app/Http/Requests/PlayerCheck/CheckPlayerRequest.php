<?php

namespace App\Http\Requests\PlayerCheck;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Structure-only validation for the public Game ID Checker (ADR-123).
 * The ID format is checked per family in PublicPlayerCheckService, after
 * the Turnstile token.
 */
class CheckPlayerRequest extends FormRequest
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
            'turnstile_token' => ['required', 'string', 'max:2048'],
        ];
    }
}
