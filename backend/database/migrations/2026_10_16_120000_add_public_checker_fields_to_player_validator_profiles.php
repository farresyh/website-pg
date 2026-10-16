<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-123 decision 2: a validator profile is a game family on the public
 * Game ID Checker. `public_slug` is the SEO URL (`/check-id/<slug>`); a
 * profile without one isn't listed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_validator_profiles', function (Blueprint $table) {
            $table->string('public_name')->nullable()->after('key');
            $table->string('public_slug')->nullable()->unique()->after('public_name');
            $table->string('public_image_url', 2048)->nullable()->after('public_slug');
        });
    }

    public function down(): void
    {
        Schema::table('player_validator_profiles', function (Blueprint $table) {
            $table->dropUnique(['public_slug']);
            $table->dropColumn(['public_name', 'public_slug', 'public_image_url']);
        });
    }
};
