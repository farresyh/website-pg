<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discovered live 2026-07-25: SupplierProductController::promote()
     * had zero backend guard against promoting the same
     * (supplier_id, supplier_package_ref) into a second Package — the
     * Product Manager frontend only hides the "Add Again" action once
     * a raw item shows as promoted (client-side convenience), so
     * calling the promote endpoint directly bypassed it entirely and
     * created two Packages selling the exact same supplier inventory.
     * Same defense-in-depth pattern as the vouchers.order_id unique
     * index (migration 2026_07_25_140000): app-level check for a
     * friendly error, DB-level constraint as the real guarantee.
     *
     * Explicit standalone `supplier_id` index added alongside the
     * composite unique: the original create_packages_table migration's
     * `foreignId('supplier_id')->constrained(...)` never left its own
     * independent index on live MySQL (confirmed via `SHOW INDEX`) —
     * only `packages_game_id_foreign` existed for the other FK. MySQL/
     * InnoDB requires *some* index backing an active foreign key at
     * all times; without a dedicated one, the FK silently depended on
     * this migration's composite unique index instead, which then
     * blocked dropping that unique index on rollback ("Cannot drop
     * index ... needed in a foreign key constraint", MySQL error 1553
     * — hit running the concurrency suite's DatabaseMigrations
     * rollback; SQLite doesn't enforce this, so the default sqlite
     * suite never surfaced it). Fix: `down()` only reverses the unique
     * constraint this migration actually added — the standalone index
     * is a permanent correction of that pre-existing gap, not
     * something to undo, since removing it would leave the FK
     * unsupported again exactly as before.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->index('supplier_id');
            $table->unique(['supplier_id', 'supplier_package_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['supplier_id', 'supplier_package_ref']);
        });
    }
};
