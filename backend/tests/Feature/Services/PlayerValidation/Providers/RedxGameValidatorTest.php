<?php

namespace Tests\Feature\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\Providers\RedxGameValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedxGameValidatorTest extends TestCase
{
    private const CHECKER_PAGE = '<html><head><meta name="csrf-token" content="tok-1"></head><body></body></html>';

    private function validator(): RedxGameValidator
    {
        return new RedxGameValidator(baseUrl: 'https://redxgame.com');
    }

    private function checkerPage(string $token = 'tok-1'): PromiseInterface
    {
        return Http::response(
            str_replace('tok-1', $token, self::CHECKER_PAGE),
            200,
            ['Set-Cookie' => 'redx_game_session=sess-'.$token.'; path=/; domain=redxgame.com; secure; httponly'],
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(RedxGameValidator::SESSION_CACHE_KEY);
    }

    public function test_normalizes_a_valid_response_and_maps_the_country_name(): void
    {
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response(['status' => true, 'nickname' => 'Prime.', 'region' => 'Malaysia']),
        ]);

        $result = $this->validator()->validate('51049607', '2005');

        $this->assertTrue($result->valid);
        $this->assertSame('Prime.', $result->nickname);
        $this->assertSame('MY', $result->countryCode);
        $this->assertSame('redxgame', $result->provider);
    }

    public function test_sends_the_csrf_token_session_cookie_and_expected_body(): void
    {
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response(['status' => true, 'nickname' => 'Prime.', 'region' => 'Malaysia']),
        ]);

        $this->validator()->validate('51049607', '2005');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://redxgame.com/tools/check'
            && $request->method() === 'POST'
            && $request->header('X-CSRF-TOKEN') === ['tok-1']
            && str_contains($request->header('Cookie')[0] ?? '', 'redx_game_session=sess-tok-1')
            && $request['game'] === 'mobile-legends'
            && $request['inputs'] === ['user_id' => '51049607', 'server_id' => '2005']);
    }

    public function test_reuses_the_cached_session_instead_of_a_new_handshake_per_check(): void
    {
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response(['status' => true, 'nickname' => 'Prime.', 'region' => 'Malaysia']),
        ]);

        $this->validator()->validate('51049607', '2005');
        $this->validator()->validate('51049607', '2005');

        $handshakes = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'GET');
        $this->assertCount(1, $handshakes);
    }

    private function cacheExpiredSession(): void
    {
        Cache::put(RedxGameValidator::SESSION_CACHE_KEY, ['cookies' => [], 'token' => 'stale'], 3600);
    }

    public function test_an_expired_cached_session_is_renewed_once_and_the_check_retried(): void
    {
        $this->cacheExpiredSession();
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => $this->checkerPage('tok-2'),
            'redxgame.com/tools/check' => Http::sequence()
                ->push(['message' => 'CSRF token mismatch.'], 419)
                ->push(['status' => true, 'nickname' => 'Prime.', 'region' => 'Malaysia']),
        ]);

        $result = $this->validator()->validate('51049607', '2005');

        $this->assertSame('MY', $result->countryCode);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://redxgame.com/tools/check'
            && $request->header('X-CSRF-TOKEN') === ['tok-2']);
        $this->assertSame('tok-2', Cache::get(RedxGameValidator::SESSION_CACHE_KEY)['token']);
    }

    public function test_a_redirect_instead_of_json_is_treated_as_an_expired_session(): void
    {
        $this->cacheExpiredSession();
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::sequence()
                ->push('<html><meta http-equiv="refresh" content="0;url=\'https://redxgame.com\'"></html>', 200)
                ->push(['status' => true, 'nickname' => 'Prime.', 'region' => 'Malaysia']),
        ]);

        $this->assertSame('MY', $this->validator()->validate('51049607', '2005')->countryCode);
    }

    public function test_a_freshly_started_session_that_is_rejected_is_not_retried(): void
    {
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response(['message' => 'CSRF token mismatch.'], 419),
        ]);

        try {
            $this->validator()->validate('51049607', '2005');
            $this->fail('expected ProviderUnavailableException');
        } catch (ProviderUnavailableException) {
        }

        Http::assertSentCount(2);
        $this->assertNull(Cache::get(RedxGameValidator::SESSION_CACHE_KEY));
    }

    public function test_gives_up_when_the_renewed_session_is_rejected_too(): void
    {
        $this->cacheExpiredSession();
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response(['message' => 'CSRF token mismatch.'], 419),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('51049607', '2005');
    }

    /**
     * Founder's call 2026-10-16: Redx's "Couldn't verify that ID" is the
     * same message for a wrong ID and for a problem on Redx's own side,
     * so it is never trusted as "invalid" — MooGold, next in the chain,
     * decides.
     */
    public function test_status_false_is_unavailable_not_invalid(): void
    {
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response(['status' => false, 'message' => "Couldn't verify that ID. Please double-check and try again."]),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('99999999', '9999');
    }

    public function test_throws_provider_unavailable_on_a_server_error(): void
    {
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response('Bad Gateway', 502),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('51049607', '2005');
    }

    public function test_throws_provider_unavailable_when_the_checker_page_has_no_csrf_token(): void
    {
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => Http::response('<html>Attention Required! | Cloudflare</html>', 403),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('51049607', '2005');
    }

    public function test_an_unrecognised_region_name_is_valid_with_no_country(): void
    {
        Http::fake([
            'redxgame.com/tools/mobile-legends-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response(['status' => true, 'nickname' => 'Prime.', 'region' => 'Atlantis']),
        ]);

        $result = $this->validator()->validate('51049607', '2005');

        $this->assertTrue($result->valid);
        $this->assertNull($result->countryCode);
    }

    private function freeFireValidator(): RedxGameValidator
    {
        return new RedxGameValidator(baseUrl: 'https://redxgame.com', game: 'free-fire', statusFalseMeansInvalid: true);
    }

    public function test_free_fire_sends_the_player_id_only(): void
    {
        Http::fake([
            'redxgame.com/tools/free-fire-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response(['status' => true, 'nickname' => 'Ace', 'region' => 'Thailand']),
        ]);

        $result = $this->freeFireValidator()->validate('16742877347', null);

        $this->assertSame('TH', $result->countryCode);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://redxgame.com/tools/check'
            && $request['game'] === 'free-fire'
            && $request['inputs'] === ['user_id' => '16742877347']);
    }

    /**
     * Founder's call 2026-10-16: Free Fire has no second provider, so
     * Redx's "Couldn't verify that ID" is shown as an invalid ID the
     * customer can correct, not region unknown.
     */
    public function test_free_fire_treats_status_false_as_invalid(): void
    {
        Http::fake([
            'redxgame.com/tools/free-fire-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response(['status' => false, 'message' => "Couldn't verify that ID. Please double-check and try again."]),
        ]);

        $result = $this->freeFireValidator()->validate('99999999', null);

        $this->assertFalse($result->valid);
        $this->assertSame('redxgame', $result->provider);
    }

    public function test_free_fire_still_throws_when_redx_is_down(): void
    {
        Http::fake([
            'redxgame.com/tools/free-fire-id-checker' => $this->checkerPage(),
            'redxgame.com/tools/check' => Http::response('Bad Gateway', 502),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->freeFireValidator()->validate('16742877347', null);
    }
}
