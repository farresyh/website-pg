<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-124 decision 10: Admin → Settings → Customer Login Policy. G, H, D
 * and the reveal limit per decisions 5 and 7 (2026-10-18 addendum).
 * `consent_text` is TEXT (no DB default on MySQL), so the existing row
 * gets the default here (written out, so this migration never changes
 * when the model's constant does) and `PlatformSettings::current()` gives
 * it to a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('credential_close_grace_hours')->default(24);
            $table->unsignedSmallInteger('credential_unpaid_hours')->default(24);
            $table->unsignedSmallInteger('credential_max_days')->default(14);
            $table->unsignedSmallInteger('credential_reveal_limit_per_hour')->default(20);
            $table->text('credential_consent_text')->nullable();
        });

        DB::table('platform_settings')->update(['credential_consent_text' => 'I allow this store to use my game login only to complete this order. It is deleted once the order is finished, and I will change my password afterwards.']);
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn([
                'credential_close_grace_hours',
                'credential_unpaid_hours',
                'credential_max_days',
                'credential_reveal_limit_per_hour',
                'credential_consent_text',
            ]);
        });
    }
};
