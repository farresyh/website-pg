<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-094 decisions 34/37 — a partial combo delivery gives back only the
 * undelivered share of the voucher it paid with and of the member quota
 * it spent. One row per order stays (both tables are unique on
 * `order_id`); these columns record how much was given back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_redemptions', function (Blueprint $table) {
            $table->unsignedInteger('restored_amount')->nullable()->after('amount');
        });

        // Every restore before this was a full one.
        DB::table('voucher_redemptions')->where('status', 'restored')->update(['restored_amount' => DB::raw('amount')]);

        // Null on rows restored before this column existed.
        Schema::table('membership_quota_debits', function (Blueprint $table) {
            $table->unsignedInteger('restored_amount_sen')->nullable()->after('amount_sen');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_redemptions', fn (Blueprint $table) => $table->dropColumn('restored_amount'));
        Schema::table('membership_quota_debits', fn (Blueprint $table) => $table->dropColumn('restored_amount_sen'));
    }
};
