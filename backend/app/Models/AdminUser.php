<?php

namespace App\Models;

use Database\Factories\AdminUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * PRD §8: Super Admin / Admin system administrator account. Distinct
 * from the storefront's guest-checkout customers — no Customer table
 * exists (MVP stays guest-checkout, decided 2026-07-24).
 */
class AdminUser extends Authenticatable
{
    /** @use HasFactory<AdminUserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'admin_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'is_active',
        'mfa_secret',
        'mfa_enabled',
        'mfa_required',
        'mfa_recovery_codes',
        'mfa_last_timestep',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_recovery_codes',
        'mfa_last_timestep',
    ];

    protected $appends = ['mfa_status'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_secret' => 'encrypted', // AUTH-7: TOTP secret, encrypted at rest
            'mfa_enabled' => 'boolean',
            'mfa_required' => 'boolean',
            'mfa_recovery_codes' => 'array',
            'mfa_last_timestep' => 'integer',
        ];
    }

    /** ADR-121: `none` (not required), `pending` (required, not set up yet), `active`. */
    public function getMfaStatusAttribute(): string
    {
        return match (true) {
            (bool) $this->mfa_enabled => 'active',
            (bool) $this->mfa_required => 'pending',
            default => 'none',
        };
    }
}
