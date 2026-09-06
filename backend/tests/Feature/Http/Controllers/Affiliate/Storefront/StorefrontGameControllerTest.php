<?php

namespace Tests\Feature\Http\Controllers\Affiliate\Storefront;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateGame;
use App\Models\AffiliateUser;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-060 PR-6 — the Catalog tab (per-brand game visibility).
 *
 * Each test makes at most one authenticated portal request: the test
 * kernel caches the resolved guard user across sequential requests, so a
 * second `withToken()` as a different affiliate would still see the
 * first (same reason the other Affiliate portal tests each act as one
 * token per method).
 */
class StorefrontGameControllerTest extends TestCase
{
    use RefreshDatabase;

    private function affiliate(): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => 'Acme Resell',
            'email' => 'owner+'.uniqid().'@acme.test',
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    private function tokenFor(Affiliate $affiliate): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'name' => 'Staff',
            'email' => 'staff+'.uniqid().'@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    private function game(string $name): Game
    {
        return Game::query()->create(['name' => $name, 'slug' => Str::slug($name), 'is_active' => true]);
    }

    private function hide(Affiliate $affiliate, Game $game): void
    {
        AffiliateGame::withoutAffiliateScope()->create([
            'affiliate_id' => $affiliate->id, 'game_id' => $game->id, 'is_visible' => false,
        ]);
    }

    public function test_toggling_a_game_off_writes_a_row_and_hides_it_from_that_storefront(): void
    {
        $this->primaryAffiliate();
        $brand = $this->affiliate();
        AffiliateDomain::query()->create(['affiliate_id' => $brand->id, 'hostname' => 'shop.acme.test', 'status' => 'active']);
        $this->game('Mobile Legends');
        $ff = $this->game('Free Fire');

        $this->withToken($this->tokenFor($brand))
            ->putJson("/api/affiliate/storefront/games/{$ff->id}", ['is_visible' => false])
            ->assertOk();

        $this->assertDatabaseHas('affiliate_game', [
            'affiliate_id' => $brand->id, 'game_id' => $ff->id, 'is_visible' => false,
        ]);

        $names = $this->getJson('/api/catalog/games', ['X-Storefront-Host' => 'shop.acme.test'])
            ->assertOk()->json('*.name');
        $this->assertSame(['Mobile Legends'], $names);
    }

    public function test_the_primary_storefront_ignores_another_brands_toggle(): void
    {
        $this->primaryAffiliate();
        $brand = $this->affiliate();
        $ml = $this->game('Mobile Legends');
        $this->game('Free Fire');
        $this->hide($brand, $ml);

        $this->getJson('/api/catalog/games')->assertOk()->assertJsonCount(2);
    }

    public function test_a_hidden_game_stays_visible_for_another_brand_in_the_portal(): void
    {
        $other = $this->affiliate();
        $mine = $this->affiliate();
        $ml = $this->game('Mobile Legends');
        $this->game('Free Fire');
        $this->hide($other, $ml);

        $games = $this->withToken($this->tokenFor($mine))
            ->getJson('/api/affiliate/storefront/games')->assertOk()->json('games');

        $this->assertTrue(collect($games)->firstWhere('id', $ml->id)['is_visible']);
    }

    public function test_the_last_visible_game_cannot_be_turned_off(): void
    {
        $brand = $this->affiliate();
        $only = $this->game('Mobile Legends');

        $this->withToken($this->tokenFor($brand))
            ->putJson("/api/affiliate/storefront/games/{$only->id}", ['is_visible' => false])
            ->assertStatus(422);

        $this->assertDatabaseMissing('affiliate_game', ['affiliate_id' => $brand->id, 'game_id' => $only->id]);
    }

    public function test_a_deactivated_affiliate_cannot_toggle(): void
    {
        $brand = $this->affiliate();
        $brand->update(['status' => 'deactivated']);
        $g1 = $this->game('Mobile Legends');
        $this->game('Free Fire');

        $this->withToken($this->tokenFor($brand))
            ->putJson("/api/affiliate/storefront/games/{$g1->id}", ['is_visible' => false])
            ->assertStatus(403);
    }
}
