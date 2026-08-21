<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-025 decision #2: a swing (`|new - old| / old > threshold`)
     * blocks PackagePriceSyncService::propagatePrice()'s write until an
     * admin reviews it — this table is the queue, and (per this
     * codebase's existing append-only audit-table convention —
     * price_change_logs/deactivation_logs) rows are never deleted, only
     * transitioned through `status` (pending -> approved/dismissed), so
     * it doubles as a permanent record of every anomaly ever flagged.
     * `price_sync_run_id` is nullable-on-delete for the same reason
     * price_change_logs' own FK is: a pruned run row must never take
     * the anomaly history it produced with it.
     */
    public function up(): void
    {
        Schema::create('pending_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_sync_run_id')->nullable()->constrained('price_sync_runs')->nullOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->unsignedInteger('old_cost_price');
            $table->unsignedInteger('proposed_cost_price');
            $table->unsignedInteger('old_reseller_cost_price');
            $table->unsignedInteger('proposed_reseller_cost_price');
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_price_changes');
    }
};
