<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-016 Sync Details modal: PackagePriceSyncService::deactivate()
     * never recorded *which* package/game it turned off on a given run
     * — only an aggregate `price_sync_runs.stats.deactivated` count.
     * Without a per-run log, a past run's Sync Details modal can show
     * price/cost diffs (from price_change_logs) but not which games had
     * a deactivation. This table closes that gap the same way
     * price_change_logs already does for price changes.
     * `price_sync_run_id` is nullable-on-delete rather than cascading,
     * matching price_change_logs: pruning a run row must never take the
     * deactivation history it produced with it.
     */
    public function up(): void
    {
        Schema::create('deactivation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_sync_run_id')->nullable()->constrained('price_sync_runs')->nullOnDelete();
            $table->foreignId('package_id')->constrained('packages')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deactivation_logs');
    }
};
