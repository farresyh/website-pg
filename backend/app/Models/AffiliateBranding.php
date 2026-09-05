<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-028 decision 2 (narrowed by the 2026-08-22 addendum decision
 * 10): store identity/contact fields only — see
 * App\Models\AffiliateFooterSettings for footer/legal content.
 */
class AffiliateBranding extends Model
{
    /** ADR-057: tenant-scoped to the current affiliate under the affiliate guard. */
    use BelongsToAffiliate;

    /** ADR-028 pins the table name `affiliate_branding` — not Eloquent's default pluralization. */
    protected $table = 'affiliate_branding';

    protected $fillable = [
        'affiliate_id',
        'store_name',
        'description',
        'support_email',
        'support_phone',
        'telegram_contact_link',
        'social_links',
    ];

    protected $casts = [
        'social_links' => 'array',
    ];
}
