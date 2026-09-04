<?php

namespace App\Models;

use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * ADR-058 (58a): a staff login for one affiliate's portal (ADR-059),
 * authenticated through the `affiliate` Sanctum guard. Separate from
 * AdminUser by design — separate table, separate token namespace, so a
 * missed role check can never cross the admin/affiliate boundary.
 *
 * Does NOT use App\Models\Concerns\BelongsToAffiliate — see the migration
 * doc comment and the ADR-058 build addendum. The `affiliate` relation is
 * declared explicitly here instead.
 */
class AffiliateUser extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasApiTokens, Notifiable;

    protected $table = 'affiliate_users';

    protected $fillable = [
        'affiliate_id',
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
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
