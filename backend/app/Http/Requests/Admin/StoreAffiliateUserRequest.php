<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-058 58b: add another staff login to an existing affiliate. The row
 * is created with a null password; the new user gets the same
 * set-password invite (AffiliateInviteService) as the first user created
 * in RES-2. One affiliate can have several staff users from day one
 * (the schema has supported it since 58a).
 */
class StoreAffiliateUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', Rule::unique('affiliate_users', 'email')],
        ];
    }
}
