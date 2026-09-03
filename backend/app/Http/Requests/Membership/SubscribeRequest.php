<?php

namespace App\Http\Requests\Membership;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ADR-068 decision 5 — the self-serve subscribe payload. The plan is
 * the only monetary input trusted from the client (ORD-9 — the fee is
 * read from `membership_plans` server-side); everything else is either
 * an identifier or a passthrough. The session token (brand + email) is
 * resolved in the controller, not here.
 */
class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Match CreateCheckoutRequest — an absent client key still yields
        // a stable per-request one so the attempt row's unique index holds.
        if (! $this->filled('idempotency_key')) {
            $this->merge(['idempotency_key' => (string) Str::uuid()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'membership_plan_id' => ['required', 'integer', 'exists:membership_plans,id'],
            'payment_method' => [
                'required',
                'string',
                Rule::exists('payment_methods', 'channel_code')->where('is_active', true),
            ],
            'idempotency_key' => ['required', 'string', 'max:64'],
            'channel_properties' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['payment_method' => 'payment method'];
    }
}
