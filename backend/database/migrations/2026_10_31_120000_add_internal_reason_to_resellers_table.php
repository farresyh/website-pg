<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-133 decision 12(c): set only on an account made by "Create internal account" (a test or
// internal account, no KYC on file). NULL on every other reseller.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->string('internal_reason', 500)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn('internal_reason');
        });
    }
};
