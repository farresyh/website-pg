<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-090: a per-affiliate fixed choice (light/dark), not a
     * viewer-side toggle — resolved server-side same as `theme_preset`
     * and injected as the matching `tokens`/`tokensDark` set. Plain
     * `string` (not a DB enum) to match `theme_preset`'s own convention
     * — the FormRequest owns the closed set of valid values.
     */
    public function up(): void
    {
        Schema::table('affiliate_branding', function (Blueprint $table) {
            $table->string('theme_mode')->default('light')->after('theme_preset');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_branding', function (Blueprint $table) {
            $table->dropColumn('theme_mode');
        });
    }
};
