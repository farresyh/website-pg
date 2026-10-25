<?php

namespace App\Http\Requests\Joki;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-126 decision 12: the `/joki` page's live price for a selection. */
class JokiQuoteRequest extends FormRequest
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
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            ...JokiSelectionRules::rules(),
        ];
    }
}
