<?php

namespace App\Http\Requests\Reseller;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-059 59c. Shape only — balance sufficiency against the reseller's
 * earnings ledger is checked in the controller (needs
 * ResellerEarningsService). Bank fields are optional: when omitted the
 * controller falls back to the reseller's saved profile bank details.
 * The `amount` ceiling mirrors the admin CreateWithdrawalRequest — a
 * fat-finger guard, not the real limit.
 */
class CreateResellerWithdrawalRequest extends FormRequest
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
