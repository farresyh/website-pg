<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

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
        'is_combo',
        'combo_override_markup_percent',
        'combo_override_price',
    ];

    protected $casts = [
        'denomination' => 'integer',
        'cost_price' => 'integer',
        'standard_selling_price' => 'integer',
        'markup_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
        'sort_order' => 'integer',
        'is_combo' => 'boolean',
        'combo_override_markup_percent' => 'decimal:2',
        'combo_override_price' => 'integer',
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
     * ADR-094 decision 1: this combo's own components, in fulfillment
     * (leg) order. Empty for a non-combo Package.
     */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'package_components',
            'combo_package_id',
            'component_package_id',
        )->withPivot(['quantity', 'sort_order'])->withTimestamps()->orderByPivot('sort_order');
    }

    /**
     * ADR-094 decision 13/21 (2026-09-15 addendum): the reverse of
     * components() — every combo that currently references this
     * Package as a component. Used to warn-and-acknowledge before a
     * deactivation/supplier-change cascades onto a dependent combo.
     */
    public function partOfCombos(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'package_components',
            'component_package_id',
            'combo_package_id',
        )->withPivot(['quantity', 'sort_order'])->withTimestamps();
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

    /**
     * Every active package for one Game, deduped down to one winner per
     * `denomination`/`catalog_code` equivalence group — the bulk sibling
     * of `cheapestActiveFor()`, same cheapest-wins rule (lowest
     * `cost_price`, `id` tie-break), reused by
     * `ResellerCatalogService::listAvailable()` (ADR-074/075's shared
     * "price list" source for the API and Bot channels).
     *
     * Deliberately separate from the storefront's own bulk listing
     * (`CatalogController::dedupByDenomination()`, ADR-034/ADR-075's
     * catalog-code addendum) rather than a shared call site: that
     * method's cheapest-pick is selling-price-based (needs
     * `PricingService`/`Affiliate::primary()`, a controller-level
     * dependency this model layer doesn't take on) — provably the same
     * winner as this cost_price-based rule for any single flat markup,
     * but keeping the storefront's own proven code path untouched
     * avoids any behavior change to that live screen.
     *
     * ADR-076 decision 1: the final display *order* of the returned
     * Collection, however, is not a cheapest-pick concern and has no
     * reason to differ from the storefront's — mirrors
     * `dedupByDenomination()`'s own final `sortBy` exactly (ascending
     * `denomination`, packages with neither key last by `name`) so the
     * Reseller API/Bot channels list packages in the same order a
     * customer sees them, instead of DB-fetch order (the bug this
     * decision fixes — `groupBy()` on a Collection preserves
     * first-appearance order, not numeric order).
     *
     * @return Collection<int, self>
     */
    public static function cheapestActivePerGame(int $gameId): Collection
    {
        return self::dedupeActivePerGame(
            static::query()->where('game_id', $gameId)->where('is_active', true)->get()
        );
    }

    /**
     * The post-query half of `cheapestActivePerGame()` — the partition /
     * cheapest-per-group / display-sort rules, over an already-fetched
     * collection of that one game's active packages. Split out so a bulk
     * caller (`ResellerCatalogService::listAvailable()`, ADR-077 PR-5)
     * can fetch every game's packages in one `whereIn` query and dedupe
     * each group in PHP, instead of one query per game.
     *
     * @param  Collection<int, self>  $packages  one game's active packages
     * @return Collection<int, self>
     */
    public static function dedupeActivePerGame(Collection $packages): Collection
    {
        [$withDenomination, $rest] = $packages->partition(fn (self $p) => $p->denomination !== null);
        [$withCatalogCode, $neither] = $rest->partition(fn (self $p) => $p->catalog_code !== null);

        $cheapestPerDenomination = $withDenomination->groupBy('denomination')->map(fn ($group) => self::cheapestInGroup($group));
        $cheapestPerCatalogCode = $withCatalogCode->groupBy('catalog_code')->map(fn ($group) => self::cheapestInGroup($group));

        return $neither
            ->concat($cheapestPerDenomination->values())
            ->concat($cheapestPerCatalogCode->values())
            ->sortBy([
                fn (self $a, self $b) => ($a->denomination === null ? 1 : 0) <=> ($b->denomination === null ? 1 : 0),
                fn (self $a, self $b) => $a->denomination <=> $b->denomination,
                fn (self $a, self $b) => strcmp($a->name, $b->name),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, self>  $group
     */
    private static function cheapestInGroup(Collection $group): self
    {
        return $group
            ->sortBy([
                fn (self $a, self $b) => $a->cost_price <=> $b->cost_price,
                fn (self $a, self $b) => $a->id <=> $b->id,
            ])
            ->first();
    }
}
