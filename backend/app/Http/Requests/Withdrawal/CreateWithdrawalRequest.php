<?php

namespace App\Http\Requests\Withdrawal;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WTH-1/§7.4. Amount sufficiency against the ledger is checked in the
 * controller (needs LedgerService), not here — this only validates shape.
 */
class CreateWithdrawalRequest extends FormRequest
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
            // Ceiling added 2026-08-14 (fresh audit) — no sanity ceiling
            // existed the way markup_percent already got one; sufficiency
            // against the real ledger balance is still checked in the
            // controller, this only guards a fat-fingered extra digit.
            // RM 1,000,000 comfortably exceeds any real single withdrawal.
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'bank_name' => ['required', 'string', 'max:255'],
            'bank_account_no' => ['required', 'string', 'max:100'],
            'bank_account_holder' => ['required', 'string', 'max:255'],
        ];
    }
}
