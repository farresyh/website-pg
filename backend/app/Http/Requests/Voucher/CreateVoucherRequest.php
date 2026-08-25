<?php

namespace App\Http\Requests\Voucher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * VCH-3 Path A — standalone voucher creation from the Vouchers page.
 * Threshold/role check (VCH-6) happens in the controller, not here.
 */
class CreateVoucherRequest extends FormRequest
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
            'customer_email' => ['required', 'email'],
            // Ceiling added 2026-08-14 (fresh audit) — a Super Admin fat-
            // fingering an extra digit had no floor/ceiling sanity check
            // the way markup_percent already got one; RM 10,000 comfortably
            // exceeds any real single-order compensation amount.
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            // ADR-035: client-generated once per CreateVoucherModal open
            // (CreateVoucherModal.tsx), unchanged across a resubmit of
            // that same attempt — mirrors CreateCheckoutRequest's own
            // idempotency_key exactly. Required: without it there is
            // nothing for VoucherController::store() to key a duplicate-
            // submission guard on. Not DB-validated here (`unique`) —
            // the controller's own lookup + the DB unique constraint on
            // vouchers.idempotency_key are what give it meaning.
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
