<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Admin-created entity (MUI-5) representing a real, backend-bound
 * player-ID validator — e.g. "Mobile Legends Validator" with
 * `key = "mlbb"`. Named PlayerValidatorProfile (not `PlayerValidator`)
 * to avoid colliding with the `App\Services\PlayerValidation\PlayerValidator`
 * interface it points at via `key` + PlayerValidatorRegistry.
 *
 * `key` is deliberately constrained (StorePlayerValidatorProfileRequest)
 * to PlayerValidatorRegistry::AVAILABLE_KEYS, so a profile can never
 * be created pointing at a key with no real implementation — the
 * founder's own concern, 2026-07-25, about not knowing whether a
 * validator was actually "plugged in".
 */
class PlayerValidatorProfile extends Model
{
    protected $fillable = [
        'name',
        'key',
        'last_tested_at',
        'last_test_result',
    ];

    protected $casts = [
        'last_tested_at' => 'datetime',
    ];

    public function mappings(): HasMany
    {
        return $this->hasMany(PlayerRegionMapping::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }
}
