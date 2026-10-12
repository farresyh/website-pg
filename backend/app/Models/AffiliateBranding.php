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
        return Attribute::get(fn (): ?string => $this->versionedUrl($this->logo_path));
    }

    /** ADR-089: same derive-at-read-time convention as `logoUrl`. */
    protected function faviconUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->versionedUrl($this->favicon_path));
    }

    /**
     * Saves a logo/favicon upload. The path is fixed per affiliate, so a
     * re-upload leaves `logo_path`/`favicon_path` unchanged and Eloquent
     * would skip the UPDATE; `updated_at` is set explicitly so `?v=` moves.
     *
     * @param  'logo_path'|'favicon_path'  $column
     */
    public function replaceImage(string $column, string $path): void
    {
        $this->{$column} = $path;
        $this->updated_at = now();
        $this->save();
    }

    /**
     * A re-upload overwrites the same fixed path, so the bare URL never
     * changes and browsers keep showing the old image. `?v=` follows
     * `updated_at`, which every logo/favicon save moves. A save of any other
     * branding field also bumps it, which only costs one refetch.
     */
    private function versionedUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $url = Storage::disk(config('filesystems.gallery_disk'))->url($path);

        return $this->updated_at === null ? $url : $url.'?v='.$this->updated_at->timestamp;
    }
}
