<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-091 2026-11-01 addendum, decision 3: display-only grouping for the
 * public /price-list. `family` ("Mobile Legends") groups games across
 * regions; `region_label` ("Malaysia") names the game inside its family.
 * Both nullable: a game with neither is its own group, so every creator of a
 * game (admin, supplier import, Joki) stays untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('family', 60)->nullable()->after('category');
            $table->string('region_label', 40)->nullable()->after('family');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['family', 'region_label']);
        });
    }
};
