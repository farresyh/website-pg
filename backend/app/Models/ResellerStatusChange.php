<?php

namespace App\Models;

use App\Services\Reseller\ResellerDeactivationReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-142 decision 15: one change of a reseller's `is_active`, append-only,
 * written only by ResellerAccountStatus. `admin_user_id` is null when the
 * system made it (the dormancy job).
 */
class ResellerStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['reseller_id', 'is_active', 'reason', 'admin_user_id'];

    protected $casts = [
        'is_active' => 'boolean',
        'reason' => ResellerDeactivationReason::class,
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
