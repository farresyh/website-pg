<?php

namespace App\Http\Requests\Middleware;

use Illuminate\Foundation\Http\FormRequest;

/**
 * MID-5/SUPP-3: promotes one raw `supplier_products` row into a real,
 * customer-facing `Package` under a Game. `game_id` is always required
 * here — the "which Game does this whole category belong to" decision
 * happens once per category group (LinkSupplierProductCategoryRequest),
 * not per item (founder feedback, docs/prd.md §14: re-deciding the
 * Game on every one of 316 items doesn't scale).
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
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'name' => ['required', 'string', 'max:255'],
            // ADR-034 decision 3/4: optional, admin-curated, never
            // auto-matched by name — the storefront best-price dedup
            // equivalence key. Left null promotes exactly as before.
            'denomination' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
