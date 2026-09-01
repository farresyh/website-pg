<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\HeroSlide;
use App\Models\Reseller;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        AdminUser::factory()->superAdmin()->create([
            'name' => 'Test Super Admin',
            'email' => 'test@example.com',
        ]);

        // ADR-061: the platform's own storefront is a Reseller row like
        // any other — `is_owned` (our brand) + `is_primary` (the single
        // console/job/migration fallback tenant, never deletable).
        // markup_pct=0 so all its margin books as platform_profit.
        Reseller::query()->updateOrCreate(
            ['is_primary' => true],
            [
                'business_name' => 'PekanGame', // ADR-062
                'markup_pct' => 0,
                'status' => 'active',
                'is_owned' => true,
            ],
        );

        $this->call(PaymentMethodSeeder::class);
        $this->call(CrawlerRuleSeeder::class);

        // A fresh install must never show an empty homepage hero
        // section — one generic default slide, matching the original
        // storefront placeholder's copy (docs/prd.md §14/§15 backlog).
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
    }
}
