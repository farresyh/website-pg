<?php

namespace App\Services\Currency;

use App\Models\CurrencyRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ADR-033: fetches a live FX rate from a keyless third-party API,
 * caches it (config `services.fx_api.cache_ttl_seconds`, ~12-24h),
 * and falls back to the last-known stored rate on any live failure —
 * a supplier's price sync must never block on a flaky FX API. Reads
 * `config('services.fx_api')` directly rather than taking constructor
 * params — unlike SupplierAdapter/PaymentGateway, this is one
 * app-wide service, not a per-supplier/per-gateway implementation
 * needing its own explicit AppServiceProvider binding.
 *
 * `rate($from, $to)` returns "how many units of $to equal 1 unit of
 * $from" — e.g. rate('IDR', 'MYR') = 0.000228 means 1 IDR = 0.000228
 * MYR. Callers multiply: `price_in_to = price_in_from * rate`.
 */
final class CurrencyRateService
{
    /**
     * ADR-111 decision 1: the single conversion boundary, widened from
     * one call pattern (Price Sync's catalog conversion, via
     * ProductSyncService) to two (this ADR's delivery-time conversion).
     * Same formula as the pre-ADR-111 `ProductSyncService::toMyrSen()`
     * it replaces, unchanged: MYR is `round` (no conversion, no
     * margin-protection concern); every other currency is `ceil`, never
     * `round`, protecting margin by construction. Non-MYR resolves its
     * own rate via `rate()` above — same cache `ProductSyncService`
     * already warms, so this is a cache read in the common case, not a
     * live fetch (ADR-111 decision 6).
     */
    public function convertToSen(?float $price, string $fromCurrency): ?int
    {
        if ($price === null) {
            return null;
        }

        if ($fromCurrency === 'MYR') {
            return (int) round($price * 100);
        }

        $rate = $this->rate($fromCurrency, 'MYR');

        return (int) ceil($price * $rate * 100);
    }

    public function rate(string $from, string $to): float
    {
        $ttl = (int) config('services.fx_api.cache_ttl_seconds', 86400);

        return Cache::remember(
            "currency_rate:{$from}:{$to}",
            $ttl,
            fn () => $this->fetchLive($from, $to) ?? $this->lastKnown($from, $to),
        );
    }

    private function fetchLive(string $from, string $to): ?float
    {
        try {
            $response = Http::timeout((int) config('services.fx_api.timeout_seconds', 5))
                ->get(config('services.fx_api.base_url').'/'.$from);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || $response->json('result') !== 'success') {
            return null;
        }

        $rate = $response->json("rates.{$to}");

        if ($rate === null) {
            return null;
        }

        CurrencyRate::query()->create([
            'from' => $from,
            'to' => $to,
            'rate' => (float) $rate,
            'source' => (string) config('services.fx_api.source', 'open.er-api.com'),
            'fetched_at' => now(),
        ]);

        return (float) $rate;
    }

    private function lastKnown(string $from, string $to): float
    {
        $last = CurrencyRate::query()
            ->where('from', $from)
            ->where('to', $to)
            ->latest('fetched_at')
            ->first();

        if ($last === null) {
            throw new CurrencyRateUnavailableException(
                "No FX rate available for {$from}->{$to}: the live fetch failed and no rate was ever stored",
            );
        }

        return (float) $last->rate;
    }
}
