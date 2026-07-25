<?php

namespace Tests\Feature\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\Providers\AcidGameShopValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AcidGameShopValidatorTest extends TestCase
{
    private function validator(): AcidGameShopValidator
    {
        return new AcidGameShopValidator(baseUrl: 'https://acidgameshop.com');
    }

    public function test_normalizes_a_valid_response_and_decodes_the_flag_emoji(): void
    {
        Http::fake([
            'acidgameshop.com/*' => Http::response([
                'nickname' => 'Prime.',
                'country' => 'Malaysia 🇲🇾',
            ], 200),
        ]);

        $result = $this->validator()->validate('51049607', '2005');

        $this->assertTrue($result->valid);
        $this->assertSame('Prime.', $result->nickname);
        $this->assertSame('MY', $result->countryCode);
        $this->assertSame('acidgameshop', $result->provider);
    }

    public function test_sends_the_expected_request_shape(): void
    {
        Http::fake([
            'acidgameshop.com/*' => Http::response(['nickname' => 'Prime.', 'country' => 'Malaysia 🇲🇾'], 200),
        ]);

        $this->validator()->validate('51049607', '2005');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://acidgameshop.com/api/validate-mlbb'
                && $request['id'] === '51049607'
                && $request['server'] === '2005'
                && $request['type'] === 'ml2';
        });
    }

    public function test_returns_invalid_result_on_the_confirmed_400_error_shape(): void
    {
        Http::fake([
            'acidgameshop.com/*' => Http::response(['error' => 'Invalid ID or Server'], 400),
        ]);

        $result = $this->validator()->validate('99999999', '9999');

        $this->assertFalse($result->valid);
        $this->assertSame('acidgameshop', $result->provider);
    }

    public function test_throws_provider_unavailable_on_unexpected_http_status(): void
    {
        Http::fake([
            'acidgameshop.com/*' => Http::response('Service Unavailable', 503),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('51049607', '2005');
    }

    public function test_throws_provider_unavailable_when_nickname_missing_on_a_200(): void
    {
        Http::fake([
            'acidgameshop.com/*' => Http::response(['something' => 'unexpected'], 200),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('51049607', '2005');
    }
}
