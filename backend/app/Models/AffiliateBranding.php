<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
        'theme_preset',
        'theme_mode',
        'description',
        'logo_path',
        'favicon_path',
        'support_email',
        'support_phone',
        'telegram_contact_link',
        'social_links',
    ];

    protected $casts = [
        'social_links' => 'array',
    ];

    /**
     * ADR-089: the admin `Settings\SettingsController` serializes this
     * model straight to JSON (no hand-built response array, unlike the
     * public/affiliate branding controllers) — without `$appends` its
     * `logo_url`/`favicon_url` accessors never reach that response.
     */
    protected $appends = ['logo_url', 'favicon_url'];

    /**
     * ADR-060 PR-6: `logo_path` stores a disk path, never a URL (the
     * addendum said `logo_url` — reversed for R2-cleanliness). The
     * storefront-facing URL is derived here from
     * `config('filesystems.gallery_disk')` so the URL base can move
     * without rewriting rows.
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->logo_path === null
            ? null
            : Storage::disk(config('filesystems.gallery_disk'))->url($this->logo_path));
    }

    /** ADR-089: same derive-at-read-time convention as `logoUrl`. */
    protected function faviconUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->favicon_path === null
            ? null
            : Storage::disk(config('filesystems.gallery_disk'))->url($this->favicon_path));
    }
}
