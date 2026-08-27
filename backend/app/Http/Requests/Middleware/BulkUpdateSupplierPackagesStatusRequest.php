<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-046 decisions 9/10: backs both "Deactivate All"/"Deactivate by
 * Game" and their paired "Reactivate" counterpart on a Supplier's
 * detail view — `game_id` narrows an otherwise supplier-wide action to
 * one game (the "supplier only has a problem with one game" scenario
 * that motivated this feature). `reason` is only meaningful for a
 * deactivate call (SupplierController stores it only when
 * `is_active === false`); a client-side reactivate call may still send
 * it and it's simply ignored, cheaper than two near-identical requests.
 */
class BulkUpdateSupplierPackagesStatusRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
            'game_id' => ['nullable', 'integer', 'exists:games,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
