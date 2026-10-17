<?php

namespace App\Services\Accounting;

use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\SupplierTransfer;
use App\Services\Order\DeliveryStatus;
use App\Support\MalaysiaTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ADR-083 2026-10-17 addendum (§16 item 115): the month's cost of sales at
 * funded cost. A prepaid supplier deposit can't be withdrawn, so it is a
 * non-monetary prepayment (IFRIC 22): what an order cost is its drawdown
 * at the MYR paid per unit of deposit, not the market rate at delivery
 * that `Order::effectiveCostPriceSen()` uses.
 *
 * Costed per "unit": a plain order, or each delivered leg of a combo (a
 * leg draws down on its own, ADR-094 decision 11). A unit with drawdowns
 * from a supplier we can value is funded; any other unit falls back to
 * its own cost, so a cost is never dropped.
 */
final class FundedCostOfSales
{
    public function __construct(private readonly RecognisedRevenue $revenue = new RecognisedRevenue) {}

    /**
     * @return array{cogs_sen: int, cogs_at_order_cost_sen: int, cogs_funding_difference_sen: int, cogs_fallback_order_count: int, drawdowns_not_in_cogs_sen: int}
     */
    public function forPeriod(Carbon $from, Carbon $toExclusive): array
    {
        $lastDay = $toExclusive->copy()->setTimezone(MalaysiaTime::TIMEZONE)->subDay()->toDateString();
        $orders = $this->revenue->recognisedOrders($from, $toExclusive);

        /** @var array<string, Order|OrderDeliveryLeg> $units  "order:7" / "order_delivery_leg:12" => model */
        $units = [];
        foreach ($orders as $order) {
            $legs = $order->deliveryLegs->where('status', DeliveryStatus::Delivered);
            if ($order->deliveryLegs->isEmpty()) {
                $units["order:{$order->id}"] = $order;
            }
            foreach ($legs as $leg) {
                $units["order_delivery_leg:{$leg->id}"] = $leg;
            }
        }

        $drawdowns = $this->drawdownsFor(array_keys($units));
        $rates = $this->rates($drawdowns->collapse()->pluck('supplier_id')->all(), $lastDay);

        $funded = [];   // supplier_id => units drawn by funded units
        $fallbackSen = 0;
        $fallbackOrders = [];
        foreach ($units as $key => $unit) {
            $rows = $drawdowns->get($key, collect());
            $valuable = $rows->isNotEmpty() && $rows->every(fn ($row) => $rates[$row->supplier_id] !== null);

            if ($valuable) {
                foreach ($rows as $row) {
                    $funded[$row->supplier_id] = ($funded[$row->supplier_id] ?? 0.0) + abs((float) $row->amount);
                }

                continue;
            }

            $fallbackSen += $unit instanceof Order
                ? $unit->effectiveCostPriceSen()
                : (int) ($unit->real_cost_price_sen ?? $unit->componentPackage?->cost_price ?? 0);
            $fallbackOrders[$unit instanceof Order ? $unit->id : $unit->order_id] = true;
        }

        $cogs = $this->value($funded, $rates) + $fallbackSen;
        $atOrderCost = (int) $orders->sum(fn (Order $o) => $o->effectiveCostPriceSen());

        return [
            'cogs_sen' => $cogs,
            'cogs_at_order_cost_sen' => $atOrderCost,
            'cogs_funding_difference_sen' => $cogs - $atOrderCost,
            'cogs_fallback_order_count' => count($fallbackOrders),
            'drawdowns_not_in_cogs_sen' => $this->drawdownsNotInCogs($from, $toExclusive, array_keys($units), $lastDay),
        ];
    }

    /**
     * MYR per unit of foreign currency, weighted by each transfer's own
     * net foreign amount received — `Σ amount_myr_sent / Σ net_foreign_received`
     * across every non-voided transfer for this supplier sent on or before
     * `$asOfDate` (a KL date). Also the month close's supplier prepaid
     * figure (2026-10-10 addendum, decision 13). Cumulative over all
     * transfers, not IAS 2's opening-plus-purchases average: the
     * 2026-10-17 addendum's known limit H1.
     */
    public function weightedAverageRate(int $supplierId, string $asOfDate): ?float
    {
        $transfers = SupplierTransfer::query()
            ->where('supplier_id', $supplierId)
            ->whereNull('voided_at')
            ->where('transferred_on', '<=', $asOfDate)
            ->get(['amount_myr_sent', 'amount_foreign_received', 'supplier_fee']);

        $totalMyrSen = $transfers->sum('amount_myr_sent');
        $totalForeign = $transfers->sum(fn (SupplierTransfer $t) => (float) $t->netForeignReceived());

        if ($totalForeign <= 0.0) {
            return null;
        }

        return ($totalMyrSen / 100) / $totalForeign;
    }

    /**
     * Drawdowns recorded this month whose unit is not in this month's
     * COGS: an unsettled partial combo, an order recognised in another
     * month, or a row tied to no recognised order. Shown so a drawdown
     * can't leave the books unseen.
     *
     * @param  list<string>  $recognisedKeys
     */
    private function drawdownsNotInCogs(Carbon $from, Carbon $toExclusive, array $recognisedKeys, string $lastDay): int
    {
        $outside = SupplierLedgerEntry::query()
            ->where('type', SupplierLedgerEntryType::OrderDrawdown->value)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->get(['supplier_id', 'amount', 'reference_type', 'reference_id'])
            ->reject(fn ($row) => in_array("{$row->reference_type}:{$row->reference_id}", $recognisedKeys, true));

        $rates = $this->rates($outside->pluck('supplier_id')->all(), $lastDay);
        $units = $outside->groupBy('supplier_id')->map(fn ($rows) => (float) $rows->sum(fn ($row) => abs((float) $row->amount)))->all();

        // ponytail: an IDR supplier with no transfer yet has no rate, so its rows here value at 0; its recognised orders still fall back to their own cost.
        return $this->value($units, $rates);
    }

    /**
     * @param  list<string>  $keys  "reference_type:reference_id"
     * @return Collection<string, Collection<int, SupplierLedgerEntry>>
     */
    private function drawdownsFor(array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        $ids = fn (string $type) => collect($keys)->filter(fn ($k) => str_starts_with($k, "{$type}:"))->map(fn ($k) => (int) substr($k, strlen($type) + 1))->values()->all();

        return SupplierLedgerEntry::query()
            ->where('type', SupplierLedgerEntryType::OrderDrawdown->value)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('reference_type', 'order')->whereIn('reference_id', $ids('order')))
                ->orWhere(fn ($q) => $q->where('reference_type', 'order_delivery_leg')->whereIn('reference_id', $ids('order_delivery_leg'))))
            ->get(['supplier_id', 'amount', 'reference_type', 'reference_id'])
            ->groupBy(fn ($row) => "{$row->reference_type}:{$row->reference_id}");
    }

    /**
     * MYR per supplier currency unit: 1 for an MYR supplier, the deposit's
     * blended rate otherwise, null when there is no transfer to value it by.
     *
     * @param  list<int|string>  $supplierIds
     * @return array<int, float|null>
     */
    private function rates(array $supplierIds, string $lastDay): array
    {
        $supplierIds = array_values(array_unique(array_map('intval', $supplierIds)));
        $currencies = Supplier::query()->whereIn('id', $supplierIds)->pluck('currency', 'id');

        return collect($supplierIds)
            ->mapWithKeys(fn (int $id) => [$id => $currencies[$id] === 'MYR' ? 1.0 : $this->weightedAverageRate($id, $lastDay)])
            ->all();
    }

    /**
     * Rounded once per supplier, never per order: per-order rounding would
     * drift by a sen per order from the true total.
     *
     * @param  array<int, float>  $unitsBySupplier
     * @param  array<int, float|null>  $rates
     */
    private function value(array $unitsBySupplier, array $rates): int
    {
        return (int) collect($unitsBySupplier)->sum(fn (float $units, int $supplierId) => (int) round($units * ($rates[$supplierId] ?? 0.0) * 100));
    }
}
