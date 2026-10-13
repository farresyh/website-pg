<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GAME-7 (founder revision, 2026-07-25): admin sets a Package's own
 * markup % — `standard_selling_price` is recomputed and stored from this
 * (PackageMarkupService), never typed directly. Every package can
 * carry a different markup, matching the legacy reference system's
 * own per-row Markup % + Update pattern.
 *
 * Not for a combo: its price comes from its components or its own
 * override (`ComboPricingService::recompute()`), and a markup typed on
 * it would be silently undone by the next recompute.
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->route('package')?->is_combo) {
                $validator->errors()->add('markup_percent', 'A combo is priced from its components. Use Edit Combo to set a custom markup or price.');
            }
        });
    }
}
