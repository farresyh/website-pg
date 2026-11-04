<?php

namespace App\Services\Supplier;

use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Currency\LandedCostRate;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-115 (2026-11-05 addendum): how much a supplier must hold right now.
 * The one rule for "short" — the email, the Dashboard chip and briefing,
 * /admin/balance and the daily log all read assess().
 *
 *  - Forecast: the busiest run of `windowDays` Malaysia days in the last
 *    60, quiet days counted as zero (decision 1).
 *  - Window: the lead time, or through Monday from Thursday (decision 2).
 *  - Required: the higher of the admin's floor and forecast × (1 + margin)
 *    (decision 3).
 *  - Balance: the lower of the polled balance and the funding ledger, when
 *    the ledger is in use (decision 5).
 */
final class SupplierBufferService
{
    public const HISTORY_DAYS = 60;

    public const DEFAULT_LEAD_DAYS = 2;

    public function __construct(private readonly LandedCostRate $landedCost) {}

    /** Null when nothing is watched: not an API supplier, no balance, or no floor and no spend. */
    public function assess(Supplier $supplier): ?SupplierBuffer
    {
        if (! $supplier->isApiBacked()) {
            return null;
        }

        $balance = $this->balance($supplier);
        if ($balance === null) {
            return null;
        }

        $today = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->startOfDay();
        $windowDays = $this->windowDays($today, $this->leadDays($supplier));
        [$forecast, $from] = $this->busiestWindow($this->dailySpend($supplier, $today), $windowDays, $today->subDays(self::HISTORY_DAYS - 1));

        $floor = $this->number($supplier, 'low_balance_threshold');
        if ($floor === null && $forecast <= 0) {
            return null;
        }

        $margin = max(0.0, $this->number($supplier, 'buffer_margin_pct') ?? 0.0);
        $required = max($floor ?? 0.0, $forecast * (1 + $margin / 100));
        $averageSen = $this->landedCost->averageSen($supplier);

        return new SupplierBuffer(
            balance: $balance,
            floor: $floor,
            forecast: $forecast,
            marginPct: $margin,
            required: $required,
            windowDays: $windowDays,
            coversUntil: $today->addDays($windowDays - 1),
            busiestFrom: $from,
            busiestTo: $from?->addDays($windowDays - 1),
            balanceMyr: $averageSen === null ? null : round($balance * $averageSen / 100, 2),
            requiredMyr: $averageSen === null ? null : round($required * $averageSen / 100, 2),
        );
    }

    /** Decision 2: Monday–Wednesday the lead time; Thursday–Sunday through Monday, never shorter than the lead time. */
    private function windowDays(CarbonImmutable $today, int $leadDays): int
    {
        $weekday = $today->dayOfWeekIso; // 1 = Monday … 7 = Sunday
        $throughMonday = $weekday >= 4 ? 9 - $weekday : 0; // Thu 5, Fri 4, Sat 3, Sun 2

        return max($leadDays, $throughMonday);
    }

    /**
     * @param  list<float>  $daily  oldest first, today last
     * @return array{float, ?CarbonImmutable} the busiest sum and the first day of that window (earliest on a tie)
     */
    private function busiestWindow(array $daily, int $windowDays, CarbonImmutable $firstDay): array
    {
        $best = 0.0;
        $bestStart = null;
        for ($start = 0; $start + $windowDays <= count($daily); $start++) {
            $sum = array_sum(array_slice($daily, $start, $windowDays));
            if ($sum > $best) {
                $best = $sum;
                $bestStart = $start;
            }
        }

        return [$best, $bestStart === null ? null : $firstDay->addDays($bestStart)];
    }

    /**
     * Decision 4: spend per Malaysia day for the last HISTORY_DAYS, zero-filled,
     * cached on today and the latest ledger row, so a new drawdown is seen at once.
     *
     * @return list<float>
     */
    private function dailySpend(Supplier $supplier, CarbonImmutable $today): array
    {
        $start = $today->subDays(self::HISTORY_DAYS - 1);
        $latest = SupplierLedgerEntry::query()->where('supplier_id', $supplier->id)->max('id');
        $key = "supplier-buffer:{$supplier->id}:{$today->toDateString()}:{$latest}";

        return Cache::remember($key, 86400, function () use ($supplier, $start) {
            $day = MalaysiaTime::dateSql('created_at');
            $totals = SupplierLedgerEntry::query()
                ->where('supplier_id', $supplier->id)
                ->where('type', SupplierLedgerEntryType::OrderDrawdown->value)
                ->where('created_at', '>=', $start->utc())
                ->selectRaw("{$day} as spend_day, SUM(-amount) as spent")
                ->groupByRaw($day)
                ->pluck('spent', 'spend_day');

            return array_map(
                fn (int $i) => (float) ($totals[$start->addDays($i)->toDateString()] ?? 0),
                range(0, self::HISTORY_DAYS - 1),
            );
        });
    }

    /** Decision 5: the lower of what the supplier last told us and what the ledger has drawn since. */
    private function balance(Supplier $supplier): ?float
    {
        $polled = $supplier->balance === null ? null : (float) $supplier->balance;
        $ledgerInUse = $supplier->ledgerEntries()->where('type', SupplierLedgerEntryType::Topup->value)->exists();

        if (! $ledgerInUse) {
            return $polled;
        }

        $ledger = (float) $supplier->supplierLedgerBalance();

        return $polled === null ? $ledger : min($polled, $ledger);
    }

    private function leadDays(Supplier $supplier): int
    {
        $days = $this->number($supplier, 'topup_lead_days');

        return $days !== null && $days >= 1 ? (int) $days : self::DEFAULT_LEAD_DAYS;
    }

    private function number(Supplier $supplier, string $key): ?float
    {
        $value = $supplier->api_config[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
