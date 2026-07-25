<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closes the gap left by 2026_07_25_100200_add_foreign_keys_to_orders_table.php,
     * which deliberately left reseller_id unconstrained because no
     * Reseller table existed yet. nullOnDelete() — same reasoning as
     * game_id/package_id/supplier_id: an Order's money fields are
     * already snapshotted (ORD-9) and must never be blocked or
     * destroyed just because a Reseller row is later removed.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('reseller_id')->references('id')->on('resellers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
        });
    }
};
