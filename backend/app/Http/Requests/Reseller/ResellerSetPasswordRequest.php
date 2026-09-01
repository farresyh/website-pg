<?php

namespace App\Http\Requests\Reseller;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-058 (58a): accepting the emailed invite (or a later reset) — the
 * reseller sets their own password. `token` is the `reseller_users`
 * broker token from ResellerInviteService's link. Unauthenticated by
 * design.
 */
class ResellerSetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            // 'min:8' matches this codebase's existing password rule
            // (CreateAdminUserRequest).
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
