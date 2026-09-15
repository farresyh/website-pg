<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-094 decision 5, second half: an admin sets/clears a combo
 * Package's optional pricing override — a custom markup percentage or
 * a custom fixed selling price, mutually exclusive, same
 * `prohibits`-each-other idiom as `denomination`/`catalog_code`
 * (UpdatePackageDenominationRequest/UpdatePackageCatalogCodeRequest).
 * Only meaningful on a combo Package — rejected on an ordinary one,
 * which already has its own dedicated markup endpoint
 * (UpdatePackageMarkupRequest).
 */
class UpdateComboPackageOverrideRequest extends FormRequest
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
            'combo_override_markup_percent' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'combo_override_price' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $package = $this->route('package');

            if ($package !== null && ! $package->is_combo) {
                $validator->errors()->add('combo_override_markup_percent', 'Only a combo package can have a pricing override.');

                return;
            }

            if ($this->input('combo_override_markup_percent') !== null && $this->input('combo_override_price') !== null) {
                $validator->errors()->add('combo_override_price', 'Set either a custom markup or a custom price, never both.');
            }
        });
    }
}
