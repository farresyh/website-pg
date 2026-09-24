<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-100 — one row per package reactivated without a direct human
 * decision on *that specific package*: either `PendingReactivationAutoApprover`
 * (no admin involved, `price_sync_run_id` set) or, since the ADR-094
 * addendum (2026-09-24), `ComboPricingService::cascadeReactivate()` —
 * a combo reactivated as a derived consequence of its components all
 * being active again, which can itself be triggered by a manual
 * component approve (`admin_user_id` set instead). See that migration's
 * own docblock for why this is a sibling table to `deactivation_logs`,
 * not a widened one.
 */
class PackageReactivationLog extends Model
{
    protected $fillable = [
        'package_id',
        'admin_user_id',
        'price_sync_run_id',
        'trigger',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function priceSyncRun(): BelongsTo
    {
        return $this->belongsTo(PriceSyncRun::class);
    }

    /**
     * ADR-094 addendum — null for a `combo_components_all_active`
     * cascade triggered by `PendingReactivationAutoApprover`; set when
     * the last component came back via a manual approve/dismiss/restore.
     */
    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }
}
