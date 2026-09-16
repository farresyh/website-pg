<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-017: `package_id` is the (possibly different, same-game-only)
 * package the admin wants to resend against — same-game and
 * is_active enforcement happens in OrderResendService, not here,
 * since it needs the Order being acted on to know which game "same"
 * means. `note` mirrors the legacy Resend Delivery modal's own
 * optional "Note (Optional)" field (decision #4).
 */
class ResendOrderDeliveryRequest extends FormRequest
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
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'note' => ['nullable', 'string', 'max:255'],
            // ADR-102 decision 3 — required only when the order is
            // scoped-unsafe to resend (Order::resendUnsafeToOverride());
            // that check needs the Order itself, so the actual
            // required-ness is enforced in OrderController::guardResendUnsafeOverride(),
            // not here. Just a plain optional string at the FormRequest layer.
            'override_reason' => ['nullable', 'string', 'max:255'],
            // ADR-102 decision 10 — an optional correction to a
            // customer-typo'd Player ID/Server ID, extending ADR-017's
            // package-swap pattern with a second, independent
            // correction. Only structural (format) validation here,
            // same 'string'/'max:255' shape as every other player_id/
            // server_id field this codebase validates (e.g.
            // CreateCheckoutRequest) — OrderResendService applies the
            // override onto the order and re-runs the same player-ID
            // validation check the package-swap path already enforces.
            'player_id' => ['nullable', 'string', 'max:255'],
            'server_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
