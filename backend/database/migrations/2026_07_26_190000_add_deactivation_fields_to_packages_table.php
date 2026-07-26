<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-015 decision #4: distinguishes an admin's deliberate
     * on/off toggle (PackageController::updateStatus) from the new
     * automated Deactivation Detection (`SyncSupplierPricesJob` via
     * `PackagePriceSyncService`) — only `supplier_sync`-reasoned rows
     * are ever monitored for reactivation; an `admin`-reasoned row is
     * never auto-touched again. `deactivated_at` also powers the
     * Pending Reactivation table's "days inactive" column (SYNC-5).
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('deactivated_reason')->nullable()->after('is_active'); // 'admin' | 'supplier_sync'
            $table->timestamp('deactivated_at')->nullable()->after('deactivated_reason');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['deactivated_reason', 'deactivated_at']);
        });
    }
};
