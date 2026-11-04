<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-118 V19: the campaign scorecard's panel settings. A qualified new
 * customer's first order is at least `campaign_qualified_min_sen` (RM10)
 * or they bought again. The verdict shows only once both the target CAC
 * and the trial length are set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedInteger('campaign_qualified_min_sen')->default(1000)->after('campaign_min_margin_percent');
            $table->unsignedInteger('campaign_target_cac_sen')->nullable()->after('campaign_qualified_min_sen');
            $table->unsignedTinyInteger('campaign_trial_months')->nullable()->after('campaign_target_cac_sen');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['campaign_qualified_min_sen', 'campaign_target_cac_sen', 'campaign_trial_months']);
        });
    }
};
