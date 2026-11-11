<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-140 decision 6: a game's own page content — a short summary (what
 * llms.txt, JSON-LD and an AI answer quote), a plain-text "about", and a FAQ
 * (a JSON list of {question, answer}). All optional. `content_published_at`
 * is null for a draft or empty game; the status is derived, never stored.
 * Purely additive: nothing reads these until PR 2's code is deployed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->text('content_summary')->nullable();
            $table->text('content_about')->nullable();
            $table->json('content_faq')->nullable();
            $table->timestamp('content_published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['content_summary', 'content_about', 'content_faq', 'content_published_at']);
        });
    }
};
