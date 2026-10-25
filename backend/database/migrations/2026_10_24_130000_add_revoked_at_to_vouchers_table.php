<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-127 P1: when an admin revoked a voucher. A revoke only flipped
 * `status`, so Cash Position kept owing the balance forever and no month
 * recorded it. Backfill: `updated_at` (production, 2026-10-24: no revoked
 * voucher).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('status');
        });

        DB::table('vouchers')->where('status', 'revoked')->update(['revoked_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    }
};
