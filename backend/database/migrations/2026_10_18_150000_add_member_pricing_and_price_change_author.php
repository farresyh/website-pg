<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-125 decisions 7/35/37: `member_pricing_enabled` per game, on for
 * every existing game so nothing changes until an admin turns it off.
 * Decision 45: a price change made by hand (manual package cost edit,
 * pending-change approval) records who made it; a sync row stays null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->boolean('member_pricing_enabled')->default(true)->after('player_validator_enabled');
        });

        Schema::table('price_change_logs', function (Blueprint $table) {
            $table->foreignId('admin_user_id')->nullable()->after('price_sync_run_id')->constrained('admin_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_change_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_user_id');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('member_pricing_enabled');
        });
    }
};
