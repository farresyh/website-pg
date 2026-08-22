<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-029 addendum 2 decisions 12/18: JSON-LD Product schema fields
     * (`schema_brand`/`schema_category`) and the per-game `noindex`
     * directive — all still on `Game` (decision 1: per-game SEO never
     * left `Game`, only its admin UI moved elsewhere per decision 11).
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('schema_brand')->nullable()->after('seo_og_image');
            $table->string('schema_category')->nullable()->after('schema_brand');
            $table->boolean('no_index')->default(false)->after('schema_category');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['schema_brand', 'schema_category', 'no_index']);
        });
    }
};
