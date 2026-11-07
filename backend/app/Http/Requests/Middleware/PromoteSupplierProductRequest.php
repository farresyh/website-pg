<?php

namespace App\Http\Requests\Middleware;

use App\Services\Catalog\GameFulfilmentRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * MID-5/SUPP-3: promotes one raw `supplier_products` row into a real,
 * customer-facing `Package` under the Game its category was linked to.
 * That decision happens once per category group
 * (LinkSupplierProductCategoryRequest), not per item, and ADR-138
 * decision 17 reads it from the product: the request carries no game.
 *
 * No `standard_selling_price`/markup field here (founder revision, same
 * session) — every promoted Package gets `config('packages.default_markup_percent')`
 * applied automatically; admin adjusts the real markup per-package
 * afterward in /admin/games, not at promote time.
 */
class PromoteSupplierProductRequest extends FormRequest
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
            // ADR-034 decision 3/4: optional, admin-curated, never
            // auto-matched by name — the storefront best-price dedup
            // equivalence key. Left null promotes exactly as before.
            'denomination' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** ADR-138 decision 17 and ADR-125 decision 5: linked first, and never to a login game. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $game = $this->route('supplier_product')->groupLink()?->game;

            if ($game === null) {
                $validator->errors()->add('supplier_product', 'This item\'s group is not linked to a game yet.');
            } elseif ($error = GameFulfilmentRule::supplierPackage($game)) {
                $validator->errors()->add('supplier_product', $error);
            }
        });
    }
}
