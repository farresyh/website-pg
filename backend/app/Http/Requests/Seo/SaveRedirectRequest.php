<?php

namespace App\Http\Requests\Seo;

use App\Models\Reseller;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ADR-029 decision 3: exact-path redirect, unique per reseller (addendum 2 decision 15: no regex). */
class SaveRedirectRequest extends FormRequest
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
            'from_path' => [
                'required',
                'string',
                'max:2048',
                'starts_with:/',
                Rule::unique('redirects', 'from_path')
                    ->where('reseller_id', $this->resellerId())
                    ->ignore($this->route('redirect')),
            ],
            'to_path' => ['required', 'string', 'max:2048'],
            'status_code' => ['required', 'integer', Rule::in([301, 302])],
        ];
    }

    private function resellerId(): int
    {
        return Reseller::primary()->id;
    }
}
