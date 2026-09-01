<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GAME-7 (founder revision, 2026-07-25): admin sets a Package's own
 * markup % — `standard_selling_price` is recomputed and stored from this
 * (PackageMarkupService), never typed directly. Every package can
 * carry a different markup, matching the legacy reference system's
 * own per-row Markup % + Update pattern.
 */
class UpdatePackageMarkupRequest extends FormRequest
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
