<?php

namespace App\Http\Requests\Affiliate;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-058 (58a): accepting the emailed invite (or a later reset) — the
 * affiliate sets their own password. `token` is the `affiliate_users`
 * broker token from AffiliateInviteService's link. Unauthenticated by
 * design.
 */
class AffiliateSetPasswordRequest extends FormRequest
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
