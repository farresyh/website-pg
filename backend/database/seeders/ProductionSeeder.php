<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\HeroSlide;
use App\Models\Reseller;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * First-boot seed for a fresh production database (deploy-wiring, ADR-020).
 *
 * Mirrors {@see DatabaseSeeder} but omits everything test-only: no
 * `test@example.com` super admin, no factories (the prod image is built
 * `composer install --no-dev`, so fakerphp/faker is absent). Every write
 * is idempotent — safe to re-run.
 *
 * The first real super admin is created from `ADMIN_EMAIL` /
 * `ADMIN_PASSWORD` in the environment (passed by the bootstrap wizard as
 * `docker compose exec -e …`, never committed). Skipped when either is
 * unset or the address already exists.
 */
class ProductionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ADR-061: the platform's own storefront is a Reseller row like
        // any other — is_owned (our brand) + is_primary (the single
        // console/job/migration fallback tenant). markup_pct=0 so all its
        // margin books as platform_profit.
        $reseller = Reseller::query()->updateOrCreate(
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
        if (! DB::table('reseller_branding')->where('reseller_id', $reseller->id)->exists()) {
            DB::table('reseller_branding')->insert([
                'reseller_id' => $reseller->id,
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

        $this->seedFirstSuperAdmin();
    }

    private function seedFirstSuperAdmin(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->command?->warn('ProductionSeeder: ADMIN_EMAIL / ADMIN_PASSWORD not set — skipping super-admin creation.');

            return;
        }

        if (AdminUser::query()->where('email', $email)->exists()) {
            $this->command?->info("ProductionSeeder: super admin {$email} already exists — skipping.");

            return;
        }

        AdminUser::create([
            'name' => is_string(env('ADMIN_NAME')) && env('ADMIN_NAME') !== '' ? env('ADMIN_NAME') : 'Admin',
            'email' => $email,
            'password' => $password, // AdminUser 'password' cast => 'hashed'
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->command?->info("ProductionSeeder: created super admin {$email}.");
    }
}
