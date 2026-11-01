<?php

namespace App\Services\Currency;

use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Services\Accounting\FundedCostOfSales;
use App\Services\Accounting\MovingAveragePool;
use App\Services\Accounting\SupplierLedgerEntryType;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-134: what one unit of a supplier's currency really cost us — the
 * moving pool's average after its last deposit or adjustment, transfer
 * fees included (decisions 1–3). The one rate behind both places a
 * supplier price becomes ringgit: Price Sync's catalog cost and an
 * order's real cost at delivery (decision 5). The market mid-rate
 * (`CurrencyRateService`) is for display beside it only.
 *
 * In sen per unit, the pool's own unit, so an order's cost here and its
 * drawdown's cost in the pool come out of the same arithmetic.
 */
final class LandedCostRate
{
    /** A ringgit unit at face value: a pay-after supplier, or an MYR one never funded. */
    private const FACE_VALUE = 100.0;

    public function __construct(private readonly FundedCostOfSales $rates) {}

    /** Sen per supplier unit; null for a foreign-currency supplier with no deposit to cost it by (decision 4). */
    public function averageSen(Supplier $supplier): ?float
    {
        if (! $supplier->isPooled()) {
            return self::FACE_VALUE;
        }

        // Keyed on what moves the average, so a new, corrected or voided transfer or an adjustment is seen with no forget in the write path (decision 8).
        $average = Cache::remember($this->cacheKey($supplier), 86400, fn () => MovingAveragePool::replay($supplier->id, $this->rates)->average);

        return $average ?? ($supplier->currency === 'MYR' ? self::FACE_VALUE : null);
    }

    public function isFunded(Supplier $supplier): bool
    {
        return $this->averageSen($supplier) !== null;
    }

    /** Catalog cost (Price Sync): rounded up, ADR-033's margin cushion; a face-value ringgit is rounded as it always was. */
    public function toCatalogSen(Supplier $supplier, ?float $price): ?int
    {
        return $this->toCatalogSenAt($this->averageSen($supplier), $price);
    }

    /** The same, at an average the caller already read once for a whole run. */
    public function toCatalogSenAt(?float $averageSen, ?float $price): ?int
    {
        if ($price === null || $averageSen === null) {
            return null;
        }

        if ($averageSen === self::FACE_VALUE) {
            return (int) round($price * 100);
        }

        // Rounded to 6 places first so float noise (98.0000000001) never costs a sen.
        return (int) ceil(round($price * $averageSen, 6));
    }

    /** An order's real cost at delivery: half-up, exactly the cost the pool gives its drawdown. */
    public function toCostSen(Supplier $supplier, ?float $price): ?int
    {
        $averageSen = $this->averageSen($supplier);

        return $price === null || $averageSen === null ? null : MovingAveragePool::costAt($price, $averageSen);
    }

    private function cacheKey(Supplier $supplier): string
    {
        $transfers = SupplierTransfer::query()
            ->where('supplier_id', $supplier->id)
            ->selectRaw('COUNT(*) as n, SUM(amount_myr_sent) as myr, SUM(fee_myr) as fee, SUM(amount_foreign_received) as foreign_units, SUM(COALESCE(supplier_fee, 0)) as supplier_fee, SUM(CASE WHEN voided_at IS NULL THEN 0 ELSE 1 END) as voided, MAX(updated_at) as touched')
            ->first();

        $adjustments = SupplierLedgerEntry::query()
            ->where('supplier_id', $supplier->id)
            ->where('type', '!=', SupplierLedgerEntryType::OrderDrawdown->value)
            ->max('id');

        return 'landed_rate:'.$supplier->id.':'.md5(json_encode([$transfers?->toArray(), $adjustments]));
    }
}
