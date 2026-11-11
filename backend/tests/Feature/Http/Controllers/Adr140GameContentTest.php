<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateBranding;
use App\Models\AffiliateDomain;
use App\Models\Game;
use App\Services\Affiliate\AffiliateDomainStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ADR-140 decisions 6–8: a game's own content (summary, about, FAQ), reaching
 * the storefront only for a published game on the primary brand, enforced at
 * one backend seam (`Game::publishedContentFor`, read by the catalog detail and
 * the index summary). The admin writes it through one endpoint; the status
 * (empty, draft, published) is derived from the fields and `content_published_at`.
 */
class Adr140GameContentTest extends TestCase
{
    use RefreshDatabase;

    private const FAQ = [['question' => 'Macam mana cari Zone ID?', 'answer' => 'Buka profil dalam game.']];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['services.storefront.url' => 'https://pekangame.com']);
        AffiliateBranding::query()->create(['affiliate_id' => $this->primaryAffiliate()->id, 'store_name' => 'PekanGame']);
    }

    private function game(string $slug = 'valorant', array $attributes = []): Game
    {
        return Game::query()->create(array_merge(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true], $attributes));
    }

    private function contentFor(string $status): array
    {
        return $status === 'empty' ? [] : [
            'content_summary' => 'Top up Valorant Points pantas.',
            'content_about' => "Perenggan satu.\n\nPerenggan dua.",
            'content_faq' => self::FAQ,
            'content_published_at' => $status === 'published' ? '2026-11-12 03:00:00' : null,
        ];
    }

    /** @return array<string, array{string, string, bool}> */
    public static function matrix(): array
    {
        $rows = [];
        foreach (['primary', 'affiliate'] as $brand) {
            foreach (['empty', 'draft', 'published'] as $status) {
                $rows["{$brand} × {$status}"] = [$brand, $status, $brand === 'primary' && $status === 'published'];
            }
        }

        return $rows;
    }

    /** @return array<string, string> */
    private function brandHeaders(string $brand): array
    {
        if ($brand === 'primary') {
            return [];
        }

        $affiliate = Affiliate::query()->create(['business_name' => 'Acme Resell', 'markup_pct' => 10, 'status' => 'active']);
        AffiliateBranding::query()->create(['affiliate_id' => $affiliate->id, 'store_name' => 'Acme']);
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id, 'hostname' => 'acme.com', 'status' => AffiliateDomainStatus::Active, 'is_primary' => true,
        ]);

        return ['X-Storefront-Host' => 'acme.com'];
    }

    #[DataProvider('matrix')]
    public function test_content_reaches_only_a_published_game_on_the_primary_brand(string $brand, string $status, bool $expected): void
    {
        $this->game('valorant', $this->contentFor($status));
        $headers = $this->brandHeaders($brand);

        $detail = $this->getJson('/api/catalog/games/valorant', $headers)->assertOk();
        $row = collect($this->getJson('/api/catalog/games', $headers)->assertOk()->json())->firstWhere('slug', 'valorant');

        if ($expected) {
            $detail->assertJsonPath('content.summary', 'Top up Valorant Points pantas.')
                ->assertJsonPath('content.about', "Perenggan satu.\n\nPerenggan dua.")
                ->assertJsonPath('content.faq.0.question', 'Macam mana cari Zone ID?');
            $this->assertSame('Top up Valorant Points pantas.', $row['content_summary']);
            $this->assertNotNull($row['content_published_at']);

            return;
        }

        $detail->assertJsonMissingPath('content');
        $this->assertArrayNotHasKey('content_summary', $row);
        $this->assertArrayNotHasKey('content_published_at', $row);
    }

    public function test_the_status_is_derived_from_the_fields_and_the_publish_date(): void
    {
        $this->assertSame('empty', $this->game('a')->contentStatus());
        $this->assertSame('empty', $this->game('b', ['content_faq' => []])->contentStatus());
        $this->assertSame('draft', $this->game('c', $this->contentFor('draft'))->contentStatus());
        $this->assertSame('draft', $this->game('d', ['content_summary' => 'Only a summary'])->contentStatus());
        $this->assertSame('published', $this->game('e', $this->contentFor('published'))->contentStatus());
    }

    public function test_tracked_games_are_active_and_not_noindex(): void
    {
        $this->game('listed');
        $this->game('inactive', ['is_active' => false]);
        $this->game('noindex', ['no_index' => true]);

        $this->assertSame(['listed'], Game::query()->tracked()->pluck('slug')->all());
    }

    private function actingAsSuperAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin']));
    }

    private function save(Game $game, array $body): TestResponse
    {
        return $this->putJson("/api/seo/games/{$game->id}/content", array_merge(
            ['summary' => 'Ringkasan', 'about' => 'Tentang game ini.', 'faq' => self::FAQ, 'published' => false],
            $body,
        ));
    }

    public function test_saving_a_draft_stores_the_fields_and_publishes_nothing(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game();

        $this->save($game, [])->assertOk()->assertJsonPath('content_status', 'draft');

        $game->refresh();
        $this->assertSame('Ringkasan', $game->content_summary);
        $this->assertSame(self::FAQ, $game->content_faq);
        $this->assertNull($game->content_published_at);
        $this->getJson('/api/catalog/games/valorant')->assertJsonMissingPath('content');
    }

    public function test_publishing_makes_it_visible_and_a_later_save_only_moves_the_date_when_the_content_changes(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game();

        Carbon::setTestNow('2026-11-12 03:00:00');
        $this->save($game, ['published' => true])->assertOk()->assertJsonPath('content_status', 'published');
        $this->getJson('/api/catalog/games/valorant')->assertJsonPath('content.summary', 'Ringkasan');
        $this->assertSame('2026-11-12 03:00:00', $game->fresh()->content_published_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-11-13 04:00:00');
        $this->save($game, ['published' => true])->assertOk();
        $this->assertSame('2026-11-12 03:00:00', $game->fresh()->content_published_at->format('Y-m-d H:i:s'), 'same content keeps the date');

        Carbon::setTestNow('2026-11-14 05:00:00');
        $this->save($game, ['published' => true, 'summary' => 'Ringkasan baharu'])->assertOk();
        $this->assertSame('2026-11-14 05:00:00', $game->fresh()->content_published_at->format('Y-m-d H:i:s'), 'changed content moves the date');
        Carbon::setTestNow();
    }

    public function test_unpublishing_hides_it_again(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game('valorant', $this->contentFor('published'));

        $this->save($game, ['published' => false])->assertOk()->assertJsonPath('content_status', 'draft');

        $this->assertNull($game->fresh()->content_published_at);
        $this->getJson('/api/catalog/games/valorant')->assertJsonMissingPath('content');
    }

    public function test_there_is_nothing_to_publish_when_every_field_is_empty(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game();

        $this->save($game, ['summary' => null, 'about' => null, 'faq' => [], 'published' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('published');
        $this->assertNull($game->fresh()->content_published_at);
    }

    public function test_the_limits_in_the_decision_are_enforced(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game();
        $item = ['question' => 'Q?', 'answer' => 'A.'];

        $this->save($game, ['summary' => str_repeat('a', 301)])->assertJsonValidationErrors('summary');
        $this->save($game, ['about' => str_repeat('a', 2001)])->assertJsonValidationErrors('about');
        $this->save($game, ['faq' => array_fill(0, 9, $item)])->assertJsonValidationErrors('faq');
        $this->save($game, ['faq' => [['question' => str_repeat('q', 151), 'answer' => 'A.']]])->assertJsonValidationErrors('faq.0.question');
        $this->save($game, ['faq' => [['question' => 'Q?', 'answer' => str_repeat('a', 501)]]])->assertJsonValidationErrors('faq.0.answer');
        $this->save($game, ['faq' => [['question' => 'Q?']]])->assertJsonValidationErrors('faq.0.answer');
        $this->save($game, ['faq' => array_fill(0, 8, $item)])->assertOk();
    }

    public function test_only_a_super_admin_can_edit_content(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->save($this->game(), [])->assertForbidden();
    }

    public function test_a_save_purges_the_cached_catalog_index(): void
    {
        $this->actingAsSuperAdmin();
        $game = $this->game();

        $before = collect($this->getJson('/api/catalog/games')->json())->firstWhere('slug', 'valorant');
        $this->assertArrayNotHasKey('content_summary', $before);

        $this->save($game, ['published' => true])->assertOk();

        $after = collect($this->getJson('/api/catalog/games')->json())->firstWhere('slug', 'valorant');
        $this->assertSame('Ringkasan', $after['content_summary']);
    }
}
