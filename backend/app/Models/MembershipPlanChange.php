<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-027's 2026-08-29 addendum, decision 22: audit trail for
 * /admin/membership tier edits, following the deactivation_logs /
 * price_change_logs precedent (a dedicated append-only table) rather
 * than a generic activity-log mechanism, since none exists in this
 * codebase yet.
 */
class MembershipPlanChange extends Model
{
    protected $fillable = [
        'membership_plan_id',
        'admin_user_id',
        'field_changed',
        'old_value',
        'new_value',
    ];

    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
