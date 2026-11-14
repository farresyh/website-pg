<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-022 2026-11-14 addendum: a payment that has started is bound to the
     * gateway it started on, as `orders.payment_gateway` already is. A wallet
     * top-up or membership attempt used to look its gateway up again from the
     * current `payment_methods` row; now it carries its own.
     *
     * Every existing attempt is CHIP: it is the only gateway since ADR-022's
     * 2026-09-01 addendum, and production had 26 attempts, all on `chip`
     * channels (read-only, 2026-11-14). No default: each new attempt must be
     * stamped by its writer, never assumed.
     */
    public function up(): void
    {
        foreach (['wallet_topup_attempts', 'membership_checkout_attempts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('gateway')->nullable()->after('channel_code');
            });

            DB::table($table)->update(['gateway' => 'chip']);

            Schema::table($table, function (Blueprint $t) {
                $t->string('gateway')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['wallet_topup_attempts', 'membership_checkout_attempts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('gateway');
            });
        }
    }
};
