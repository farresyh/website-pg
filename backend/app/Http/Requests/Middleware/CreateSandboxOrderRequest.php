<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-018 decision #3: admin picks a real Game/Package from our own
 * catalog — package_id's game match and active-status are cross-field
 * checks, left to SandboxOrderController same as CheckoutController's
 * own split. Customer/player fields are all optional here and default
 * to fixed sandbox placeholders in the controller (decision #3).
 */
class CreateSandboxOrderRequest extends FormRequest
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
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:50'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'player_id' => ['nullable', 'string', 'max:255'],
            'server_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
