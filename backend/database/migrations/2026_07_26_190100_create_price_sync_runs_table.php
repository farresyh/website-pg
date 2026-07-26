<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-015 decision #5 / ADR-016 decision #5: one row per
     * `SyncSupplierPricesJob` run — `status` (queued/running/success/
     * failed) is what `/middleware/price-sync` polls while a
     * manually-triggered run is in flight. `triggered_by` is nullable
     * so a manual trigger can leave it unset today; it distinguishes
     * `'system'` (scheduled) from an admin identity once scheduled
     * runs exist alongside manual ones (ADR-016's Sync History "who
     * started this run" column).
     */
    public function up(): void
    {
        Schema::create('price_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->string('triggered_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_sync_runs');
    }
};
