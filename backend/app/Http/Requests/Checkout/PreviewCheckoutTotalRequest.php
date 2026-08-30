<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Structural validation for CheckoutController::previewTotal() — the
 * read-only Package Price/Transaction Fee/Voucher Discount/Total
 * breakdown the storefront fetches before payment (bug fix,
 * 2026-08-30, see CheckoutService::previewTotal()'s own doc comment).
 * Deliberately a much smaller field set than CreateCheckoutRequest:
 * no player_id/idempotency_key/channel_properties — this never creates
 * an Order. `customer_email` is only required alongside `voucher_code`,
 * matching VoucherPreviewController's own requirement (voucher
 * ownership is matched by email/phone) — a plain package/channel
 * preview (no voucher yet) needs neither.
 */
class PreviewCheckoutTotalRequest extends FormRequest
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
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'channel_code' => [
                'required',
                'string',
                'max:64',
                Rule::exists('payment_methods', 'channel_code')->where('is_active', true),
            ],
            'voucher_code' => ['nullable', 'string', 'max:32'],
            'customer_email' => ['nullable', 'required_with:voucher_code', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
