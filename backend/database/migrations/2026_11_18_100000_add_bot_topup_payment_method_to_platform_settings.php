<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-076 2026-11-12 addendum, decision 1: the payment method `.topupbaki`
 * uses, chosen in Settings → Purchase Balance. By id, never by channel code
 * (codes have been renamed). Starts on FPX; null means "the first offered".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->foreignId('bot_topup_payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
        });

        $fpx = DB::table('payment_methods')->where('channel_code', 'fpx')->value('id');
        if ($fpx !== null) {
            DB::table('platform_settings')->update(['bot_topup_payment_method_id' => $fpx]);
        }
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bot_topup_payment_method_id');
        });
    }
};
