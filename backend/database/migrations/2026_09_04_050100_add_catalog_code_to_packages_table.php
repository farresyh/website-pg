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
     *
     * Indexes `catalog_code` alone, deliberately NOT composite with
     * `game_id`: real MySQL confirmed (CI's concurrency suite, backend/
     * AGENTS.md's own documented gotcha) that `packages.game_id`'s
     * `foreignId()->constrained()` has no reliable standalone index of
     * its own — same class of bug the historical `packages.supplier_id`
     * fix addressed. A composite `(game_id, catalog_code)` index
     * silently became the FK's only supporting index and blocked its
     * own `down()` with MySQL error 1553. A `catalog_code`-only index
     * sidesteps this entirely without touching `game_id`'s indexing
     * story.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('catalog_code')->nullable()->after('denomination');
            $table->index('catalog_code');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['catalog_code']);
            $table->dropColumn('catalog_code');
        });
    }
};
