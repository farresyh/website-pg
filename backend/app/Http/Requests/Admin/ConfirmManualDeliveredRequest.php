<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-125 decisions 8/36/42: the real cost in sen (defaults to the order's
 * agreed cost) and a reason, required by ManualDeliveryService whenever
 * the cost differs, and always for a Failed → Delivered correction.
 */
class ConfirmManualDeliveredRequest extends FormRequest
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
            'real_cost_sen' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
