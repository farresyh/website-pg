<?php

use App\Models\Reseller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-057 decision 2: `orders.reseller_id` becomes a first-class tenant
 * key.
 *
 *  1. Standalone index on `reseller_id`. The FK
 *     (2026_07_25_150100_add_reseller_foreign_key_to_orders_table) does
 *     leave `orders_reseller_id_foreign` on live MySQL — but migration
 *     2026_07_25_180000 already caught one case in this codebase where a
 *     constrained FK left NO independent index, so this adds a named
 *     index only when nothing already covers the column (verify live
 *     with `SHOW INDEX FROM orders`). SQLite — the default test DB —
 *     never auto-indexes FKs, so this is where the tenant-scoped
 *     `WHERE reseller_id = ?` query gets its index there.
 *
 *  2. Backfill every NULL `reseller_id` to the platform owner (ADR-013 —
 *     the single Reseller row; every historical order genuinely is
 *     theirs). Resolved only when there is actually something to
 *     backfill, so a fresh (test) DB with no orders never has a Reseller
 *     row conjured into existence by this migration.
 *
 * The column stays nullable. NOT NULL is deliberately left to a follow-up:
 * ~240 test fixtures build a bare Order with no `reseller_id`, so flipping
 * it here would turn a focused isolation change into a suite-wide fixture
 * refactor. The reseller scope already fails closed on reads (decision 3),
 * so a tenant-less row is invisible under the reseller guard regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        $covered = collect(Schema::getIndexes('orders'))
            ->contains(fn (array $index) => $index['columns'] === ['reseller_id']);

        if (! $covered) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index('reseller_id');
            });
        }

        if (DB::table('orders')->whereNull('reseller_id')->exists()) {
            DB::table('orders')
                ->whereNull('reseller_id')
                ->update(['reseller_id' => Reseller::platformOwner()->id]);
        }
    }

    public function down(): void
    {
        // The backfill is one-way: "was NULL before this migration" is
        // indistinguishable from "genuinely the platform owner's" after
        // the fact — and every order IS the platform owner's anyway
        // (ADR-013). Nothing safe to reverse there.

        $named = collect(Schema::getIndexes('orders'))
            ->contains(fn (array $index) => $index['name'] === 'orders_reseller_id_index');

        if ($named) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_reseller_id_index');
            });
        }
    }
};
