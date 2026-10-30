<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use App\Services\Affiliate\AffiliateDomainStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum): a custom domain
 * attached to an affiliate's branded storefront. See the migration for
 * the column semantics.
 *
 * `BelongsToAffiliate` so the portal / admin CRUD (PR-5) is tenant-scoped
 * by default. The public `Host` resolver (`ResolveStorefrontBrand`) runs
 * in guest context — `CurrentAffiliate` inactive — so the scope no-ops
 * there and it can match any brand's hostname, which is exactly right.
 */
class AffiliateDomain extends Model
{
    use BelongsToAffiliate;

    protected $fillable = [
        'affiliate_id',
        'hostname',
        'is_primary',
        'redirects_to_domain_id',
        'status',
        'provider',
        'provider_ref',
        'verification',
        'last_checked_at',
        'verified_at',
        'last_error',
        'payments_approved_at',
        'payments_approved_by',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'status' => AffiliateDomainStatus::class,
        'verification' => 'array',
        'last_checked_at' => 'datetime',
        'verified_at' => 'datetime',
        'payments_approved_at' => 'datetime',
    ];

    /**
     * ADR-060 second 2026-10-30 addendum, decision 4: the rows that can serve
     * a storefront, be canonical, primary or a CORS origin. A redirect twin
     * (`www.` → the typed domain) is none of these; every reader that needs a
     * servable domain goes through this scope.
     *
     * @param  Builder<AffiliateDomain>  $query
     */
    public function scopeServable(Builder $query): void
    {
        $query->whereNull('redirects_to_domain_id');
    }

    public function isRedirectTwin(): bool
    {
        return $this->redirects_to_domain_id !== null;
    }

    /** @return BelongsTo<AffiliateDomain, $this> */
    public function redirectTarget(): BelongsTo
    {
        return $this->belongsTo(self::class, 'redirects_to_domain_id');
    }

    /** @return HasMany<AffiliateDomain, $this> */
    public function twins(): HasMany
    {
        return $this->hasMany(self::class, 'redirects_to_domain_id');
    }
}
