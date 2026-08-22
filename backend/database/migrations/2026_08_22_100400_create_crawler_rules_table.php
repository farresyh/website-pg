<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-029 addendum 2 decision 14: per-bot allow/block rules feeding
     * Next.js's native app/robots.ts. Deliberately NOT reseller-scoped
     * (unlike every other table in this ADR) — robots.txt is one file
     * per domain, not per storefront-content concern; a build-time call
     * made here since decision 14 itself didn't specify scoping and
     * this project has exactly one domain today (ADR-020). Revisit
     * only if a real multi-domain reseller need surfaces.
     */
    public function up(): void
    {
        Schema::create('crawler_rules', function (Blueprint $table) {
            $table->id();
            $table->string('bot_name');
            $table->string('user_agent')->unique();
            $table->boolean('is_allowed')->default(true);
            $table->unsignedInteger('crawl_delay')->nullable();
            $table->json('disallow_paths')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawler_rules');
    }
};
