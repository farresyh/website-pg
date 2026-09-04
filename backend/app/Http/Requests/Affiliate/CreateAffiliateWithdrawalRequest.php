<?php

namespace App\Http\Requests\Affiliate;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-059 59c. Shape only — balance sufficiency against the affiliate's
 * earnings ledger is checked in the controller (needs
 * AffiliateEarningsService). Bank fields are optional: when omitted the
 * controller falls back to the affiliate's saved profile bank details.
 * The `amount` ceiling mirrors the admin CreateWithdrawalRequest — a
 * fat-finger guard, not the real limit.
 */
class CreateAffiliateWithdrawalRequest extends FormRequest
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
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:100'],
            'bank_account_holder' => ['nullable', 'string', 'max:255'],
        ];
    }
}
