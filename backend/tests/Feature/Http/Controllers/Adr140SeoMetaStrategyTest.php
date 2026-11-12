<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Models\Game;
use App\Services\Affiliate\AffiliateDomainStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-140 PR 1 (meta strategy), backend half:
 * decision 4 — the index summary carries `no_index` so the sitemap can leave
 * a noindex game out, and the branding payload says which brand is primary
 * (Organization `alternateName` is primary-only);
 * decision 5, release 1 — `seo_title_local`, `seo_description_local` and
 * `seo_keywords` are no longer sent. Their columns are gone since
 * 2026_11_16_100000 (Adr140DropDeadSeoColumnsTest), so no save can write them.
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
}
