<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use App\Services\Affiliate\AffiliateDomainStatus;
use Illuminate\Database\Eloquent\Model;

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
        'status',
        'provider',
        'provider_ref',
        'verification',
        'last_checked_at',
        'verified_at',
        'last_error',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'status' => AffiliateDomainStatus::class,
        'verification' => 'array',
        'last_checked_at' => 'datetime',
        'verified_at' => 'datetime',
    ];
}
