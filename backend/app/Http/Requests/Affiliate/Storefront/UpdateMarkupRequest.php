<?php

namespace App\Http\Requests\Affiliate\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-060 PR-6 — the retail markup an affiliate adds on top of their
 * wholesale cost. Floor 0 (they may sell at wholesale). The real
 * ceiling is the admin-set `max_markup_pct`, checked in the controller
 * (DB-dependent — backend/AGENTS.md); `max:999.99` here only fits the
 * `decimal(5,2)` column.
 */
class UpdateMarkupRequest extends FormRequest
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
