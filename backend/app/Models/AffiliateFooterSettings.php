<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-028 addendum decision 10: footer text + the three legal-page
 * contents (sanitized HTML on save, decision 12) + Footer Games
 * (decision 14 — `footer_game_ids` is an ordered JSON array of
 * `Game.id`, order is display order).
 */
class AffiliateFooterSettings extends Model
{
    /** ADR-057: tenant-scoped to the current affiliate under the affiliate guard. */
    use BelongsToAffiliate;

    protected $table = 'affiliate_footer_settings';

    protected $fillable = [
        'affiliate_id',
        'footer_text',
        'terms_content',
        'privacy_content',
        'about_us_content',
        'footer_game_ids',
    ];

    protected $casts = [
        'footer_game_ids' => 'array',
    ];
}
