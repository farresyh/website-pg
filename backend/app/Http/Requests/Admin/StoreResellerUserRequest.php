<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-058 58b: add another staff login to an existing reseller. The row
 * is created with a null password; the new user gets the same
 * set-password invite (ResellerInviteService) as the first user created
 * in RES-2. One reseller can have several staff users from day one
 * (the schema has supported it since 58a).
 */
class StoreResellerUserRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', Rule::unique('reseller_users', 'email')],
        ];
    }
}
