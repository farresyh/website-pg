<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-118 2026-11-19 addendum, V26 + V30: a fee row's share of recognised
 * sales, and the statement each month close freezes per campaign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_fees', function (Blueprint $table) {
            // Basis points of the month's recognised sales: 200 = 2%. Limited by the promo margin floor (V29).
            $table->unsignedSmallInteger('revenue_share_bp')->default(0)->after('monthly_fee_sen');
            // Optional monthly cap on the share; null = none.
            $table->unsignedInteger('revenue_share_cap_sen')->nullable()->after('revenue_share_bp');
        });

        Schema::create('campaign_month_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            // The close that froze it; a reopen voids both together.
            $table->foreignId('accounting_period_close_id')->constrained()->restrictOnDelete();
            $table->date('period_month');
            // The terms that applied, copied so the statement reads alone.
            $table->unsignedInteger('monthly_fee_sen');
            $table->unsignedSmallInteger('revenue_share_bp');
            $table->unsignedInteger('revenue_share_cap_sen')->nullable();
            $table->bigInteger('sales_sen');
            $table->bigInteger('profit_sen');
            $table->unsignedBigInteger('share_before_limits_sen');
            $table->unsignedBigInteger('share_sen');
            $table->unsignedBigInteger('payable_sen');
            // 'profit' or 'cap' when a limit cut the share (V28).
            $table->string('limited_by', 10)->nullable();
            $table->json('lines');
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'accounting_period_close_id']);
            $table->index(['period_month', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_month_statements');

        Schema::table('campaign_fees', function (Blueprint $table) {
            $table->dropColumn(['revenue_share_bp', 'revenue_share_cap_sen']);
        });
    }
};
