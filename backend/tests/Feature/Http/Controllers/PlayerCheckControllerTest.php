<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateGame;
use App\Models\Game;
use App\Models\PlayerValidation;
use App\Models\PlayerValidatorProfile;
use App\Models\PublicPlayerCheck;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** ADR-123: the public Game ID Checker. */
class PlayerCheckControllerTest extends TestCase
{
    use RefreshDatabase;

    private \ArrayObject $providerCalls;

    private bool $turnstilePasses = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->primaryAffiliate();
        Cache::flush();
        config(['services.turnstile.secret_key' => 'test-secret']);
        $this->providerCalls = new \ArrayObject;
        Http::fake(['challenges.cloudflare.com/*' => fn () => Http::response(['success' => $this->turnstilePasses])]);

        // Checkout's chain must never be used by the public checker.
        $this->app->bind('player-validator.mlbb', fn () => throw new \LogicException('checkout chain used by the public checker'));
    }

    private function bindPublic(string $key, PlayerValidationResult|\Throwable $outcome): void
    {
        $calls = $this->providerCalls;
        $this->app->bind("player-validator.public.{$key}", fn () => new class($outcome, $calls) implements PlayerValidator
        {
            public function __construct(private readonly PlayerValidationResult|\Throwable $outcome, private readonly \ArrayObject $calls) {}

            public function validate(string $playerId, ?string $serverId): PlayerValidationResult
            {
                $this->calls[] = $playerId;

                if ($this->outcome instanceof \Throwable) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        });
    }

    private function mlbb(): array
    {
        $profile = PlayerValidatorProfile::query()->create([
            'name' => 'Mobile Legends Validator', 'key' => 'mlbb',
            'public_name' => 'Mobile Legends', 'public_slug' => 'mobile-legends',
        ]);
        $my = $this->store($profile, 'Mobile Legends Malaysia', ['extra_field' => 'server_id']);
        $id = $this->store($profile, 'Mobile Legends Indonesia', ['extra_field' => 'server_id']);
        $profile->mappings()->create(['country_code' => 'MY', 'country_name' => 'Malaysia', 'game_id' => $my->id]);
        $profile->mappings()->create(['country_code' => 'ID', 'country_name' => 'Indonesia', 'game_id' => $id->id]);

        return [$profile, $my, $id];
    }

    private function store(PlayerValidatorProfile $profile, string $name, array $rules): Game
    {
        return Game::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'is_active' => true,
            'player_validator_enabled' => true,
            'player_validator_profile_id' => $profile->id,
            'validation_rules' => $rules,
        ]);
    }

    private function check(string $slug, array $payload, array $headers = [])
    {
        return $this->postJson("/api/player-check/{$slug}", $payload + ['turnstile_token' => 'tok'], $headers);
    }

    public function test_found_names_our_store_and_never_the_provider_or_its_country(): void
    {
        [, $my] = $this->mlbb();
        $this->bindPublic('mlbb', PlayerValidationResult::valid('redxgame', 'Tuosen', 'MY'));

        $response = $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])->assertOk();

        $response->assertExactJson([
            'status' => 'found',
            'nickname' => 'Tuosen',
            'zone' => null,
            'stores' => [['slug' => $my->slug, 'name' => 'Mobile Legends Malaysia']],
            'player_id' => '51049607',
            'server_id' => '2005',
        ]);
        $this->assertSame(1, PublicPlayerCheck::query()->where('status', 'found')->where('matched_game_id', $my->id)->count());
    }

    public function test_a_public_check_never_writes_the_table_that_approves_checkout(): void
    {
        $this->mlbb();
        $this->bindPublic('mlbb', PlayerValidationResult::valid('redxgame', 'Tuosen', 'MY'));

        $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])->assertOk();

        $this->assertSame(0, PlayerValidation::query()->count());
    }

    public function test_fails_closed_without_a_turnstile_secret(): void
    {
        $this->mlbb();
        $this->bindPublic('mlbb', PlayerValidationResult::valid('redxgame', 'Tuosen', 'MY'));
        config(['services.turnstile.secret_key' => null]);

        $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])
            ->assertStatus(503)->assertJsonPath('reason', 'checker_unavailable');
        $this->assertCount(0, $this->providerCalls);
    }

    public function test_rejects_a_failed_turnstile_token_before_any_provider_call(): void
    {
        $this->mlbb();
        $this->bindPublic('mlbb', PlayerValidationResult::valid('redxgame', 'Tuosen', 'MY'));
        $this->turnstilePasses = false;

        $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])
            ->assertUnprocessable()->assertJsonPath('reason', 'turnstile_failed');
        $this->assertCount(0, $this->providerCalls);
        $this->assertSame(0, PublicPlayerCheck::query()->count());
    }

    public function test_rejects_a_malformed_id_before_any_provider_call(): void
    {
        $this->mlbb();
        $this->bindPublic('mlbb', PlayerValidationResult::valid('redxgame', 'Tuosen', 'MY'));

        $this->check('mobile-legends', ['player_id' => '5104 9607', 'server_id' => '2005'])
            ->assertUnprocessable()->assertJsonValidationErrors('player_id');
        $this->check('mobile-legends', ['player_id' => '51049607'])
            ->assertUnprocessable()->assertJsonValidationErrors('server_id');
        $this->assertCount(0, $this->providerCalls);
    }

    public function test_a_repeat_check_is_served_from_the_cache(): void
    {
        $this->mlbb();
        $this->bindPublic('mlbb', PlayerValidationResult::valid('redxgame', 'Tuosen', 'MY'));

        $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])->assertOk();
        $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])->assertOk()->assertJsonPath('nickname', 'Tuosen');

        $this->assertCount(1, $this->providerCalls);
        $this->assertSame([false, true], PublicPlayerCheck::query()->orderBy('id')->pluck('cache_hit')->all());
    }

    public function test_a_provider_failure_answers_busy_and_cools_down_without_caching(): void
    {
        $this->mlbb();
        $this->bindPublic('mlbb', new ProviderUnavailableException('Redx HTTP 429'));

        $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])->assertOk()->assertJsonPath('status', 'busy');
        $this->check('mobile-legends', ['player_id' => '12345678', 'server_id' => '2005'])->assertOk()->assertJsonPath('status', 'busy');

        $this->assertCount(1, $this->providerCalls);
        $this->assertFalse(Cache::has('player-check:result:1:'.sha1('51049607|2005')));
    }

    public function test_a_spent_hourly_budget_answers_busy_and_alerts_once_a_day(): void
    {
        $this->mlbb();
        $this->bindPublic('mlbb', PlayerValidationResult::valid('redxgame', 'Tuosen', 'MY'));
        config(['services.player_check.hourly_budget' => 1]);
        $alerter = $this->mock(BackupFailureAlerter::class);
        $alerter->shouldReceive('alert')->once()->withArgs(fn ($context, $message, $area) => $area === 'Game ID Checker');

        $this->check('mobile-legends', ['player_id' => '11111111', 'server_id' => '2005'])->assertJsonPath('status', 'found');
        $this->check('mobile-legends', ['player_id' => '22222222', 'server_id' => '2005'])->assertJsonPath('status', 'busy');
        $this->check('mobile-legends', ['player_id' => '33333333', 'server_id' => '2005'])->assertJsonPath('status', 'busy');
        // A cached ID costs no budget.
        $this->check('mobile-legends', ['player_id' => '11111111', 'server_id' => '2005'])->assertJsonPath('status', 'found');

        $this->assertCount(1, $this->providerCalls);
    }

    public function test_not_found_and_unsupported_region(): void
    {
        $this->mlbb();
        $this->bindPublic('mlbb', PlayerValidationResult::invalid('redxgame'));
        $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])
            ->assertJsonPath('status', 'not_found')->assertJsonPath('nickname', null);

        $this->bindPublic('mlbb', PlayerValidationResult::valid('redxgame', 'Tuosen', 'BR'));
        $this->check('mobile-legends', ['player_id' => '99999999', 'server_id' => '2005'])
            ->assertJsonPath('status', 'unsupported_region')->assertJsonPath('nickname', 'Tuosen')->assertJsonPath('stores', []);
    }

    public function test_a_store_the_brand_hides_answers_unavailable_here(): void
    {
        [, , $id] = $this->mlbb();
        AffiliateGame::query()->create(['affiliate_id' => $this->primaryAffiliate()->id, 'game_id' => $id->id, 'is_visible' => false]);
        $this->bindPublic('mlbb', PlayerValidationResult::valid('redxgame', 'Budi', 'ID'));

        $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])
            ->assertJsonPath('status', 'unavailable_here')->assertJsonPath('stores', []);
    }

    public function test_a_zone_game_takes_the_uid_alone_and_prefills_the_zone(): void
    {
        $profile = PlayerValidatorProfile::query()->create([
            'name' => 'Genshin Impact Validator', 'key' => 'genshin',
            'public_name' => 'Genshin Impact', 'public_slug' => 'genshin-impact',
        ]);
        $store = $this->store($profile, 'Genshin Impact Malaysia', ['extra_field' => 'zone_id', 'zone_options' => ['Asia', 'America', 'Europe']]);
        $this->bindPublic('genshin', PlayerValidationResult::valid('redxgame', 'Feets.', null, 'Americas', 'America'));

        $this->check('genshin-impact', ['player_id' => '800000001'])->assertOk()->assertExactJson([
            'status' => 'found',
            'nickname' => 'Feets.',
            'zone' => 'America',
            'stores' => [['slug' => $store->slug, 'name' => 'Genshin Impact Malaysia']],
            'player_id' => '800000001',
            'server_id' => 'America',
        ]);
    }

    public function test_families_lists_only_complete_families_and_marks_the_primary_brand_indexable(): void
    {
        $this->mlbb();
        // No slug: not listed.
        $ff = PlayerValidatorProfile::query()->create(['name' => 'Free Fire Validator', 'key' => 'freefire']);
        $this->store($ff, 'Free Fire Malaysia', ['extra_field' => null]);
        // Stores disagree on input shape: hidden.
        $hsr = PlayerValidatorProfile::query()->create(['name' => 'HSR', 'key' => 'hsr', 'public_name' => 'Honkai: Star Rail', 'public_slug' => 'honkai-star-rail']);
        $this->store($hsr, 'HSR Malaysia', ['extra_field' => 'zone_id', 'zone_options' => ['Asia']]);
        $this->store($hsr, 'HSR Global', ['extra_field' => null]);
        // No active store: hidden.
        PlayerValidatorProfile::query()->create(['name' => 'ZZZ', 'key' => 'zzz', 'public_name' => 'Zenless Zone Zero', 'public_slug' => 'zenless-zone-zero']);

        $this->getJson('/api/catalog/player-check/families')->assertOk()->assertExactJson([
            'indexable' => true,
            'families' => [[
                'slug' => 'mobile-legends', 'name' => 'Mobile Legends', 'image_url' => null,
                'player_id_format' => 'numeric', 'requires_server_id' => true,
            ]],
        ]);
        $this->getJson('/api/catalog/player-check/families/honkai-star-rail')->assertNotFound();
        $this->check('honkai-star-rail', ['player_id' => '800000001'])->assertNotFound();
    }

    public function test_an_affiliate_brand_works_but_is_not_indexable(): void
    {
        $this->mlbb();
        $brand = Affiliate::query()->create(['business_name' => 'Soloz Store', 'markup_pct' => 5, 'status' => 'active', 'is_owned' => false]);
        AffiliateDomain::query()->create([
            'affiliate_id' => $brand->id, 'hostname' => 'solozstore.my',
            'status' => AffiliateDomainStatus::Active, 'is_primary' => true,
        ]);

        $this->getJson('/api/catalog/player-check/families', ['X-Storefront-Host' => 'solozstore.my'])
            ->assertOk()->assertJsonPath('indexable', false)->assertJsonCount(1, 'families');
    }

    public function test_per_ip_limit_is_five_a_minute(): void
    {
        $this->mlbb();
        $this->bindPublic('mlbb', PlayerValidationResult::valid('redxgame', 'Tuosen', 'MY'));

        foreach (range(1, 5) as $i) {
            $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])->assertOk();
        }

        $this->check('mobile-legends', ['player_id' => '51049607', 'server_id' => '2005'])->assertStatus(429);
    }
}
