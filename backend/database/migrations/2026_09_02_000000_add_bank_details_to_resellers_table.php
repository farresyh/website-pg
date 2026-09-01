<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-059 59c: a reseller sets its payout bank account once, in the
 * portal Profile screen, and the Withdrawal request form prefills from
 * it (founder decision 2026-08-31). The `withdrawals` table still stores
 * its own copy per request (`bank_name` / `bank_account_no` /
 * `bank_account_holder`, unchanged) — this is the default source, not a
 * replacement, so a reseller can still override per withdrawal and the
 * historical record on each row stays immutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('notes');
            $table->string('bank_account_no')->nullable()->after('bank_name');
            $table->string('bank_account_holder')->nullable()->after('bank_account_no');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'bank_account_no', 'bank_account_holder']);
        });
    }
};
