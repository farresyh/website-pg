<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PackageController::updateStatus — a dedicated lightweight endpoint
 * for flipping a Package's is_active flag, extracted from an inline
 * $request->validate() 2026-08-21 to match this project's own
 * FormRequest-per-mutating-route convention (AGENTS.md).
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
        ];
    }
}
