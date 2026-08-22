<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-029 addendum 2 decision 14, refined 2026-08-22 (founder
     * request, same session): a robots.txt `User-agent` block never
     * inherits from `*` — a bot with its own named block (Googlebot,
     * GPTBot, etc.) ignores whatever `*` disallows entirely. Storing
     * "paths every bot should skip" per-row on `crawler_rules` would
     * mean re-entering the same paths on every current *and future*
     * bot row. One shared list here instead, merged into every
     * `is_allowed` bot's rule at render time (`SeoController::robots()`)
     * — a path added here covers every bot automatically, including
     * ones added to `crawler_rules` later, with no per-row duplication.
     */
    public function up(): void
    {
        Schema::table('reseller_seo_settings', function (Blueprint $table) {
            $table->json('crawler_default_disallow_paths')->nullable()->after('schema_breadcrumb_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('reseller_seo_settings', function (Blueprint $table) {
            $table->dropColumn('crawler_default_disallow_paths');
        });
    }
};
