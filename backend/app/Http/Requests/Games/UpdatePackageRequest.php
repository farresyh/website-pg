<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GAME-7: admin renames a Package's customer-facing name. Markup
 * (`markup_percent`/`standard_selling_price`) and active/inactive status
 * are separate, dedicated endpoints (PackageController::updateMarkup/
 * updateStatus) — inline, per-row actions, matching the legacy
 * reference system's own layout (legacy-reference-notes.md), not
 * bundled into one general-purpose "edit everything" form.
 */
class UpdatePackageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
