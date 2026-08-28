<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ADR-054 (DEV-1/2, MUI-11) — validates a Developer API Tester call.
 * `payload` fields populate a typed adapter DTO (SupplierOrderRequest/
 * SupplierStatusCheckRequest) or validatePlayer()'s two scalar args —
 * never a raw body forwarded to the supplier as-is (decision 2).
 * `reference_number` is deliberately not an accepted field: createOrder
 * always gets a server-generated `DEVTEST-` reference (decision 5).
 */
class TestSupplierAdapterRequest extends FormRequest
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
        $method = $this->input('method');

        return [
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'method' => ['required', 'string', 'in:checkBalance,listProducts,checkStatus,validatePlayer,createOrder'],
            'dry_run' => ['required', 'boolean'],
            'payload' => ['sometimes', 'array'],
            'payload.supplier_ref' => [Rule::requiredIf($method === 'checkStatus'), 'nullable', 'string', 'max:255'],
            'payload.product_ref' => [Rule::requiredIf($method === 'createOrder'), 'nullable', 'string', 'max:255'],
            'payload.player_id' => [Rule::requiredIf(in_array($method, ['validatePlayer', 'createOrder'], true)), 'nullable', 'string', 'max:255'],
            'payload.server_id' => ['nullable', 'string', 'max:255'],
            'payload.customer_phone' => ['nullable', 'string', 'max:255'],
            'payload.callback_url' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
