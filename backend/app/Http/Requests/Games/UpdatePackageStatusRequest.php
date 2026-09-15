<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PackageController::updateStatus — a dedicated lightweight endpoint
 * for flipping a Package's is_active flag, extracted from an inline
 * $request->validate() 2026-08-21 to match this project's own
 * FormRequest-per-mutating-route convention (AGENTS.md).
 *
 * ADR-094 decision 13 (2026-09-15 Phase 4): `acknowledge_cascade`
 * — when deactivating a Package that's an active component of one or
 * more active combos, the controller rejects unless this is true. The
 * admin panel computes the warning client-side from data it already
 * has (the combo package list's own `active_combo_dependents`, same
 * pattern AffiliateFormModal's membership-disable warning already
 * uses) and only ever sends `true` after a confirm step; this is the
 * server-side backstop for a request that bypasses that UI.
 */
class UpdatePackageStatusRequest extends FormRequest
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
            'acknowledge_cascade' => ['sometimes', 'boolean'],
        ];
    }
}
