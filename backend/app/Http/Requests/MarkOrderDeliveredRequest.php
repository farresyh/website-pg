<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-026 decision 4a: `supplier_ref` is required, not optional — the
 * whole point of this action is closing the exact data gap (a missing
 * Gamevion invoice number) that made the order ambiguous in the first
 * place, so an admin confirming "delivered" without it would just
 * repeat the same problem this feature exists to fix.
 *
 * ADR-127 PR D: `supplier_price` is what the supplier charged, read off
 * its dashboard with the invoice, in the supplier's own currency (IDR for
 * Digiflazz). It becomes the real cost and the drawdown.
 */
class MarkOrderDeliveredRequest extends FormRequest
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
            'supplier_ref' => ['required', 'string', 'max:255'],
            'supplier_price' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
