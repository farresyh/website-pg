<?php

namespace Tests\Feature\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\Providers\MoogoldValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MoogoldValidatorTest extends TestCase
{
    private function validator(): MoogoldValidator
    {
        return new MoogoldValidator(baseUrl: 'https://moogold.com');
    }

    public function test_parses_nickname_and_country_out_of_the_free_text_message(): void
    {
        Http::fake([
            'moogold.com/*' => Http::response([
                'title' => 'Validation',
                'message' => "User ID: 51049607\nServer ID: 2005\nIn-Game Nickname: Prime.\nCountry: MY",
                'icon' => 'success',
                'status' => 'true',
            ], 200),
        ]);

        $result = $this->validator()->validate('51049607', '2005');

        $this->assertTrue($result->valid);
        $this->assertSame('Prime.', $result->nickname);
        $this->assertSame('MY', $result->countryCode);
        $this->assertSame('moogold', $result->provider);
    }

    /**
     * Confirmed live 2026-07-25: the same valid account sometimes
     * comes back without a Country line at all — must not crash or
     * misparse when it's missing.
     */
    public function test_handles_a_valid_response_missing_the_country_line(): void
    {
        Http::fake([
            'moogold.com/*' => Http::response([
                'title' => 'Validation',
                'message' => "User ID: 51049607\nServer ID: 2005\nIn-Game Nickname: Prime.",
                'icon' => 'success',
                'status' => 'true',
            ], 200),
        ]);

        $result = $this->validator()->validate('51049607', '2005');

        $this->assertTrue($result->valid);
        $this->assertSame('Prime.', $result->nickname);
        $this->assertNull($result->countryCode);
    }

    /**
     * Confirmed live: `icon` stays "success" even when `status` is
     * "invalid" and `message` is empty — validity must be read from
     * `status`, never `icon`.
     */
    public function test_returns_invalid_result_despite_icon_saying_success(): void
    {
        Http::fake([
            'moogold.com/*' => Http::response([
                'title' => 'Validation',
                'message' => '',
                'icon' => 'success',
                'status' => 'invalid',
            ], 200),
        ]);

        $result = $this->validator()->validate('99999999', '9999');

        $this->assertFalse($result->valid);
        $this->assertSame('moogold', $result->provider);
    }

    public function test_throws_provider_unavailable_when_status_field_missing(): void
    {
        Http::fake([
            'moogold.com/*' => Http::response(['title' => 'unexpected'], 200),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('51049607', '2005');
    }

    public function test_throws_provider_unavailable_on_unexpected_http_status(): void
    {
        Http::fake([
            'moogold.com/*' => Http::response('Service Unavailable', 503),
        ]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('51049607', '2005');
    }
}
