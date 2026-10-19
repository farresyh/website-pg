<?php

namespace App\Http\Requests\Games;

use App\Models\Game;
use App\Models\Supplier;
use App\Services\Catalog\GameFulfilmentRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-125 decision 4: a manual package on a login game, priced from the
 * agreed supplier cost (`cost_price` in sen, > 0, ADR-025) and a markup,
 * exactly like a promoted package. Before the bot, the cost is the
 * highest expected cost (Q4).
 */
class StoreManualPackageRequest extends FormRequest
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
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'name' => ['required', 'string', 'max:255'],
            'cost_price' => ['required', 'integer', 'min:1'],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Game $game */
            $game = $this->route('game');
            $supplier = Supplier::query()->find($this->input('supplier_id'));

            if ($supplier !== null && ($error = GameFulfilmentRule::manualPackage($game, $supplier))) {
                $validator->errors()->add('supplier_id', $error);
            }
        });
    }
}
