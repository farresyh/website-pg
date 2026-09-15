<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-083 decision 2: recording a capital transfer of company funds into
 * a supplier's prepaid account. `amount_myr_sent`/`fee_myr` are
 * admin-typed by design — this is a bookkeeping record of a real transfer
 * that already happened, not a client-trusted checkout value.
 *
 * ADR-083 2026-09-15 addendum: `supplier_fee` — the supplier's own
 * deposit-side cut (e.g. Digiflazz's flat IDR fee), distinct from
 * `fee_myr` (Wise/Airwallex's fee). Optional — not every channel/
 * supplier has one.
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
            'supplier_fee' => ['nullable', 'numeric', 'min:0'],
            'reference_no' => ['nullable', 'string', 'max:191'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $gross = (float) $this->input('amount_foreign_received', 0);
            $fee = (float) $this->input('supplier_fee', 0);

            if ($fee > $gross) {
                $validator->errors()->add(
                    'supplier_fee',
                    'Supplier fee cannot exceed the gross amount received — the net credit would go negative.',
                );
            }
        });
    }
}
