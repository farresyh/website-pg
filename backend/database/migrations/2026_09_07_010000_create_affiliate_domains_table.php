<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
     *    section B). `provider_ref` is NULL for the seeded rows that
     *    represent the primary affiliate's own domain(s): those are the
     *    platform's real zone, not customer domains, and every
     *    provider-side lifecycle step (PR-5) skips a null-`provider_ref`
     *    row (addendum section I).
     *  - `verification` holds the TXT-challenge payload when the provider
     *    needs an explicit ownership proof (rare — only when the hostname
     *    is already attached elsewhere on the provider).
     *
     * The primary affiliate's own hostnames are seeded here from
     * `STOREFRONT_PRIMARY_HOSTS` (comma-separated) so the `Host` resolver
     * has one code path for every brand including ours. Empty in tests /
     * CI / local dev with nothing configured — the resolver falls back
     * to `Affiliate::primary()` whenever the `X-Storefront-Host` header
     * is absent, so that is harmless.
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

        $primaryId = DB::table('affiliates')->where('is_primary', true)->value('id');
        // Normalise the same way ResolveStorefrontBrand normalises the
        // incoming header: lower-case, trimmed, any `:port` stripped — so
        // a value like `localhost:3001` still matches `localhost`.
        $hosts = array_filter(array_map(
            fn ($h) => explode(':', strtolower(trim($h)), 2)[0],
            explode(',', (string) env('STOREFRONT_PRIMARY_HOSTS', '')),
        ));

        if ($primaryId !== null && $hosts !== []) {
            $now = now();
            foreach (array_values(array_unique($hosts)) as $i => $hostname) {
                DB::table('affiliate_domains')->insert([
                    'affiliate_id' => $primaryId,
                    'hostname' => $hostname,
                    'is_primary' => $i === 0,
                    'status' => 'active',
                    'provider' => 'vercel',
                    'provider_ref' => null,
                    'verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_domains');
    }
};
