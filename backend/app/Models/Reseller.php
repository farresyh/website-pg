<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §8: a branded storefront owner. The platform owner IS the first
 * row here (markup_pct=0), not a separate concept — see the
 * create_resellers_table migration's doc comment. No `balance` column:
 * balance is always derived from LedgerEntry (ADR-002).
 */
class Reseller extends Model
{
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
