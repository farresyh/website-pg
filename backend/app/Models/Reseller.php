<?php

namespace App\Models;

use App\Services\Auth\AccountOwnerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ADR-072/073: a prepaid-wallet wholesale buyer — the Reseller API
 * (ADR-074) and Reseller Bot (ADR-075) channels' shared account shape.
 * Distinct from `Affiliate` (whitelabel storefront partner): this entity
 * only ever spends against a deposited balance, never earns. No
 * `balance` column — always derived from `LedgerEntry` (ADR-002), owner
 * type `LedgerOwnerType::ResellerWallet`.
 *
 * `is_active` (ADR-072 decision 9) is the account-level order-placement
 * kill switch; it does not freeze the wallet balance itself. Soft-delete
 * mirrors `Affiliate`'s own precedent (order history, once PR-D lands,
 * must outlive a "deleted" reseller).
 */
class Reseller extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_name',
        'contact_name',
        'email',
        'phone',
        'reseller_tier_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tier(): BelongsTo
    {
        return $this->belongsTo(ResellerTier::class, 'reseller_tier_id');
    }

    /** ADR-074 decision 1: this account's Reseller API credentials. */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ResellerApiKey::class);
    }

    /** ADR-075 decision 2: this account's linked Reseller Bot WhatsApp groups. */
    public function whatsAppGroups(): HasMany
    {
        return $this->hasMany(ResellerWhatsAppGroup::class);
    }

    /**
     * PR-G: this account's portal login user(s) — `affiliate_users` rows
     * with `owner_type = 'reseller'`. Mirrors `Affiliate::users()`'s own
     * scoped `hasMany` (no Eloquent morphTo, see `AffiliateUser`'s own
     * doc comment for why).
     */
    public function users(): HasMany
    {
        return $this->hasMany(AffiliateUser::class, 'owner_id')
            ->where('owner_type', AccountOwnerType::Reseller->value);
    }
}
