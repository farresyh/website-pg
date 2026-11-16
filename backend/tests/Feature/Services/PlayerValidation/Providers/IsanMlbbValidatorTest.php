<?php

namespace Tests\Feature\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\PlayerValidatorRegistry;
use App\Services\PlayerValidation\Providers\IsanMlbbValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IsanMlbbValidatorTest extends TestCase
{
    private function validator(): IsanMlbbValidator
    {
        return new IsanMlbbValidator(baseUrl: 'https://mlbb-api.isan.eu.org');
    }

    public function test_normalizes_a_found_player(): void
    {
        // Shape seen live from the prod box, 2026-11-16.
        Http::fake(['mlbb-api.isan.eu.org/find*' => Http::response([
            'success' => true, 'id' => 51049607, 'zone' => 2005, 'name' => 'Prime.',
            'countryCode' => 'MY', 'countryName' => 'Malaysia', 'processingTime' => 16,
        ])]);

        $result = $this->validator()->validate('51049607', '2005');

        $this->assertTrue($result->valid);
        $this->assertSame('Prime.', $result->nickname);
        $this->assertSame('MY', $result->countryCode);
        $this->assertSame('isan', $result->provider);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://mlbb-api.isan.eu.org/find')
            && $r['id'] === '51049607' && $r['zone'] === '2005');
    }

    public function test_not_found_is_an_invalid_id(): void
    {
        Http::fake(['mlbb-api.isan.eu.org/find*' => Http::response(['success' => false, 'message' => 'Not found'], 404)]);

        $result = $this->validator()->validate('1', '1');

        $this->assertFalse($result->valid);
        $this->assertSame('isan', $result->provider);
    }

    public function test_a_missing_zone_is_an_invalid_id_without_a_call(): void
    {
        Http::fake();

        $this->assertFalse($this->validator()->validate('51049607', null)->valid);
        Http::assertNothingSent();
    }

    /** A WAF block, a server error, or a 404 that isn't the "not found" body must never read as invalid. */
    public function test_anything_else_is_unavailable_not_invalid(): void
    {
        Http::fake(['mlbb-api.isan.eu.org/find*' => Http::sequence()
            ->push('boom', 500)
            ->push('rate limited', 429)
            ->push('<html>blocked</html>', 404)
            ->push(['success' => true], 200)]);

        foreach (range(1, 4) as $_) {
            try {
                $this->validator()->validate('51049607', '2005');
                $this->fail('Expected ProviderUnavailableException');
            } catch (ProviderUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** ADR-005 2026-11-16: the bound MLBB chain (checkout and the public checker) asks isan first, Redx only when isan is unavailable. */
    public function test_the_bound_mlbb_chains_ask_isan_first(): void
    {
        Http::fake([
            'mlbb-api.isan.eu.org/find*' => Http::response(['success' => true, 'name' => 'Prime.', 'countryCode' => 'MY']),
            '*' => Http::response('unexpected', 500),
        ]);
        $registry = app(PlayerValidatorRegistry::class);

        $this->assertSame('isan', $registry->resolve('mlbb')->validate('51049607', '2005')->provider);
        $this->assertSame('isan', $registry->resolvePublic('mlbb')->validate('51049607', '2005')->provider);
        Http::assertSentCount(2);
    }

    public function test_a_connection_failure_is_unavailable(): void
    {
        Http::fake(['mlbb-api.isan.eu.org/find*' => Http::failedConnection()]);

        $this->expectException(ProviderUnavailableException::class);

        $this->validator()->validate('51049607', '2005');
    }
}
