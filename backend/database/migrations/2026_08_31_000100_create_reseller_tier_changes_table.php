<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-058 58b (RES-3 + ADR-056 decision 8): an append-only audit row
     * per reseller wholesale-tier assignment or change. Follows the
     * `membership_plan_changes` / `price_change_logs` precedent — a
     * dedicated per-feature table, not a generic activity log. Both tier
     * FKs are nullable-on-delete so pruning a tier row never takes the
     * history of resellers who were once on it.
     *
     * `from_reseller_membership_tier_id` is null for the first assignment
     * (RES-2, reseller had no subscription yet).
     */
    public function up(): void
    {
        Schema::create('reseller_tier_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->nullable()->constrained('resellers')->nullOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            // Short column names (`from_tier_id` / `to_tier_id`) keep the
            // auto-generated FK constraint + index identifiers well under
            // MySQL's 64-char limit (backend/AGENTS.md gotcha).
            $table->foreignId('from_tier_id')
                ->nullable()
                ->constrained('reseller_membership_tiers')
                ->nullOnDelete();
            $table->foreignId('to_tier_id')
                ->nullable()
                ->constrained('reseller_membership_tiers')
                ->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('reseller_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_tier_changes');
    }
};
