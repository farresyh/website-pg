<?php

namespace App\Http\Requests\Affiliate;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-059 59c: an affiliate edits only its own contact + payout bank
 * details. `business_name` / `email` (the login) are admin-controlled
 * and not accepted here.
 */
class UpdateAffiliateProfileRequest extends FormRequest
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
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            // Item 34 (found 2026-09-26, fixed 2026-09-28): `nullable`
            // let an empty string "" through as a "valid" bank_name/etc,
            // which Affiliate\WithdrawalController::store()'s own
            // `$affiliate->bank_name === null` guard doesn't catch — an
            // affiliate could save blank bank details and still pass the
            // "must have bank details" check. `filled` allows the field
            // to be omitted entirely (a partial update touching other
            // fields), but rejects it outright if present and empty.
            'bank_name' => ['filled', 'string', 'max:255'],
            'bank_account_no' => ['filled', 'string', 'max:100'],
            'bank_account_holder' => ['filled', 'string', 'max:255'],
        ];
    }
}
