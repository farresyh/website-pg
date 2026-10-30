<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-132: one auto-opened payout ticket per affiliate per Malaysia month.
// NULL on manual-era and platform rows; a unique index tolerates many NULLs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('payout_period', 7)->nullable()->after('owner_id');
            $table->unique(['owner_type', 'owner_id', 'payout_period'], 'withdrawals_owner_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropUnique('withdrawals_owner_period_unique');
            $table->dropColumn('payout_period');
        });
    }
};
