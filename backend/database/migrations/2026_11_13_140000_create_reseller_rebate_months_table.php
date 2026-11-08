<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-139 decision 10: one row per Malaysia month the rebate finalise task
 * has run for. Month close (decision 23, PR 3b-ii) reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_rebate_months', function (Blueprint $table) {
            $table->id();
            $table->date('period')->unique();
            $table->timestamp('finalized_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_rebate_months');
    }
};
