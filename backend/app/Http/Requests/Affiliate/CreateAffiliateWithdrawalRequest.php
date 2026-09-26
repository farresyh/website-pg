<?php

namespace App\Http\Requests\Affiliate;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-059 59c. Shape only — balance sufficiency against the affiliate's
 * earnings ledger is checked in the controller (needs
 * AffiliateEarningsService). The `amount` ceiling mirrors the admin
 * CreateWithdrawalRequest — a fat-finger guard, not the real limit.
 *
 * ADR-059 addendum, 2026-09-26: no `bank_name`/`bank_account_no`/
 * `bank_account_holder` fields at all — a withdrawal request always
 * pays out to the affiliate's saved profile bank details, never a
 * per-request override. To pay out to a different account, a staff
 * member must update Profile first, a separate, independently
 * auditable action (`Affiliate\WithdrawalController::store()`).
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
        ];
    }
}
