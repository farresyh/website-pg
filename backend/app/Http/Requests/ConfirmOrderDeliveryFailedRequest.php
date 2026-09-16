<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-026 addendum (2026-09-16) — `note` is required, unlike
 * MarkOrderDeliveredRequest's optional one: this is an admin's own
 * "I've confirmed this genuinely failed" claim, exactly the class of
 * consequential judgment call this codebase already requires written
 * justification for elsewhere (Q1's own reasoning) — never a bare
 * click with no record of why.
 */
class ConfirmOrderDeliveryFailedRequest extends FormRequest
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
            'note' => ['required', 'string', 'max:255'],
        ];
    }
}
