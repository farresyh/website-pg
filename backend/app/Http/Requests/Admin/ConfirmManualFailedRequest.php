<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-125 decision 8: why the supplier couldn't top up this order. */
class ConfirmManualFailedRequest extends FormRequest
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
            // ADR-126 2026-10-23 addendum, decision 18: the supplier the admin saw; finalize refuses if the order has since moved.
            'supplier_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
