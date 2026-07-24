<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A game title available for top-up (PRD §8). `category` is a
     * plain admin-assigned string, not an enum — Gamevion's own raw
     * catalog category strings vary too much to enum from (confirmed
     * against the real live catalog: "Mobile legends Global " vs
     * "Mobile Legends: Bang Bang (Malaysia)" vs "MLBB UNLIMITED
     * PROMO"), and a Game is curated by an admin from the supplier
     * catalog (SUPP-3), not auto-derived from it.
     *
     * `supports_validation` lives per supplier-mapping entry inside
     * `supplier_mappings`, not on Supplier globally (GAME-12) — varies
     * even within one supplier's own catalog.
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('image_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->json('supplier_mappings')->nullable(); // [{supplier_id, product_ref, supports_validation, supports_server_list}]
            $table->json('validation_rules')->nullable();

            // SEO-4: per-game SEO — columns only, no feature logic here
            $table->string('seo_title')->nullable();
            $table->string('seo_title_local')->nullable();
            $table->text('seo_description')->nullable();
            $table->text('seo_description_local')->nullable();
            $table->string('seo_keywords')->nullable();
            $table->string('seo_og_image')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
