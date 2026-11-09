<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-139 decision 36: one change to a reseller's rebate eligibility. Written only by RebateEligibilityService. */
class ResellerRebateEligibilityChange extends Model
{
    protected $fillable = ['reseller_id', 'from_eligible', 'to_eligible', 'admin_user_id', 'reason'];

    protected $casts = [
        'from_eligible' => 'boolean',
        'to_eligible' => 'boolean',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
