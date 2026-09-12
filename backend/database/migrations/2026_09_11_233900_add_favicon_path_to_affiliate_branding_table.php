<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-089: a favicon is its own square asset — never derived from
     * `logo_path` (that one is free-aspect, a wordmark/wide logo crops
     * badly to a square tab icon). Same disk-path convention as
     * `logo_path` — the URL is derived at read time, never stored.
     */
    public function up(): void
    {
        Schema::table('affiliate_branding', function (Blueprint $table) {
            $table->string('favicon_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_branding', function (Blueprint $table) {
            $table->dropColumn('favicon_path');
        });
    }
};
