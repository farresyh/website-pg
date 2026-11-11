<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-140 decision 5, the contract release. Since PR 1 (#781) nothing reads or writes
 * these three columns: the storefront never did, and the admin form, `Game::$fillable`,
 * `UpdateGameSeoRequest` and `CatalogController` stopped in that PR. Production held one
 * repeated seed template (with unreplaced `{game_name}` tokens) in the two `_local`
 * columns and nothing in `seo_keywords`; no view or index touches them.
 *
 * `down()` restores the shape, not the data (the data was that one template).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['seo_title_local', 'seo_description_local', 'seo_keywords']);
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('seo_title_local')->nullable();
            $table->text('seo_description_local')->nullable();
            $table->string('seo_keywords')->nullable();
        });
    }
};
