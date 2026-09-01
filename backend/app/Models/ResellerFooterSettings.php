<?php

namespace App\Models;

use App\Models\Concerns\BelongsToReseller;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-028 addendum decision 10: footer text + the three legal-page
 * contents (sanitized HTML on save, decision 12) + Footer Games
 * (decision 14 — `footer_game_ids` is an ordered JSON array of
 * `Game.id`, order is display order).
 */
class ResellerFooterSettings extends Model
{
    /** ADR-057: tenant-scoped to the current reseller under the reseller guard. */
    use BelongsToReseller;

    protected $table = 'reseller_footer_settings';

    protected $fillable = [
        'reseller_id',
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
