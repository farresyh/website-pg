<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-061 decision 5 (PR-B): consumer-membership identity becomes
 * per-brand. `memberships` gains a `reseller_id` FK and its `unique(email)`
 * becomes `unique(reseller_id, email)` — the same email may hold a
 * separate membership on each brand's storefront, and a lookup at
 * checkout/catalog is always scoped by the resolved storefront brand.
 *
 * Backfill: every existing row is the primary brand's (ADR-013 — the one
 * pre-Phase-2 storefront). Guarded: a fresh DB with no `is_primary` row
 * (tests never seed) and no membership rows is left untouched, and the
 * NOT NULL flip then applies to an empty table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('reseller_id')->nullable()->after('id');
        });

        $primaryId = DB::table('resellers')->where('is_primary', true)->value('id');

        if ($primaryId !== null) {
            DB::table('memberships')->whereNull('reseller_id')->update(['reseller_id' => $primaryId]);
        }

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('reseller_id')->nullable(false)->change();
            $table->foreign('reseller_id')->references('id')->on('resellers')->cascadeOnDelete();
            $table->unique(['reseller_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropUnique(['reseller_id', 'email']);
            $table->dropColumn('reseller_id');
            $table->unique('email');
        });
    }
};
