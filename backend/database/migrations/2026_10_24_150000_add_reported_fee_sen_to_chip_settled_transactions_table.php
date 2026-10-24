<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-127 F2: the fee CHIP reported when the payment was confirmed
 * (`gateway_fee_sen` on the matched record), copied at ingest so the
 * Settlements page can show where the file's fee differs. Null = not
 * reported (unmatched, or paid before F2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chip_settled_transactions', function (Blueprint $table) {
            $table->unsignedInteger('reported_fee_sen')->nullable()->after('fee_sen');
        });
    }

    public function down(): void
    {
        Schema::table('chip_settled_transactions', function (Blueprint $table) {
            $table->dropColumn('reported_fee_sen');
        });
    }
};
