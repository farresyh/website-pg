<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Homepage hero-banner content (docs/prd.md §14/§15 backlog item,
     * "Hero Banner / Campaign management") — admin-authored marketing
     * copy, deliberately separate from a future Promotions feature
     * (founder decision, 2026-07-26): a promotion ties to a real
     * Game/Package + a real discounted price, a hero slide never does
     * — `price_from_sen` here is display copy an admin types, not a
     * computed/linked price (same distinction as ORD-9 draws between
     * server-computed money and marketing text that merely mentions a
     * number). `image_url` is a plain string, same pattern as
     * `games.image_url` today — no upload mechanism yet (Image Gallery
     * is a separate, follow-up backlog item).
     */
    public function up(): void
    {
        Schema::create('hero_slides', function (Blueprint $table) {
            $table->id();
            $table->string('eyebrow')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->unsignedInteger('price_from_sen')->nullable();
            $table->string('primary_cta_label');
            $table->string('primary_cta_href');
            $table->string('secondary_cta_label')->nullable();
            $table->string('secondary_cta_href')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hero_slides');
    }
};
