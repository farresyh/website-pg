<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-075's catalog-code addendum (2026-09-04), decisions 2-4: a
     * second, independent equivalence key alongside `denomination`
     * (ADR-034, left entirely untouched) for packages that have no
     * inherent numeric value — bundles/passes. Deliberately a
     * separate column rather than a synthetic `denomination` value:
     * that would let a bundle silently dedup against an unrelated
     * real-value package sharing the same number, which is exactly
     * the false-equivalence bug this addendum exists to avoid.
     *
     * No DB-level uniqueness constraint: multiple active packages
     * (from different suppliers) sharing the same `catalog_code`
     * within a game is the intended dedup shape, same as `denomination`
     * already allows. Format (must contain a non-digit character) is
     * enforced at the FormRequest layer; mutual exclusivity with
     * `denomination` is enforced there too, not by the schema.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('catalog_code')->nullable()->after('denomination');
            $table->index(['game_id', 'catalog_code']);
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['game_id', 'catalog_code']);
            $table->dropColumn('catalog_code');
        });
    }
};
