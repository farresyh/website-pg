<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-060 PR-6 addendum, 2026-09-19: an asset-only hero slide (all
     * copy baked into the image, matching acidgameshop.com/
     * topagentgames.com's pattern) is now a valid slide — reverses PR-6's
     * "primary_cta_label/primary_cta_href required, NOT NULL" decision.
     * `eyebrow`/`description`/`image_url` were already nullable.
     */
    public function up(): void
    {
        Schema::table('hero_slides', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
            $table->string('primary_cta_label')->nullable()->change();
            $table->string('primary_cta_href')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('hero_slides', function (Blueprint $table) {
            $table->string('title')->nullable(false)->change();
            $table->string('primary_cta_label')->nullable(false)->change();
            $table->string('primary_cta_href')->nullable(false)->change();
        });
    }
};
