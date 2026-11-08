<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-139 (PR 3a): the rebate ladder versions, the milestone rows, the
 * programme-terms acceptances and the per-reseller eligible switch.
 *
 * A ladder version's grid is JSON (thresholds shared by every tier,
 * cumulative RM per tier id): it is written once, validated as a whole by
 * RebateLadderService and never queried by cell, so a grid table would be
 * a join with no reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_rebate_ladders', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from');
            $table->unsignedSmallInteger('max_share_bp');
            $table->json('steps');
            $table->foreignId('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->index('effective_from');
        });

        Schema::create('reseller_rebate_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->restrictOnDelete();
            $table->date('period');
            $table->string('kind', 16);
            $table->unsignedTinyInteger('step');
            $table->foreignId('ladder_id')->constrained('reseller_rebate_ladders')->restrictOnDelete();
            $table->foreignId('tier_id')->constrained('reseller_tiers')->restrictOnDelete();
            $table->unsignedInteger('amount_sen');
            $table->boolean('adjusted')->default(false);
            $table->string('state', 16);
            $table->bigInteger('sales_sen');
            $table->bigInteger('profit_sen');
            $table->unsignedInteger('ladder_cumulative_sen');
            $table->unsignedInteger('cap_sen');
            $table->timestamp('computed_at');
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('claimed_by')->nullable()->constrained('affiliate_users')->nullOnDelete();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['reseller_id', 'period', 'kind', 'step'], 'rebate_milestones_reseller_period_kind_step_unique');
            $table->index(['state', 'period']);
        });

        Schema::create('reseller_rebate_terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('resellers')->restrictOnDelete();
            $table->unsignedSmallInteger('terms_version');
            $table->foreignId('affiliate_user_id')->nullable()->constrained('affiliate_users')->nullOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->unique(['reseller_id', 'terms_version'], 'rebate_terms_reseller_version_unique');
        });

        Schema::table('resellers', function (Blueprint $table) {
            $table->boolean('rebate_eligible')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', fn (Blueprint $table) => $table->dropColumn('rebate_eligible'));
        Schema::dropIfExists('reseller_rebate_terms_acceptances');
        Schema::dropIfExists('reseller_rebate_milestones');
        Schema::dropIfExists('reseller_rebate_ladders');
    }
};
