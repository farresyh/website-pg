<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-027's 2026-08-29 addendum, decision 20: the actual pre-launch
     * kill switch — gates both the storefront member-price badge and
     * the checkout verify-prompt. Seeded false; membership_plans itself
     * already carries real (draft) numbers, so this flag is what keeps
     * the feature invisible to customers until the founder is ready.
     */
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->boolean('membership_enabled')->default(false)->after('vip_spend_threshold_sen');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('membership_enabled');
        });
    }
};
