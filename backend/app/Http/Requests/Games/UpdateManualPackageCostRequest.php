<?php

namespace App\Http\Requests\Games;

use App\Models\Package;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-125 decision 34: the agreed cost of a manual package, edited by an
 * admin. An API package's cost comes only from Price Sync.
 */
class UpdateManualPackageCostRequest extends FormRequest
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
            'cost_price' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Package $package */
            $package = $this->route('package');

            if ($package->is_combo || $package->supplier === null || $package->supplier->isApiBacked()) {
                $validator->errors()->add('cost_price', 'Only a manual package\'s cost is edited by hand; an API package\'s cost comes from Price Sync.');
            }
        });
    }
}
