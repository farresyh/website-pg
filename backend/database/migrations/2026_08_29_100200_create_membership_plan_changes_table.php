<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-027's 2026-08-29 addendum, decision 22: no reusable "who
     * changed this config and when" mechanism exists anywhere in this
     * codebase (confirmed by investigation, not assumed) — follows the
     * deactivation_logs/price_change_logs precedent (a dedicated
     * append-only table per feature) rather than adopting a generic
     * activity-log package. `membership_plan_id` is nullable-on-delete,
     * matching those tables' own discipline: pruning the plan row a log
     * entry references must never take the audit history with it.
     */
    public function up(): void
    {
        Schema::create('membership_plan_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('field_changed');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plan_changes');
    }
};
