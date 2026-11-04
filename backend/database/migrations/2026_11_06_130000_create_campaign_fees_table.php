<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-118 V12, PR 2: a KOL campaign's fee schedule (the agreement, accrued
 * in its month of service) and the campaign tag on an Envelope Ledger
 * payment (the cash). Prepaid / (accrued) is their difference, computed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            // SST included when the KOL charges it (not recoverable, the accountant's answer).
            $table->unsignedInteger('monthly_fee_sen');
            // First day of the month the row takes effect; rows are append-only.
            $table->date('from_month');
            // Last month the fee runs; null = until changed. The only field that can be set later.
            $table->date('to_month')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'from_month']);
            $table->index('created_by');
        });

        Schema::table('budget_envelope_postings', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('expense_category')->constrained()->restrictOnDelete();
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('budget_envelope_postings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::dropIfExists('campaign_fees');
    }
};
