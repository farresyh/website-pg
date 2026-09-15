<?php

namespace App\Http\Requests\Games;

use App\Models\Package;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-094 decisions 1-4, 18-20 (2026-09-15 stress-test addendum),
 * 2026-09-16 addendum (cap raised 3->5): creates a combo Package — the
 * one deliberate exception to PackageController's own doc comment that
 * every Package traces back to a real supplier item
 * (`SupplierProductController::promote()`). A combo has no
 * `supplier_id`/`supplier_package_ref` of its own (decision 3); it's
 * assembled from `components` instead, each an already-promoted
 * Package on the same Game.
 *
 * Cross-field rules live in `withValidator()` (need the resolved
 * `Package` rows and the route's `Game`, not just the raw input):
 * no nested combo (decision 4), same game, same supplier only
 * (decision 4), every component must itself carry a `denomination`
 * (decision 2 — combo identity is the *sum* of components' own
 * denomination, meaningless for a catalog_code-only bundle/pass), and
 * total legs (`sum(quantity)`, not row count — decision 20's
 * 2026-09-15 wording fix) capped at 5 (raised from 3, 2026-09-16
 * addendum — real usage, decision 20's own revisit bar). Raising this
 * also requires `config/horizon.php`'s `supervisor-orders-combo`
 * timeout to stay sized to the new max (60s/leg).
 */
class StoreComboPackageRequest extends FormRequest
{
    public const MAX_TOTAL_LEGS = 5;

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
            'components' => ['required', 'array', 'min:1'],
            'components.*.package_id' => ['required', 'integer', 'distinct', 'exists:packages,id'],
            'components.*.quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_TOTAL_LEGS],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $components = $this->input('components', []);
            if (! is_array($components) || $components === []) {
                return;
            }

            $game = $this->route('game');
            $packageIds = collect($components)->pluck('package_id')->filter()->all();
            $packages = Package::query()->whereIn('id', $packageIds)->get()->keyBy('id');

            $totalLegs = 0;
            $supplierIds = [];

            foreach ($components as $index => $component) {
                $packageId = $component['package_id'] ?? null;
                $package = $packageId !== null ? $packages->get($packageId) : null;

                if ($package === null) {
                    // The `exists:` rule already reports this row.
                    continue;
                }

                if ($package->is_combo) {
                    $validator->errors()->add(
                        "components.{$index}.package_id",
                        'A combo cannot contain another combo.',
                    );
                }

                if ($game !== null && $package->game_id !== $game->id) {
                    $validator->errors()->add(
                        "components.{$index}.package_id",
                        'Component must belong to the same game as the combo.',
                    );
                }

                if ($package->denomination === null) {
                    $validator->errors()->add(
                        "components.{$index}.package_id",
                        'Component must have a denomination set — a catalog_code-only package (bundle/pass) has no numeric amount to sum.',
                    );
                }

                $totalLegs += (int) ($component['quantity'] ?? 0);
                $supplierIds[$package->supplier_id ?? 0] = true;
            }

            if ($totalLegs > self::MAX_TOTAL_LEGS) {
                $validator->errors()->add(
                    'components',
                    'A combo cannot resolve to more than '.self::MAX_TOTAL_LEGS.' total legs (sum of quantities).',
                );
            }

            if (count($supplierIds) > 1) {
                $validator->errors()->add(
                    'components',
                    'All components must share the same supplier — cross-supplier combos are not supported in v1.',
                );
            }
        });
    }
}
