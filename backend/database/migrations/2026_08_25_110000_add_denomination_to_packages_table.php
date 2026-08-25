<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-034 decision 1: the package's own inherent value (e.g.
     * diamond/UC amount), scoped per game — equivalence key for
     * storefront best-price dedup is (game_id, denomination), never
     * denomination alone. Nullable: existing packages stay unset
     * (decision 3, no mass backfill), and non-integer-amount products
     * (bundles/passes) are expected to stay null forever.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('denomination')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('denomination');
        });
    }
};
