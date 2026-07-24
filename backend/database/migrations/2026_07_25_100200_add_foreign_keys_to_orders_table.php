<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resolves the TODO left in the original orders migration: add
     * real FK constraints now that `games`/`packages`/`suppliers`
     * exist. `nullOnDelete()`, not cascade/restrict — an Order
     * snapshots all its money fields at creation time (ORD-9) and
     * never depends on the catalog row still existing, so it must
     * never be blocked or destroyed just because an admin later
     * deleted a Package/Game/Supplier.
     *
     * `reseller_id` is deliberately left unconstrained — no
     * `Reseller` table yet (Phase 2, ADR-003).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('game_id')->references('id')->on('games')->nullOnDelete();
            $table->foreign('package_id')->references('id')->on('packages')->nullOnDelete();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['game_id']);
            $table->dropForeign(['package_id']);
            $table->dropForeign(['supplier_id']);
        });
    }
};
