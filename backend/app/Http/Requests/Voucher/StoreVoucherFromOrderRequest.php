<?php

namespace App\Http\Requests\Voucher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * VoucherController::storeFromOrder — extracted from an inline
 * $request->validate() 2026-08-21 to match this project's own
 * FormRequest-per-mutating-route convention (AGENTS.md).
 *
 * ADR-094 decision 9 (2026-09-15 Phase 4): `amount` is new — every
 * ordinary Failed-order voucher still ignores it entirely (the
 * controller computes `final_amount - transaction_fee` itself, ORD-9's
 * "never trust a client-submitted money value"); it's read only for a
 * combo order's genuine partial-delivery needs_review case, where
 * there's no single correct auto-computed figure (the player already
 * has some of the goods) — an admin-adjustable amount, still capped
 * here at the order's own `final_amount` so it can never exceed what
 * was actually paid.
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
            'amount' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $order = $this->route('order');
            $amount = $this->input('amount');

            if ($order !== null && $amount !== null && $amount > $order->final_amount) {
                $validator->errors()->add('amount', 'Amount cannot exceed what the customer actually paid.');
            }
        });
    }
}
