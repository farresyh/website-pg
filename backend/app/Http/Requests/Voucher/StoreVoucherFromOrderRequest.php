<?php

namespace App\Http\Requests\Voucher;

use Illuminate\Foundation\Http\FormRequest;

/**
 * VoucherController::storeFromOrder — extracted from an inline
 * $request->validate() 2026-08-21 to match this project's own
 * FormRequest-per-mutating-route convention (AGENTS.md).
 *
 * ADR-094 decision 33 (2026-10-04): the amount is a formula
 * (OrderSettlementService), never admin-typed — `amount` is refused
 * outright so a stale client can't believe it set one. Goodwill above
 * the formula is a standalone voucher (Path A).
 */
class StoreVoucherFromOrderRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:1000'],
            'amount' => ['prohibited'],
        ];
    }
}
