<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ADR-060 second 2026-10-30 addendum, decision 2: a `www` redirect twin is
     * a row whose `redirects_to_domain_id` names the domain it redirects to. A
     * twin is never served, canonical, primary, CORS-allowed or
     * payment-approved. Additive and nullable: every existing row is an
     * ordinary domain. The index is explicit (backend/AGENTS.md: a foreign key
     * has been found not to reliably leave its own on MySQL), and it goes
     * before the constraint so MySQL reuses it.
     */
    public function up(): void
    {
        Schema::table('affiliate_domains', function (Blueprint $table) {
            $table->unsignedBigInteger('redirects_to_domain_id')->nullable()->after('is_primary');
            $table->index('redirects_to_domain_id', 'affiliate_domains_redirects_to_index');
            $table->foreign('redirects_to_domain_id', 'affiliate_domains_redirects_to_foreign')
                ->references('id')->on('affiliate_domains')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_domains', function (Blueprint $table) {
            $table->dropForeign('affiliate_domains_redirects_to_foreign');
            $table->dropIndex('affiliate_domains_redirects_to_index');
            $table->dropColumn('redirects_to_domain_id');
        });
    }
};
