<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Founder revision, 2026-07-25: `reseller_cost_price` is no longer
     * admin-typed directly — admin sets `markup_percent` per package
     * (in /admin/games, not at promote time), and
     * `reseller_cost_price` is computed and stored from it
     * (`PackageMarkupService`). Matches the legacy reference system's
     * own Markup % → Base Price pattern (legacy-reference-notes.md).
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->decimal('markup_percent', 5, 2)->default(0)->after('reseller_cost_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('markup_percent');
        });
    }
};
