<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-126 addendum decision 5: a route package (e.g. Epic II → Mythic) at
 * a founder-set promo price in whole ringgit, or a quotation with no
 * price. The package is the joki game's `Package` row; its own price
 * fields are never read (F1).
 */
class JokiRoute extends Model
{
    protected $fillable = [
        'package_id', 'from_tier_id', 'from_division', 'from_stars', 'to_tier_id', 'to_division', 'to_stars', 'price_rm', 'is_quotation',
    ];

    protected $casts = [
        'from_division' => 'integer',
        'from_stars' => 'integer',
        'to_division' => 'integer',
        'to_stars' => 'integer',
        'price_rm' => 'integer',
        'is_quotation' => 'boolean',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return array{tier_id: int, division: ?int, stars: ?int} */
    public function fromRank(): array
    {
        return ['tier_id' => $this->from_tier_id, 'division' => $this->from_division, 'stars' => $this->from_stars];
    }

    /** @return array{tier_id: int, division: ?int, stars: ?int} */
    public function toRank(): array
    {
        return ['tier_id' => $this->to_tier_id, 'division' => $this->to_division, 'stars' => $this->to_stars];
    }
}
