<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-126 decision 3: one rung of the rank model, bottom first by
 * `sort_order`. A divisional tier (Warrior … Legend) has `divisions` ×
 * `stars_per_division` stars, divisions counted down (V … I). A Mythic
 * tier starts at `mythic_from_stars` on the Mythic star count. Rates are
 * whole ringgit per star.
 */
class JokiTier extends Model
{
    public const KIND_DIVISIONAL = 'divisional';

    public const KIND_MYTHIC = 'mythic';

    protected $fillable = [
        'name', 'sort_order', 'kind', 'divisions', 'stars_per_division', 'mythic_from_stars', 'rate_rm', 'express_rate_rm', 'icon_url',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'divisions' => 'integer',
        'stars_per_division' => 'integer',
        'mythic_from_stars' => 'integer',
        'rate_rm' => 'integer',
        'express_rate_rm' => 'integer',
    ];

    public function isDivisional(): bool
    {
        return $this->kind === self::KIND_DIVISIONAL;
    }

    public function rateRm(bool $express): int
    {
        return $express ? $this->express_rate_rm : $this->rate_rm;
    }
}
