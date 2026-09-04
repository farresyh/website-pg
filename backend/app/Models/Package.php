<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-075's catalog-code addendum (2026-09-04): `denomination`
 * (ADR-034) and `catalog_code` are two independent equivalence keys
 * — a package carries at most one of them (enforced at the
 * FormRequest layer, see UpdatePackageDenominationRequest/
 * UpdatePackageCatalogCodeRequest), never both.
 */
class Package extends Model
{
    protected $fillable = [
        'game_id',
        'name',
        'denomination',
        'catalog_code',
        'cost_price',
        'standard_selling_price',
        'markup_percent',
        'is_active',
        'deactivated_reason',
        'deactivated_at',
        'supplier_id',
        'supplier_package_ref',
        'sort_order',
    ];

    protected $casts = [
        'denomination' => 'integer',
        'cost_price' => 'integer',
        'standard_selling_price' => 'integer',
        'markup_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * ADR-075's catalog-code addendum (2026-09-04), decision 7: the
     * single-code resolution the Reseller API/Bot channels
     * (`ResellerCatalogService::resolveByCode()`) use — pass exactly
     * one of `$denomination`/`$catalogCode`, never both. Cheapest
     * active package wins ties broken by lowest `id`, ordered on
     * `cost_price` directly (not the storefront's customer-facing
     * selling price) since that ordering is equivalent for any single
     * fixed markup percentage and needs no `PricingService`/
     * `Affiliate` dependency inside the model layer.
     *
     * Bulk storefront listing (`CatalogController::dedupByDenomination()`)
     * is a separate, pre-existing code path (ADR-034) — left as-is for
     * the `denomination` branch to avoid any behavior change to a
     * live, money-adjacent screen; it's extended with the same
     * cheapest-wins rule for the new `catalog_code` branch there.
     */
    public static function cheapestActiveFor(int $gameId, ?int $denomination, ?string $catalogCode): ?self
    {
        $query = static::query()->where('game_id', $gameId)->where('is_active', true);

        if ($denomination !== null) {
            $query->where('denomination', $denomination);
        } elseif ($catalogCode !== null) {
            $query->where('catalog_code', $catalogCode);
        } else {
            return null;
        }

        return $query->orderBy('cost_price')->orderBy('id')->first();
    }
}
