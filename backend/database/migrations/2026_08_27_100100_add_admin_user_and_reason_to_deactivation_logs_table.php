<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-046 decision 10 — lets one manual bulk deactivate action
     * (Supplier Management's "Deactivate All"/"Deactivate by Game")
     * and one automatic Price Sync run (PackagePriceSyncService::
     * deactivate(), already writing here with price_sync_run_id set)
     * share the same audit table instead of splitting "why did this
     * package go inactive" across two tables for the same fact.
     * `admin_user_id` stays null for automatic-run rows, exactly as
     * `price_sync_run_id` already stays null for manual-action rows.
     */
    public function up(): void
    {
        Schema::table('deactivation_logs', function (Blueprint $table) {
            $table->foreignId('admin_user_id')->nullable()->after('package_id')->constrained('admin_users')->nullOnDelete();
            $table->text('reason')->nullable()->after('admin_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('deactivation_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_user_id');
            $table->dropColumn('reason');
        });
    }
};
