<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-015 decision #2: every *actual* price change Price
     * Propagation applies (no-ops are skipped, never logged) — the
     * audit trail this feature exists to provide, since propagation
     * has no approval gate in either direction. `price_sync_run_id`
     * is nullable-on-delete rather than cascading: a run row being
     * pruned later must never take the price history it produced
     * with it.
     */
    public function up(): void
    {
        Schema::create('price_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_sync_run_id')->nullable()->constrained('price_sync_runs')->nullOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->unsignedInteger('old_cost_price');
            $table->unsignedInteger('new_cost_price');
            $table->unsignedInteger('old_reseller_cost_price');
            $table->unsignedInteger('new_reseller_cost_price');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_change_logs');
    }
};
