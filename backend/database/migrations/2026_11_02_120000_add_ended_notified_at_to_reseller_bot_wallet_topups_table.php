<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-135 decision 8b: the group is told once when a `.topupbaki`
 * top-up ends unpaid, as `notified_at` already guards the paid message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_bot_wallet_topups', function (Blueprint $table) {
            $table->timestamp('ended_notified_at')->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_bot_wallet_topups', function (Blueprint $table) {
            $table->dropColumn('ended_notified_at');
        });
    }
};
