<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-072 decision 5 / PR-G: `affiliate_users` (the portal-login
 * credential table) becomes polymorphic — a `Reseller` (wallet, ADR-073)
 * portal account is, internally, an `affiliate_users` row too (PR-G
 * planning addendum decision 5 — the table/model is NOT renamed a
 * second time, only these two columns are added).
 *
 * Same additive-then-drop discipline PR-A's own rename migration used
 * for every other polymorphic-owner column in this codebase
 * (LedgerOwnerType's rollout): add nullable, backfill every existing row
 * `owner_type = 'affiliate'` / `owner_id = affiliate_id` (a real schema
 * change on a table with real production rows — the founder's own
 * primary Affiliate portal users), THEN make both non-nullable, THEN
 * drop `affiliate_id` and its FK/index (data already preserved in
 * owner_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_users', function (Blueprint $table) {
            $table->string('owner_type')->nullable()->after('id');
            $table->unsignedBigInteger('owner_id')->nullable()->after('owner_type');
        });

        DB::table('affiliate_users')->update([
            'owner_type' => 'affiliate',
            'owner_id' => DB::raw('affiliate_id'),
        ]);

        Schema::table('affiliate_users', function (Blueprint $table) {
            $table->string('owner_type')->nullable(false)->change();
            $table->unsignedBigInteger('owner_id')->nullable(false)->change();
        });

        Schema::table('affiliate_users', function (Blueprint $table) {
            // Array-of-columns form, not the explicit-name string form —
            // SQLite's grammar (the test suite's driver) only supports
            // dropping a foreign key by the column(s) it's on, not by its
            // stored constraint name (real MySQL supports both; PR-A's
            // own rename migration follows this same array-form
            // convention for every dropForeign() call for exactly this
            // reason).
            $table->dropForeign(['affiliate_id']);
            $table->dropIndex('affiliate_users_affiliate_id_index');
            $table->dropColumn('affiliate_id');

            $table->index(['owner_type', 'owner_id'], 'affiliate_users_owner_type_owner_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_users', function (Blueprint $table) {
            $table->dropIndex('affiliate_users_owner_type_owner_id_index');
            $table->foreignId('affiliate_id')->nullable()->after('id');
        });

        // Best-effort: only 'affiliate'-owned rows have a meaningful
        // affiliate_id to restore — any 'reseller'-owned row created
        // after this migration shipped has no pre-migration shape to
        // roll back to and is left with a null affiliate_id.
        DB::table('affiliate_users')->where('owner_type', 'affiliate')->update([
            'affiliate_id' => DB::raw('owner_id'),
        ]);

        Schema::table('affiliate_users', function (Blueprint $table) {
            $table->index('affiliate_id', 'affiliate_users_affiliate_id_index');
            $table->foreign('affiliate_id', 'affiliate_users_affiliate_id_foreign')
                ->references('id')->on('affiliates')->cascadeOnDelete();

            $table->dropColumn(['owner_type', 'owner_id']);
        });
    }
};
