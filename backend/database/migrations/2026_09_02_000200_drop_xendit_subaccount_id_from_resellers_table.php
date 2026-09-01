<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-022's 2026-09-01 addendum, decision 1 — CHIP-only cutover. This
 * column was added by create_resellers_table for ADR-001's Phase 2
 * `xenPlatform` OWNED sub-account plan, which ADR-059 decision 6
 * superseded (reseller payout is a manual bank transfer). It has never
 * held a value, and `git grep` shows no reader outside the model's
 * `$fillable` list. Additive/safe: dropping a nullable, unused column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn('xendit_subaccount_id');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('xendit_subaccount_id')->nullable();
        });
    }
};
