<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-142: why and by whom a reseller is deactivated, every status change,
 * the dormancy notices sent once per idle spell, and the dormancy settings.
 * `resellers.is_active` stays the one flag every reader checks; it is now
 * written only by ResellerAccountStatus. Production on 2026-11-16: three
 * resellers, all active, so nothing is backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Plain nullable columns only: a foreign key here makes sqlite rebuild
        // `resellers`, which the `llm_report_*` views block (it broke the dev
        // DB once, build-log 2026-11-16). Who made a change is in the history.
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('deactivation_reason', 16)->nullable();
            $table->timestamp('deactivated_at')->nullable();
        });

        Schema::create('reseller_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->index()->constrained();
            $table->boolean('is_active');
            $table->string('reason', 16)->nullable();
            $table->foreignId('admin_user_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('reseller_dormancy_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->index()->constrained();
            $table->string('kind', 16);
            // The Malaysia date of the last activity the notice is about; one
            // warning and one deactivation notice per idle spell.
            $table->date('anchor_on');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['reseller_id', 'kind', 'anchor_on'], 'reseller_dormancy_notices_once');
        });

        Schema::table('platform_settings', function (Blueprint $table) {
            $table->boolean('reseller_dormancy_enabled')->default(false);
            $table->timestamp('reseller_dormancy_enabled_at')->nullable();
            $table->unsignedSmallInteger('reseller_dormancy_days')->default(7);
            $table->unsignedSmallInteger('reseller_dormancy_warning_days')->default(3);
            $table->string('reseller_support_whatsapp', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['reseller_dormancy_enabled', 'reseller_dormancy_enabled_at', 'reseller_dormancy_days', 'reseller_dormancy_warning_days', 'reseller_support_whatsapp']);
        });
        Schema::dropIfExists('reseller_dormancy_notices');
        Schema::dropIfExists('reseller_status_changes');
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn(['deactivation_reason', 'deactivated_at']);
        });
    }
};
