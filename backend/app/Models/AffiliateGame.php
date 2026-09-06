<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-060 PR-6 (planning addendum decision 3): per-brand catalog
 * visibility. One row per (affiliate, game) the affiliate has an
 * explicit opinion on. No row = visible (default-on) — the storefront
 * catalog only excludes a game when a row exists with `is_visible = false`.
 *
 * Full join with an explicit `is_visible` rather than a sparse
 * disabled-list so a future `sort_order` / featured flag slots in
 * without a migration, and so an affiliate's choice survives a game
 * being globally deactivated then reactivated.
 */
class AffiliateGame extends Model
{
    /** ADR-057: tenant-scoped to the current affiliate under the affiliate guard. */
    use BelongsToAffiliate;

    protected $table = 'affiliate_game';

    protected $fillable = [
        'affiliate_id',
        'game_id',
        'is_visible',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
