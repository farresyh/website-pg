<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-072 decision 5 / PR-G planning addendum decision 1: add a portal
 * login for an existing `Reseller` (wallet) account. Mirrors
 * `StoreAffiliateUserRequest` exactly — the row is created with a null
 * password, the new user gets the same set-password invite
 * (`AffiliateInviteService`, generalized for `owner_type`) an `Affiliate`
 * staff user gets.
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
            'email' => ['required', 'email', 'max:255', Rule::unique('affiliate_users', 'email')],
        ];
    }
}
