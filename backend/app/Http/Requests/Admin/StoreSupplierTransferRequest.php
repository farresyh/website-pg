<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-083 decision 2: recording a capital transfer of company funds into
 * a supplier's prepaid account. `amount_myr_sent`/`fee_myr` are
 * admin-typed by design — this is a bookkeeping record of a real transfer
 * that already happened, not a client-trusted checkout value.
 */
class StoreSupplierTransferRequest extends FormRequest
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
            'source_channel' => ['required', 'string', 'in:wise,airwallex,bank'],
            'amount_myr_sent' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'fee_myr' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'currency' => ['required', 'string', 'size:3', 'uppercase'],
            'amount_foreign_received' => ['required', 'numeric', 'min:0.0001'],
            'reference_no' => ['nullable', 'string', 'max:191'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
