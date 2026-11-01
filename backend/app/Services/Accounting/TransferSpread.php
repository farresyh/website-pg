<?php

namespace App\Services\Accounting;

use App\Models\CurrencyRate;
use App\Models\Supplier;
use App\Models\SupplierTransfer;
use App\Support\MalaysiaTime;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ADR-134 decision 16: a transfer now prices the whole catalogue, so a
 * typo in it must not slip through. Its rate (fee included when pooled) is
 * compared with that day's market mid-rate; outside the supplier's band
 * (Middleware: `transfer_spread_min_pct` / `transfer_spread_max_pct`,
 * default −2% to +12%) the admin confirms before it saves.
 */
final class TransferSpread
{
    public const DEFAULT_MIN_PCT = -2.0;

    public const DEFAULT_MAX_PCT = 12.0;

    /**
     * @return array{rate: float, market_rate: ?float, spread_pct: ?float, min_pct: float, max_pct: float, outside: bool}|null null when there is no credit to rate
     */
    public function measure(Supplier $supplier, string $transferredOn, int $amountMyrSent, int $feeMyr, string $netForeignReceived): ?array
    {
        $rate = SupplierTransfer::rateFor($supplier, $transferredOn, $amountMyrSent, $feeMyr, $netForeignReceived);

        if ($rate === null) {
            return null;
        }

        $market = $this->marketRateOn($supplier->currency, $transferredOn);
        $spread = $market ? round(($rate / $market - 1) * 100, 2) : null;
        $min = $this->bound($supplier, 'transfer_spread_min_pct', self::DEFAULT_MIN_PCT);
        $max = $this->bound($supplier, 'transfer_spread_max_pct', self::DEFAULT_MAX_PCT);

        return [
            'rate' => $rate,
            'market_rate' => $market,
            'spread_pct' => $spread,
            'min_pct' => $min,
            'max_pct' => $max,
            // With no market rate to compare, nothing can be flagged; the transfer saves as before.
            'outside' => $spread !== null && ($spread < $min || $spread > $max),
        ];
    }

    /** Refuses a transfer outside the band unless the admin has confirmed it. */
    public function assertConfirmedIfOutside(?array $measured, bool $confirmed): void
    {
        if ($measured === null || ! $measured['outside'] || $confirmed) {
            return;
        }

        throw ValidationException::withMessages([
            'confirm_spread' => [sprintf(
                'This transfer costs %+.2f%% against the market rate that day (expected %+.1f%% to %+.1f%%). Check the amounts, then confirm to save.',
                $measured['spread_pct'], $measured['min_pct'], $measured['max_pct'],
            )],
        ]);
    }

    /** The last mid-rate fetched by the end of that KL day; 1.0 for ringgit. */
    private function marketRateOn(string $currency, string $date): ?float
    {
        if ($currency === 'MYR') {
            return 1.0;
        }

        $rate = CurrencyRate::query()
            ->where('from', $currency)
            ->where('to', 'MYR')
            ->where('fetched_at', '<', Carbon::parse($date, MalaysiaTime::TIMEZONE)->addDay()->utc())
            ->latest('fetched_at')
            ->value('rate');

        return $rate === null ? null : (float) $rate;
    }

    private function bound(Supplier $supplier, string $key, float $default): float
    {
        $value = $supplier->api_config[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }
}
