<?php

namespace App\Http\Requests\Affiliate\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/** ADR-060 PR-6 — a what-if markup for the live preview table. */
class PreviewMarkupRequest extends FormRequest
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
            'markup_pct' => ['required', 'numeric', 'min:0', 'max:999.99'],
        ];
    }
}
