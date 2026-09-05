<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-073 decision 3(b): admin manual-credit of a reseller's wallet.
 * `amount_sen` is the only money value here and it's admin-typed by
 * design (this IS the manual/no-CHIP path) — not a client-trusted value
 * from anywhere else. `receipt` is optional, for audit.
 */
class StoreResellerWalletCreditRequest extends FormRequest
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
            'amount_sen' => ['required', 'integer', 'min:1', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }
}
