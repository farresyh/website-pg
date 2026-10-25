<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-126 2026-10-23 addendum, decision 22: each login's wipe ceiling is
 * stored on its row (OrderCredential::expiryFor()), from the game's own
 * `credential_max_days` when set, else the Customer Login Policy's D.
 * Existing rows get `secrets_set_at + D`, the ceiling they already had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedSmallInteger('credential_max_days')->nullable()->after('member_pricing_enabled');
        });

        Schema::table('order_credentials', function (Blueprint $table) {
            $table->timestamp('secrets_expire_at')->nullable()->after('secrets_set_at');
        });

        $days = (int) (DB::table('platform_settings')->value('credential_max_days') ?? 14);

        DB::table('order_credentials')->whereNull('secrets_expire_at')->orderBy('id')->get()->each(function (object $row) use ($days) {
            DB::table('order_credentials')->where('id', $row->id)->update([
                'secrets_expire_at' => Carbon::parse($row->secrets_set_at)->addDays($days),
            ]);
        });

        Schema::table('order_credentials', function (Blueprint $table) {
            $table->timestamp('secrets_expire_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_credentials', function (Blueprint $table) {
            $table->dropColumn('secrets_expire_at');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('credential_max_days');
        });
    }
};
