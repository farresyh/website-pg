<?php

namespace App\Services\Accounting;

use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Support\MalaysiaTime;
use Illuminate\Support\Carbon;

/**
 * ADR-083 2026-10-25 addendum (§16 item 132): a foreign-currency
 * supplier's deposits as a moving (perpetual) weighted average, IAS 2 /
 * MPERS 13. The pool holds the units left and their ringgit cost in sen:
 * a deposit adds its net units and its `amount_myr_sent`, and any other
 * row takes `round(pool_sen × units ÷ pool_units)`, so Σ costs taken +
 * the pool = Σ ringgit sent, to the sen.
 *
 * Replayed on every read, never stored, so a transfer recorded late,
 * corrected or voided in an open month re-costs only open months
 * (`AccountingPeriod::assertOpen` keeps closed ones still).
 */
final class MovingAveragePool
{
    /** The pool opens at the start of this KL day; before it, the cumulative average (`weightedAverageRate()`) stands. */
    public const OPENS_ON = '2026-10-01';

    /** The opening is valued at this day's cumulative average, as September's close valued the balance. */
    public const OPENING_DAY = '2026-09-30';

    /**
     * @param  array<int, int|null>  $costs  ledger entry id => signed sen (null: no average to value it by yet)
     */
    private function __construct(
        public readonly array $costs,
        public readonly float $units,
        public readonly int $sen,
    ) {}

    public static function opensAt(): Carbon
    {
        return Carbon::parse(self::OPENS_ON, MalaysiaTime::TIMEZONE)->utc();
    }

    /** @param  ?Carbon  $until  replay only what happened before this instant (a month end); null for everything */
    public static function replay(int $supplierId, FundedCostOfSales $rates, ?Carbon $until = null): self
    {
        $opensAt = self::opensAt();
        $dayStart = fn (SupplierTransfer $t) => Carbon::parse($t->transferred_on->toDateString(), MalaysiaTime::TIMEZONE)->utc();

        $transfers = SupplierTransfer::query()->where('supplier_id', $supplierId)->get();
        $voided = $transfers->whereNotNull('voided_at')->pluck('id')->all();

        // A voided transfer and every row that references it are out: its TOPUP, its void and any adjustment (prod's two 15-Sep voids are adjustments).
        $rows = SupplierLedgerEntry::query()
            ->where('supplier_id', $supplierId)
            ->where('type', '!=', SupplierLedgerEntryType::Topup->value)
            ->whereNot(fn ($q) => $q->where('reference_type', 'supplier_transfer')->whereIn('reference_id', $voided))
            ->get(['id', 'amount', 'created_at']);

        $events = [
            ...$transfers->whereNull('voided_at')->map(fn (SupplierTransfer $t) => [$dayStart($t), 0, $t->id, (float) $t->netForeignReceived(), (int) $t->amount_myr_sent])->all(),
            ...$rows->map(fn (SupplierLedgerEntry $r) => [$r->created_at, 1, $r->id, (float) $r->amount, null])->all(),
        ];
        if ($until !== null) {
            $events = array_filter($events, fn (array $e) => $e[0] < $until);
        }
        // A deposit counts from the start of its day, ahead of that day's rows.
        usort($events, fn (array $a, array $b) => [$a[0]->getTimestamp(), $a[1], $a[2]] <=> [$b[0]->getTimestamp(), $b[1], $b[2]]);

        $before = array_filter($events, fn (array $e) => $e[0] < $opensAt);
        $units = (float) array_sum(array_column($before, 3));
        $rate = $rates->weightedAverageRate($supplierId, self::OPENING_DAY);
        $sen = $rate === null ? 0 : (int) round($units * $rate * 100);
        $average = $rate === null ? null : $rate * 100;   // sen per unit

        $costs = [];
        foreach ($events as [$at, $kind, $id, $amount, $myrSen]) {
            if ($at < $opensAt) {
                continue;
            }

            if ($kind === 0) {
                $units += $amount;
                $sen += $myrSen;
            } else {
                // An empty or negative pool takes the last known average (decision 6).
                $cost = $units > 0 ? (int) round($sen * $amount / $units) : ($average === null ? null : (int) round($amount * $average));
                $costs[$id] = $cost;
                $units += $amount;
                $sen += $cost ?? 0;
            }

            if ($units > 0) {
                $average = $sen / $units;
            }
        }

        return new self($costs, $units, $sen);
    }
}
