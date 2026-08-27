<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-049 decision 5: the only Customer Analytics segment threshold
     * left configurable — Frequent/Dormant/New/One-time stay hardcoded
     * constants (CustomerSegment). Integer sen, matching every other
     * money field in this codebase; default 500000 = RM5,000.
     */
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedInteger('vip_spend_threshold_sen')->default(500000)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('vip_spend_threshold_sen');
        });
    }
};
