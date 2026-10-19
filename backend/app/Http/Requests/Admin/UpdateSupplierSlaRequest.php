<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-125 decisions 15/60: a manual supplier's reply SLA, in minutes. */
class UpdateSupplierSlaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reply_sla_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ];
    }
}
