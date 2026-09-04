<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PRD §8: a branded storefront owner. Every storefront — ours and
 * third-party — is a row here, treated uniformly (ADR-061, superseding
 * ADR-013's magic-string discriminator). Our own brands carry `is_owned`;
 * exactly one of those is `is_primary` (the console/job/migration
 * fallback tenant, never deletable). No `balance` column: balance is
 * always derived from LedgerEntry (ADR-002).
 *
 * Soft-deletes since ADR-058 58b (RES-6): an `orders.affiliate_id` FK
 * points here and that history must outlive a "deleted" affiliate.
 */
class Affiliate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_name',
        'contact_name',
        'email',
        'phone',
        'markup_pct',
        'max_markup_pct',
        'domains',
        'status',
        'is_owned',
        'is_primary',
        'membership_enabled',
        'notes',
        'bank_name',
        'bank_account_no',
        'bank_account_holder',
    ];

    protected $casts = [
        'markup_pct' => 'decimal:2',
        'max_markup_pct' => 'decimal:2',
        'domains' => 'array',
        'is_owned' => 'boolean',
        // Stored as `1` on the single primary row and `NULL` on every
        // other affiliate (portable nullable-unique — see the
        // add_ownership_flags_to_affiliates_table migration). The cast
        // makes reads a clean bool; no write path may ever persist
        // `false`/`0` here or it collides with the real primary.
        'is_primary' => 'boolean',
        'membership_enabled' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(AffiliateUser::class);
    }

    /** ADR-056: one subscription row per affiliate (its `affiliate_id` is unique). */
    public function subscription(): HasOne
    {
        return $this->hasOne(AffiliateSubscription::class);
    }

    /** ADR-058 58b (RES-3): append-only wholesale-tier assignment history. */
    public function tierChanges(): HasMany
    {
        return $this->hasMany(AffiliateTierChange::class);
    }

    /**
     * ADR-061: the single fallback tenant for any context with no `Host`
     * to resolve a brand from — console commands, queue jobs, migrations,
     * and admin screens not yet made brand-aware. Exactly one row carries
     * `is_primary` (DB-enforced), so `sole()` is correct: it throws on 0
     * (environment never seeded — a loud, actionable failure) or >1 (the
     * nullable-unique index was bypassed) rather than silently creating or
     * picking a row the way the old `platformOwner()` firstOrCreate did.
     *
     * ADR-028 decision 9 / ADR-061: this is the one place every "resolve
     * the platform's own storefront" call site points at. ADR-060 replaces
     * these calls with `Host` resolution in the storefront-config and
     * pricing paths; `primary()` stays only for the non-`Host` contexts
     * above.
     */
    public static function primary(): self
    {
        return static::query()->where('is_primary', true)->sole();
    }

    /**
     * ADR-061 decision 4: consumer Membership (ADR-027) is live for a
     * storefront only when BOTH the global master kill-switch
     * (`PlatformSettings.membership_enabled` — flipped off everywhere
     * during an incident) AND this brand's own `membership_enabled` are
     * true. Callers that already hold the settings row pass it in to avoid
     * a second lookup.
     */
    public function membershipEnabledEffective(?PlatformSettings $settings = null): bool
    {
        $global = ($settings ?? PlatformSettings::current())->membership_enabled;

        return $this->membership_enabled && $global;
    }
}
