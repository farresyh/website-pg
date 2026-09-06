<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-060 (2026-09-06 "domain lifecycle fully specified" addendum,
     * section A): one row per custom domain attached to an affiliate's
     * branded storefront. Replaces the free-text `affiliates.domains`
     * JSON column (dropped in a follow-up once the portal — PR-5/PR-6 —
     * stops reading it; PR-2 only adds this table + the `Host` resolver).
     *
     *  - `hostname` is globally unique: one hostname belongs to exactly
     *    one affiliate across the whole system (collision rule, addendum
     *    section D).
     *  - `provider` / `provider_ref` describe the frontend host that
     *    actually serves the certificate + routing (Vercel — addendum
     *    section B). `provider_ref` is NULL for a row that is not a
     *    provider-managed custom domain (an `is_owned` brand pointed at a
     *    hostname configured elsewhere); every provider-side lifecycle
     *    step (PR-5) skips a null-`provider_ref` row (addendum section I).
     *  - `verification` holds the TXT-challenge payload when the provider
     *    needs an explicit ownership proof (rare — only when the hostname
     *    is already attached elsewhere on the provider).
     *
     * The primary affiliate's own hostnames are NOT rows here — they are
     * deploy config (`STOREFRONT_PRIMARY_HOSTS`, resolved by
     * ResolveStorefrontBrand before it ever queries this table). "Our own
     * infra hostnames are config; customer domains are data" (PR-3
     * addendum). This table only ever holds non-primary brands' domains.
     */
    public function up(): void
    {
        Schema::create('affiliate_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->string('hostname')->unique();
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('pending');
            $table->string('provider')->default('vercel');
            $table->string('provider_ref')->nullable();
            $table->json('verification')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            // backend/AGENTS.md standalone-index gotcha: `hostname`'s
            // unique index does not cover the `affiliate_id` FK, and
            // `constrained()` has been found not to reliably leave one
            // of its own on MySQL.
            $table->index('affiliate_id', 'affiliate_domains_affiliate_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_domains');
    }
};
