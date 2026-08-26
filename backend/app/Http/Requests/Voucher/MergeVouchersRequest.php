<?php

namespace App\Http\Requests\Voucher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-036 — structural validation only. Active-status and same-
 * customer checks are business rules needing DB context, so they live
 * in VoucherService::merge() (mirrors this codebase's existing
 * FormRequest/controller split, e.g. CreateVoucherRequest/
 * VoucherController::store()).
 */
class MergeVouchersRequest extends FormRequest
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
            'voucher_ids' => ['required', 'array', 'min:2'],
            'voucher_ids.*' => ['required', 'integer', 'distinct', 'exists:vouchers,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
