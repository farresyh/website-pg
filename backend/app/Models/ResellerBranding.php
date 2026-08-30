<?php

namespace App\Models;

use App\Models\Concerns\BelongsToReseller;
use Illuminate\Database\Eloquent\Model;

/**
 * ADR-028 decision 2 (narrowed by the 2026-08-22 addendum decision
 * 10): store identity/contact fields only — see
 * App\Models\ResellerFooterSettings for footer/legal content.
 */
class ResellerBranding extends Model
{
    /** ADR-057: tenant-scoped to the current reseller under the reseller guard. */
    use BelongsToReseller;

    /** ADR-028 pins the table name `reseller_branding` — not Eloquent's default pluralization. */
    protected $table = 'reseller_branding';

    protected $fillable = [
        'reseller_id',
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
