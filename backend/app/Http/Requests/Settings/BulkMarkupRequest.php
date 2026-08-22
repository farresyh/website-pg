<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-028 decision 4 — SET-2's bulk markup action, mechanics decided at build time. */
class BulkMarkupRequest extends FormRequest
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
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
        ];
    }
}
