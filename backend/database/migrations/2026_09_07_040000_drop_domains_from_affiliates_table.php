<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-060 (2026-09-06 "domain lifecycle" addendum, section A): the
     * free-text `affiliates.domains` JSON column is replaced by the
     * `affiliate_domains` table (created in PR-2 / migration
     * 2026_09_07_010000). PR-5 is where the last readers go away — the
     * admin CRUD stops accepting/returning it, the portal Domain screen
     * takes over — so the column is dropped here.
     *
     * Production has no data in it (the branded-storefront channel was
     * never commercially live), and the primary affiliate's own
     * hostnames are `STOREFRONT_PRIMARY_HOSTS` config, not this column
     * (PR-3 addendum) — so there is nothing to migrate into rows.
     */
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn('domains');
        });
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->json('domains')->nullable();
        });
    }
};
