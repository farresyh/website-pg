<?php

namespace App\Services\Currency;

use App\Models\CurrencyRate;
use App\Services\Backup\BackupFailureAlerter;
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
    private const FALLBACK_TTL_SECONDS = 900;

    private const STALE_ALERT_HOURS = 48;

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

    /**
     * 2026-09-29 audit (Wave 5 Low): a fallback rate used to be cached for
     * the full TTL like a live one — no live retry for a day, and nobody
     * told when the stored rate kept aging. Now a fallback is cached for
     * only 15 min, and a stored rate older than 48h alerts the admins
     * (once a day per pair, same Plunk path as Horizon's LongWait alert).
     */
    public function rate(string $from, string $to): float
    {
        $key = "currency_rate:{$from}:{$to}";

        $cached = Cache::get($key);
        if ($cached !== null) {
            return (float) $cached;
        }

        $live = $this->fetchLive($from, $to);
        if ($live !== null) {
            Cache::put($key, $live, (int) config('services.fx_api.cache_ttl_seconds', 86400));

            return $live;
        }

        $last = $this->lastKnown($from, $to);
        Cache::put($key, (float) $last->rate, self::FALLBACK_TTL_SECONDS);

        if ($last->fetched_at->lt(now()->subHours(self::STALE_ALERT_HOURS))
            && Cache::add("currency_rate_stale_alert:{$from}:{$to}", true, 86400)) {
            app(BackupFailureAlerter::class)->alert(
                "{$from}->{$to} FX rate is stale",
                "The live FX API has been failing. Prices are using the stored rate from {$last->fetched_at->toDateTimeString()} ({$last->fetched_at->diffForHumans()}).",
                'FX',
            );
        }

        return (float) $last->rate;
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

    private function lastKnown(string $from, string $to): CurrencyRate
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

        return $last;
    }
}
