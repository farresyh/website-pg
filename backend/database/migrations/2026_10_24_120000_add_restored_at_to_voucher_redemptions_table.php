<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-127 F4: when a redemption was restored, written once with the
 * restore. Cash Position dated it by `updated_at`, which any later write
 * moves. Backfill: `updated_at`, the best record there is (a restored row
 * is not written again today).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_redemptions', function (Blueprint $table) {
            $table->timestamp('restored_at')->nullable()->after('restored_amount');
        });

        DB::table('voucher_redemptions')->where('status', 'restored')->update(['restored_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('voucher_redemptions', function (Blueprint $table) {
            $table->dropColumn('restored_at');
        });
    }
};
