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
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'player_id' => ['required', 'string', 'max:255'],
            'server_id' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', 'string', Rule::in(array_keys(config('checkout.payment_methods')))],
            'channel_code' => ['required', 'string', 'max:64'],
            'channel_properties' => ['nullable', 'array'],
        ];
    }
}
