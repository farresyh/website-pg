<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Structural validation only — cross-field/DB-dependent business rules
 * (package belongs to game, the game's validation_rules requirement,
 * active-status gates) live in CheckoutController, matching this
 * codebase's existing split between FormRequest (structural) and
 * controller (business rules needing DB context) — see
 * WithdrawalController::approve()/VoucherController::store() for the
 * same pattern. Guest checkout (ADR-011): no auth, no AdminUser
 * context — anyone can submit this.
 *
 * `payment_method` was dropped 2026-07-25: now that every channel has
 * its own stored `payment_methods` row (fee rate + gateway), the old
 * category-based key was redundant with `channel_code` — validating
 * `channel_code` alone (must exist + be active) is both necessary and
 * sufficient. CheckoutController derives the Order's reporting
 * `payment_method` label from the matched row's `category`.
 *
 * `customer_name` added 2026-07-25: Xendit's Payment Request API
 * requires a `customer.individual_detail.given_names` for at least the
 * FPX channel (discovered live via the Payment Methods "Test" action —
 * see PaymentCustomer's own doc comment) — guest checkout never
 * collected a name before this.
 *
 * `idempotency_key` added 2026-07-29 (ADR-019's remaining gap, closed):
 * client-generated once per checkout attempt (the storefront's Review
 * Modal — see OrderForm.tsx), unchanged across a resubmit of that same
 * attempt. CheckoutController uses it to detect a retried
 * `POST /api/checkout` (double-click, timeout retry) and avoid creating
 * a second real Order/payment for it. Not DB-validated here
 * (`exists`/`unique`) — CheckoutController's own lookup is what gives
 * it meaning; this FormRequest only enforces shape.
 *
 * `customer_phone` made required 2026-07-30: the first real production
 * order (docs/adr.md's ADR-006 addendum) failed delivery with Gamevion's
 * `{"error_code":"404","error_message":"Phone number required"}` — their
 * order endpoint needs it even though our own checkout previously marked
 * it optional. Required at the FormRequest layer so a missing phone is
 * caught before payment, not after a paid order fails delivery.
 */
class CreateCheckoutRequest extends FormRequest
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
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_name' => ['required', 'string', 'max:50'], // Xendit individual_detail.given_names caps at 50
            'customer_phone' => ['required', 'string', 'max:32'],
            'player_id' => ['required', 'string', 'max:255'],
            'server_id' => ['nullable', 'string', 'max:255'],
            'channel_code' => [
                'required',
                'string',
                'max:64',
                Rule::exists('payment_methods', 'channel_code')->where('is_active', true),
            ],
            'channel_properties' => ['nullable', 'array'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
