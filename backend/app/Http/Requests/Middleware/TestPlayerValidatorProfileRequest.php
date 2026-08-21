<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PlayerValidatorProfileController::test — extracted from an inline
 * $request->validate() 2026-08-21 to match this project's own
 * FormRequest-per-mutating-route convention (AGENTS.md).
 */
class TestPlayerValidatorProfileRequest extends FormRequest
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
            'player_id' => ['required', 'string'],
            'server_id' => ['nullable', 'string'],
        ];
    }
}
