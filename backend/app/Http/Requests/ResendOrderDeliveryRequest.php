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
        ];
    }
}
