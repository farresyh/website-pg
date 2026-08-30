<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PRD §8: a branded storefront owner. The platform owner IS the first
 * row here (markup_pct=0), not a separate concept — see the
 * create_resellers_table migration's doc comment. No `balance` column:
 * balance is always derived from LedgerEntry (ADR-002).
 *
 * Soft-deletes since ADR-058 58b (RES-6): an `orders.reseller_id` FK
 * points here and that history must outlive a "deleted" reseller.
 */
class Reseller extends Model
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
        'xendit_subaccount_id',
        'notes',
    ];

    protected $casts = [
        'markup_pct' => 'decimal:2',
        'max_markup_pct' => 'decimal:2',
        'domains' => 'array',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(ResellerUser::class);
    }

    /** ADR-056: one subscription row per reseller (its `reseller_id` is unique). */
    public function subscription(): HasOne
    {
        return $this->hasOne(ResellerSubscription::class);
    }

    /** ADR-058 58b (RES-3): append-only wholesale-tier assignment history. */
    public function tierChanges(): HasMany
    {
        return $this->hasMany(ResellerTierChange::class);
    }

    /**
     * PRD §8 / ADR-013: exactly one Reseller row for MVP, the platform
     * owner (markup_pct=0). `firstOrCreate` is a safety net for any
     * environment that skipped seeding — same stopgap CheckoutController
     * already used before this was extracted as a second call site
     * (CatalogController) made the duplication worth removing.
     *
     * ADR-028 decision 9: every call site, kept current so a future
     * Phase 2 domain-routing session can find all of them from here,
     * not by re-reading every ADR that ever mentioned one —
     * CheckoutController, CatalogController, VoucherPreviewController,
     * Middleware\SandboxOrderController, BrandingController (public
     * branding/footer/legal endpoint, ADR-028 addendum), and
     * Admin\SettingsController (the same reseller's own edit side).
     */
    public static function platformOwner(): self
    {
        return static::query()->firstOrCreate(
            ['business_name' => 'Platform Owner'],
            ['markup_pct' => 0, 'status' => 'active'],
        );
    }
}
