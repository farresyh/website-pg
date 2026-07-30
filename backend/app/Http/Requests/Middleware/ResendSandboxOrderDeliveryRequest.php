<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-018 decision #5: same package_id/note shape as the real
 * ResendOrderDeliveryRequest, plus the sandbox-only outcome picker —
 * `simulate_success` is required so a submit always has a deliberate
 * outcome; error_code/error_message are only meaningful (and only
 * ever used) when simulate_success is false, defaulted by
 * FakeSupplierAdapter itself when omitted.
 */
class ResendSandboxOrderDeliveryRequest extends FormRequest
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
            'simulate_success' => ['required', 'boolean'],
            'error_code' => ['nullable', 'string', 'max:64'],
            'error_message' => ['nullable', 'string', 'max:255'],
        ];
    }
}
