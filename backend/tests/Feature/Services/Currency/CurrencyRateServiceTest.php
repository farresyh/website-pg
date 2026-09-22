<?php

namespace Tests\Feature\Services\Currency;

use App\Models\CurrencyRate;
use App\Services\Currency\CurrencyRateService;
use App\Services\Currency\CurrencyRateUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-033 decision 1: fetches from a keyless FX API, caches for
 * determinism/rate-limit friendliness, and falls back to the last
 * stored rate on any live failure — the sync itself must never block
 * on a flaky third-party FX API.
 */
class CurrencyRateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_fetches_live_and_stores_a_currency_rate_row(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response([
                'result' => 'success',
                'base_code' => 'IDR',
                'rates' => ['MYR' => 0.000228],
            ], 200),
        ]);

        $rate = (new CurrencyRateService)->rate('IDR', 'MYR');

        $this->assertSame(0.000228, $rate);
        $row = CurrencyRate::query()->sole();
        $this->assertSame('IDR', $row->from);
        $this->assertSame('MYR', $row->to);
        $this->assertSame('open.er-api.com', $row->source);
        $this->assertNotNull($row->fetched_at);
    }

    public function test_rate_falls_back_to_the_last_known_rate_when_the_live_fetch_fails(): void
    {
        CurrencyRate::query()->create([
            'from' => 'IDR', 'to' => 'MYR', 'rate' => 0.000230, 'source' => 'open.er-api.com', 'fetched_at' => now()->subDay(),
        ]);
        Http::fake(['open.er-api.com/*' => Http::response(['message' => 'Server error'], 500)]);

        $rate = (new CurrencyRateService)->rate('IDR', 'MYR');

        $this->assertSame(0.000230, $rate);
        // The failed live attempt must never write a new row.
        $this->assertSame(1, CurrencyRate::query()->count());
    }

    public function test_rate_falls_back_when_the_target_currency_is_missing_from_the_response(): void
    {
        CurrencyRate::query()->create([
            'from' => 'IDR', 'to' => 'MYR', 'rate' => 0.000230, 'source' => 'open.er-api.com', 'fetched_at' => now()->subDay(),
        ]);
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'base_code' => 'IDR', 'rates' => ['USD' => 0.000065]], 200),
        ]);

        $rate = (new CurrencyRateService)->rate('IDR', 'MYR');

        $this->assertSame(0.000230, $rate);
    }

    public function test_rate_throws_when_the_live_fetch_fails_and_no_rate_was_ever_stored(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['message' => 'Server error'], 500)]);

        $this->expectException(CurrencyRateUnavailableException::class);

        (new CurrencyRateService)->rate('IDR', 'MYR');
    }

    /** ADR-033 decision 1 — the cache keeps a single sync run (and repeat calls within the TTL) deterministic and rate-limit-friendly. */
    public function test_rate_is_cached_and_does_not_refetch_within_the_ttl(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'base_code' => 'IDR', 'rates' => ['MYR' => 0.000228]], 200),
        ]);

        $service = new CurrencyRateService;
        $service->rate('IDR', 'MYR');
        $service->rate('IDR', 'MYR');

        Http::assertSentCount(1);
        $this->assertSame(1, CurrencyRate::query()->count());
    }

    /** Different pairs never share a cache entry or collide with each other's stored rows. */
    public function test_rate_caches_independently_per_currency_pair(): void
    {
        Http::fake([
            'open.er-api.com/v6/latest/IDR' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.000228]], 200),
            'open.er-api.com/v6/latest/PHP' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.078]], 200),
        ]);

        $service = new CurrencyRateService;
        $idr = $service->rate('IDR', 'MYR');
        $php = $service->rate('PHP', 'MYR');

        $this->assertSame(0.000228, $idr);
        $this->assertSame(0.078, $php);
        Http::assertSentCount(2);
    }

    /** ADR-111 decision 1 — the widened boundary: null price short-circuits before any rate lookup. */
    public function test_convert_to_sen_returns_null_for_a_null_price(): void
    {
        $result = (new CurrencyRateService)->convertToSen(null, 'IDR');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    /**
     * ADR-111 decision 1 — MYR: plain `round`, no conversion, no rate
     * lookup at all (same as pre-ADR-111 `ProductSyncService::toMyrSen()`
     * with a null rate).
     */
    public function test_convert_to_sen_rounds_a_myr_price_without_fetching_a_rate(): void
    {
        $result = (new CurrencyRateService)->convertToSen(35.556, 'MYR');

        $this->assertSame(3556, $result);
        Http::assertNothingSent();
    }

    /**
     * ADR-111 decision 1 — non-MYR: `ceil`, never `round`, protecting
     * margin by construction — reuses `rate()`'s own cache/live-fetch.
     */
    public function test_convert_to_sen_ceils_a_non_myr_price_using_the_live_rate(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['MYR' => 0.00023]], 200),
        ]);

        // 15394 * 0.00023 = 3.54062 sen * 100 = 354.062 -> ceil 355.
        $result = (new CurrencyRateService)->convertToSen(15394, 'IDR');

        $this->assertSame(355, $result);
    }

    /**
     * ADR-111 decision 6 — the exception `rate()` already throws when no
     * live fetch succeeds and nothing was ever stored propagates
     * unchanged; callers (OrderFulfillmentService) are the ones that
     * catch it and fall back to catalog cost, never this method itself.
     */
    public function test_convert_to_sen_throws_for_a_non_myr_currency_with_no_rate_ever_available(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['message' => 'Server error'], 500)]);

        $this->expectException(CurrencyRateUnavailableException::class);

        (new CurrencyRateService)->convertToSen(15394, 'IDR');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }
}
