<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-094 addendum (2026-09-24) — combo reactivation cascade.
     * `ComboPricingService::cascadeReactivate()` writes here whenever
     * every component of a `combo_component_deactivated` combo is
     * active again, regardless of whether that last component came
     * back via `PendingReactivationAutoApprover` (no admin involved,
     * `price_sync_run_id` set) or a manual approve/dismiss/restore
     * (`admin_user_id` set instead) — mirrors `deactivation_logs`'
     * own `admin_user_id` addition for `cascadeDeactivate()`'s
     * identical two-provenance shape. Either way, no human directly
     * decided *the combo's own* status — it's always a derived
     * consequence of its components, which is what keeps this table
     * scoped to "not a direct human decision on this row's package."
     */
    public function up(): void
    {
        Schema::table('package_reactivation_logs', function (Blueprint $table) {
            $table->foreignId('admin_user_id')->nullable()->after('package_id')->constrained('admin_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('package_reactivation_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_user_id');
        });
    }
};
