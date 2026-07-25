<?php

namespace Tests\Feature\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\Providers\NexoneValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NexoneValidatorTest extends TestCase
{
    private const TOKEN_PAGE_HTML = <<<'HTML'
        <html><body>
        <form>
        <input type="hidden" name="checkToken" id="checkToken" value="abc123def456">
        </form>
        </body></html>
        HTML;

    private function validator(): NexoneValidator
    {
        return new NexoneValidator(baseUrl: 'https://nexone.ph');
    }

    public function test_fetches_token_then_normalizes_a_valid_response(): void
    {
        Http::fake([
            'nexone.ph/idchecker' => Http::response(self::TOKEN_PAGE_HTML, 200),
            'nexone.ph/pages/get_check_id' => Http::response([
                'success' => true,
                'title' => 'ID Found!',
                'nickname' => 'Prime.',
                'user_id' => '51049607',
                'zone_id' => '2005',
                'region' => '🇲🇾',
            ], 200),
        ]);

        $result = $this->validator()->validate('51049607', '2005');

        $this->assertTrue($result->valid);
        $this->assertSame('Prime.', $result->nickname);
        $this->assertSame('MY', $result->countryCode);
        $this->assertSame('nexone', $result->provider);
    }

    public function test_replays_the_scraped_token_on_the_check_request(): void
    {
        Http::fake([
            'nexone.ph/idchecker' => Http::response(self::TOKEN_PAGE_HTML, 200),
            'nexone.ph/pages/get_check_id' => Http::response(['success' => true, 'nickname' => 'Prime.'], 200),
        ]);

        $this->validator()->validate('51049607', '2005');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'get_check_id')
                && $request['checkToken'] === 'abc123def456'
                && $request['userId'] === '51049607'
                && $request['zoneId'] === '2005';
        });
    }

    public function test_returns_invalid_result_on_the_confirmed_not_found_shape(): void
    {
        Http::fake([
            'nexone.ph/idchecker' => Http::response(self::TOKEN_PAGE_HTML, 200),
            'nexone.ph/pages/get_check_id' => Http::response([
                'success' => false,
                'title' => 'ID Not Found!',
                'message' => 'ID not found.',
            ], 200),
        ]);

        $result = $this->validator()->validate('99999999', '9999');

        $this->assertFalse($result->valid);
        $this->assertSame('nexone', $result->provider);
    }

    public function test_throws_provider_unavailable_when_token_page_has_no_token(): void
    {
        Http::fake([
            'nexone.ph/idchecker' => Http::response('<html><body>no token here</body></html>', 200),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('51049607', '2005');
    }

    public function test_throws_provider_unavailable_when_token_page_is_unreachable(): void
    {
        Http::fake([
            'nexone.ph/idchecker' => Http::response('Service Unavailable', 503),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('51049607', '2005');
    }
}
