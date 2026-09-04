<?php

namespace Database\Seeders;

use App\Models\Affiliate;
use App\Models\HeroSlide;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * First-boot seed for a fresh production database (deploy-wiring, ADR-020).
 *
 * Mirrors {@see DatabaseSeeder} but omits everything test-only: no
 * `test@example.com` super admin, no factories (the prod image is built
 * `composer install --no-dev`, so fakerphp/faker is absent). Every write
 * is idempotent — safe to re-run.
 *
 * The first real super admin is created by the `app:create-admin`
 * command (ADR-066 cutover addendum), which reads
 * `config('admin.seed_email|seed_password')` — env resolved inside a
 * config file so `config:cache` bakes it in. Skipped when either is
 * unset or the address already exists.
 */
class ProductionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ADR-061: the platform's own storefront is an Affiliate row like
        // any other — is_owned (our brand) + is_primary (the single
        // console/job/migration fallback tenant). markup_pct=0 so all its
        // margin books as platform_profit.
        $affiliate = Affiliate::query()->updateOrCreate(
            ['is_primary' => true],
            [
                'business_name' => 'PekanGame', // ADR-062
                'markup_pct' => 0,
                'status' => 'active',
                'is_owned' => true,
            ],
        );

        // Explicit branding row so the storefront never falls back to the
        // internal business_name (ADR-028/062). Same shape the ADR-062
        // rename migration writes; the founder edits copy / footer / legal
        // through /admin after launch (ADR-062 decision 7).
        if (! DB::table('affiliate_branding')->where('affiliate_id', $affiliate->id)->exists()) {
            DB::table('affiliate_branding')->insert([
                'affiliate_id' => $affiliate->id,
                'store_name' => 'PekanGame',
                'description' => 'Fast, secure game top-ups delivered in minutes.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->call(PaymentMethodSeeder::class);
        $this->call(CrawlerRuleSeeder::class);

        // A fresh install must never show an empty homepage hero section —
        // one generic default slide (docs/prd.md §14/§15 backlog).
        HeroSlide::query()->firstOrCreate(
            ['title' => 'Top up your favorite games in seconds'],
            [
                'eyebrow' => 'Top Up Made Easy',
                'description' => 'Pick your game, place your order, and pay your way — the fastest top-up experience in Malaysia.',
                'primary_cta_label' => 'Find Games',
                'primary_cta_href' => '#popular-picks',
                'secondary_cta_label' => 'Track Order',
                'secondary_cta_href' => '/track-order',
                'is_active' => true,
                'sort_order' => 0,
            ],
        );

        // ADR-066 cutover addendum: the super admin is created by
        // app:create-admin, which reads config (not runtime env), so a
        // reseed after `config:cache` no longer silently skips it.
        Artisan::call('app:create-admin', [], $this->command?->getOutput());
    }
}
