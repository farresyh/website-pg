<?php

namespace App\Http\Requests\Voucher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-004 2026-11-12 addendum, decisions 3 and 5. Structural only: the
 * amount is never sent (it is the voucher's balance), and the voucher and
 * transfer-date rules live in VoucherService::refundInCash().
 */
class RefundVoucherInCashRequest extends FormRequest
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
            'transferred_on' => ['required', 'date_format:Y-m-d'],
            'bank_name' => ['required', 'string', 'min:2', 'max:100'],
            'bank_account_no' => ['required', 'string', 'regex:/^[0-9 -]{5,30}$/'],
            'bank_account_holder' => ['required', 'string', 'min:2', 'max:150'],
            'bank_reference' => ['required', 'string', 'min:3', 'max:100'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
