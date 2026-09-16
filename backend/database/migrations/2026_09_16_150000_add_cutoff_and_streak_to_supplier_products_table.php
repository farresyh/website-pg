<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-100 — two pieces of state `PendingReactivationAutoApprover` needs
 * that `ProductSyncService::sync()` was previously discarding entirely.
 *
 * `cutoff_start`/`cutoff_end` ("hh:mm", Digiflazz's own `start_cut_off`/
 * `end_cut_off` fields, Asia/Jakarta local time per Digiflazz's docs —
 * developer.digiflazz.com/api/buyer/daftar-harga) — Digiflazz-only, a
 * seller's own documented daily maintenance window, nullable for every
 * other supplier and for a Digiflazz product with no cutoff set. Kept
 * as raw "hh:mm" strings, not `time` columns — Digiflazz never
 * documents an "unset" sentinel distinct from `"00:00"`/`"00:00"`, and
 * a string round-trips exactly what the API sent for that ambiguity to
 * be resolved in application code (`PendingReactivationAutoApprover`
 * treats `cutoff_start === cutoff_end` as "no real cutoff data"),
 * rather than baking an assumption into the column type.
 *
 * `consecutive_active_syncs` — how many Price Sync runs in a row this
 * exact row has reported `status_raw = 'active'`, reset to 0 the
 * moment it isn't. `supplier_products` has never retained sync
 * history (`updateOrCreate` overwrites in place), so this is the one
 * piece of history `PendingReactivationAutoApprover`'s N-consecutive-
 * sync confirmation gate needs that no existing column provides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->string('cutoff_start', 5)->nullable()->after('status_raw');
            $table->string('cutoff_end', 5)->nullable()->after('cutoff_start');
            $table->unsignedSmallInteger('consecutive_active_syncs')->default(0)->after('cutoff_end');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropColumn(['cutoff_start', 'cutoff_end', 'consecutive_active_syncs']);
        });
    }
};
