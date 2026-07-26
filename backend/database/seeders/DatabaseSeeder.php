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

        // PRD §8: exactly one Reseller row for the platform owner's own
        // internal storefront (markup_pct=0) — see the
        // create_resellers_table migration's doc comment for why this
        // isn't a separate "no reseller yet" special case.
        Reseller::query()->firstOrCreate(
            ['business_name' => 'Platform Owner'],
            ['markup_pct' => 0, 'status' => 'active'],
        );

        $this->call(PaymentMethodSeeder::class);

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
