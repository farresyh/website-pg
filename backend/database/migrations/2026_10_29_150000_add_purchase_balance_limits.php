<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-131 decisions 4 and 11: the Purchase Balance limits (RM10,000 per
 * top-up, RM100,000 default balance cap, both in sen), a per-reseller
 * cap override (null = the platform default), and the seller details
 * a top-up receipt prints. The limit defaults are written out here so
 * this migration never changes when a constant does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('topup_max_sen')->default(1_000_000);
            $table->unsignedBigInteger('balance_cap_default_sen')->default(10_000_000);
            $table->string('company_legal_name', 191)->nullable();
            $table->string('company_reg_no', 64)->nullable();
            $table->text('company_address')->nullable();
        });

        Schema::table('resellers', function (Blueprint $table) {
            $table->unsignedBigInteger('balance_cap_sen')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resellers', function (Blueprint $table) {
            $table->dropColumn('balance_cap_sen');
        });

        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['topup_max_sen', 'balance_cap_default_sen', 'company_legal_name', 'company_reg_no', 'company_address']);
        });
    }
};
