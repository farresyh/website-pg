<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Models\Game;
use App\Services\Affiliate\AffiliateDomainStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-140 PR 1 (meta strategy), backend half:
 * decision 4 — the index summary carries `no_index` so the sitemap can leave
 * a noindex game out, and the branding payload says which brand is primary
 * (Organization `alternateName` is primary-only);
 * decision 5, release 1 — `seo_title_local`, `seo_description_local` and
 * `seo_keywords` are no longer read or written (the columns are dropped by
 * 2026_11_16_100000, see Adr140DropDeadSeoColumnsTest).
 */
class Adr140SeoMetaStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['services.storefront.url' => 'https://pekangame.com']);

        AffiliateBranding::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'store_name' => 'PekanGame',
        ]);
    }

    private function game(string $slug, array $attributes = []): Game
    {
        return Game::query()->create(array_merge([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'is_active' => true,
        ], $attributes));
    }

    public function test_the_index_summary_carries_no_index_per_game(): void
    {
        $this->game('visible');
        $this->game('hidden-from-google', ['no_index' => true]);

        $rows = collect($this->getJson('/api/catalog/games')->assertOk()->json())->keyBy('slug');

        $this->assertFalse($rows['visible']['no_index']);
        $this->assertTrue($rows['hidden-from-google']['no_index']);
    }

    public function test_the_game_detail_no_longer_sends_the_removed_seo_fields(): void
    {
        $this->game('free-fire');

        $this->getJson('/api/catalog/games/free-fire')
            ->assertOk()
            ->assertJsonMissingPath('seo_title_local')
            ->assertJsonMissingPath('seo_description_local')
            ->assertJsonMissingPath('seo_keywords');
    }

    public function test_the_branding_payload_flags_only_the_platform_brand_as_primary(): void
    {
        $this->getJson('/api/catalog/branding')
            ->assertOk()
            ->assertJsonPath('is_primary', true);

        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme Resell',
            'markup_pct' => 10,
            'status' => 'active',
        ]);
        AffiliateBranding::query()->create(['affiliate_id' => $affiliate->id, 'store_name' => 'Acme']);
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id,
            'hostname' => 'acme.com',
            'status' => AffiliateDomainStatus::Active,
            'is_primary' => true,
        ]);

        $this->getJson('/api/catalog/branding', ['X-Storefront-Host' => 'acme.com'])
            ->assertOk()
            ->assertJsonPath('is_primary', false);
    }

    public function test_saving_game_seo_ignores_the_removed_fields(): void
    {
        $game = $this->game('valorant');
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));

        $this->putJson("/api/seo/games/{$game->id}", [
            'seo_title' => 'Top Up Valorant',
            'seo_title_local' => 'old local title',
            'seo_description_local' => 'old local description',
            'seo_keywords' => 'old, keywords',
        ])->assertOk();

        $game->refresh();
        $this->assertSame('Top Up Valorant', $game->seo_title);
        $this->assertNull($game->seo_title_local);
        $this->assertNull($game->seo_description_local);
        $this->assertNull($game->seo_keywords);
    }
}
