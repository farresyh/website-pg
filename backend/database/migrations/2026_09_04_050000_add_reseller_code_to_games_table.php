<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-075's catalog-code addendum (2026-09-04), decision 1: a
     * short, admin-set, globally-unique alias for a Game — the game
     * segment of a reseller-facing product code (`{reseller_code}-
     * {denomination-or-catalog_code}`, e.g. `MLMY-14`). Nullable: a
     * Game with no `reseller_code` is simply excluded from the
     * Reseller API/Bot catalog, no effect on the storefront. Format
     * ([A-Z]{2,10}) is enforced at the FormRequest layer, not here.
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('reseller_code')->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('reseller_code');
        });
    }
};
