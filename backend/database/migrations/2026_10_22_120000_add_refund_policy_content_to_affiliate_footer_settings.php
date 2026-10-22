<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fourth admin-edited legal page, /refund-policy (CHIP's merchant review,
 * 2026-10-22), stored beside the other three (ADR-028 addendum decision 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_footer_settings', function (Blueprint $table) {
            $table->text('refund_policy_content')->nullable()->after('about_us_content');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_footer_settings', function (Blueprint $table) {
            $table->dropColumn('refund_policy_content');
        });
    }
};
