<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-139 decision 36: every change to a reseller's rebate eligibility is
 * recorded with who, when, before and after, and why. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_rebate_eligibility_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->index()->constrained();
            $table->boolean('from_eligible');
            $table->boolean('to_eligible');
            $table->foreignId('admin_user_id')->constrained('admin_users');
            $table->string('reason', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_rebate_eligibility_changes');
    }
};
