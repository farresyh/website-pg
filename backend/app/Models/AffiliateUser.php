<?php

namespace App\Models;

use App\Services\Auth\AccountOwnerType;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * ADR-058 (58a): a staff login for one affiliate's portal (ADR-059),
 * authenticated through the `affiliate` Sanctum guard. Separate from
 * AdminUser by design — separate table, separate token namespace, so a
 * missed role check can never cross the admin/affiliate boundary.
 *
 * ADR-072 decision 5 / PR-G: this table is polymorphic — a `Reseller`
 * (wallet, ADR-073) portal account is, internally, an `affiliate_users`
 * row too (`owner_type = 'reseller'`), per the PR-G planning addendum's
 * decision 5. The table/model itself is NOT renamed a second time.
 *
 * Build-time judgment call: `owner()` is resolved via two explicit
 * accessor methods (`affiliateOwner()`/`resellerOwner()`) rather than
 * an Eloquent `morphTo()`/`morphMap()` — this codebase has never used
 * Eloquent's polymorphic relations anywhere (confirmed by grep before
 * writing this); `LedgerEntry`/`LedgerAccount`'s own `owner_type`/
 * `owner_id` pair (`LedgerOwnerType`) is read back manually by every
 * caller too, never via a relation. Introducing `morphTo()` for the
 * first time on this one model would be a new idiom this codebase
 * doesn't otherwise use, not a fit with everything else that already
 * shares this exact "raw type string + id, resolved explicitly" shape.
 *
 * Does NOT use App\Models\Concerns\BelongsToAffiliate — see the
 * migration doc comment and the ADR-058 build addendum.
 */
class AffiliateUser extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasApiTokens, Notifiable;

    protected $table = 'affiliate_users';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'name',
        'email',
        'password',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'owner_type' => AccountOwnerType::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** Null when this row's owner_type isn't 'affiliate' (never both). */
    public function affiliateOwner(): ?Affiliate
    {
        if ($this->owner_type !== AccountOwnerType::Affiliate) {
            return null;
        }

        return Affiliate::query()->find($this->owner_id);
    }

    /** Null when this row's owner_type isn't 'reseller' (never both). */
    public function resellerOwner(): ?Reseller
    {
        if ($this->owner_type !== AccountOwnerType::Reseller) {
            return null;
        }

        return Reseller::query()->find($this->owner_id);
    }

    /**
     * The business/account name to show in an invite email or portal
     * header, regardless of owner kind — the one place both branches
     * are folded into a single call, so callers (AffiliateInviteService,
     * AffiliateAuthController) don't each re-derive this themselves.
     */
    public function ownerBusinessName(): ?string
    {
        return match ($this->owner_type) {
            AccountOwnerType::Affiliate => $this->affiliateOwner()?->business_name,
            AccountOwnerType::Reseller => $this->resellerOwner()?->business_name,
        };
    }
}
