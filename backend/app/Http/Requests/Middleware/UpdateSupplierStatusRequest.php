<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-046 decision 5: activating a Supplier is a real, immediate,
 * money-adjacent action (it's picked up by the next scheduled price
 * sync — ADR-031 decision 4) — the frontend surfaces a confirm warning
 * before submitting this, but the check itself is a UI concern; this
 * request just validates the shape.
 */
class UpdateSupplierStatusRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
        ];
    }
}
