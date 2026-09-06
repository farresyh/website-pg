<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class HeroSlide extends Model
{
    /**
     * ADR-060 PR-6: null `affiliate_id` = the global / primary slide set
     * (edited in `/admin/hero-slides`); a set `affiliate_id` = a brand's
     * own slide, edited self-serve in the affiliate portal.
     * `BelongsToAffiliate` scopes the portal path to the current tenant
     * and no-ops for the guest storefront + admin paths (AffiliateScope).
     */
    use BelongsToAffiliate;

    protected $fillable = [
        'affiliate_id',
        'eyebrow',
        'title',
        'description',
        'image_url',
        'image_path',
        'price_from_sen',
        'primary_cta_label',
        'primary_cta_href',
        'secondary_cta_label',
        'secondary_cta_href',
        'is_active',
        'sort_order',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'price_from_sen' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * ADR-060 PR-6: the URL the storefront renders. An affiliate upload
     * is stored as a disk path (`image_path`) so the file can be cleaned
     * up and the URL base can move (a future R2 cutover) without a data
     * migration; the URL is derived here at read time. Admin's rows keep
     * a pasted absolute `image_url` and no `image_path`.
     */
    public function resolvedImageUrl(): ?string
    {
        if ($this->image_path !== null) {
            return Storage::disk(config('filesystems.gallery_disk'))->url($this->image_path);
        }

        return $this->image_url;
    }

    /**
     * Live per the schedule window (nullable bounds = no restriction
     * on that side) AND is_active — the public catalog endpoint's
     * single source of truth for "should this slide show right now."
     */
    public function scopeLive($query)
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }
}
