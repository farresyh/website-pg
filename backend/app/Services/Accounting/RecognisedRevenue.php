<?php

namespace App\Services\Accounting;

use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ADR-083 decision 8 + ADR-094 decision 41, extracted by ADR-104's
 * 2026-10-08 addendum R7 so the Monthly Summary's "Sales revenue" and
 * the Reports Bridge to Accounting read one definition and cannot drift.
 *
 * Revenue is recognised on delivery, scoped by `paid_at`: Σ
 * `selling_price` of delivered orders, plus each settled partial order's
 * `selling_price` net of its compensation. It deliberately does not
 * filter `payment_status` (the original definition); the Bridge's
 * "Unexplained difference" row is what surfaces a delivered order that
 * was never paid. Null bounds mean all time.
 */
final class RecognisedRevenue
{
    public function revenueSen(?CarbonInterface $from, ?CarbonInterface $toExclusive): int
    {
        return (int) $this->inRange(Order::query(), $from, $toExclusive)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->sum('selling_price')
            + (int) $this->settledPartialOrders($from, $toExclusive)->sum(fn (Order $o) => $this->keptSen($o));
    }

    /** What a settled partial order kept: the revenue it is recognised at. */
    public function keptSen(Order $order): int
    {
        return $order->selling_price - $order->compensationAmountSen();
    }

    /**
     * A partially delivered order is recognised once settled (like a
     * Failed one, it has no final economics before). Few rows, so loaded
     * rather than expressed in SQL.
     *
     * @return Collection<int, Order>
     */
    public function settledPartialOrders(?CarbonInterface $from, ?CarbonInterface $toExclusive): Collection
    {
        return $this->inRange(Order::query(), $from, $toExclusive)
            ->where('delivery_status', DeliveryStatus::PartiallyDelivered->value)
            ->with(['voucher', 'voucherRedemption', 'deliveryLegs.componentPackage:id,cost_price'])
            ->get()
            ->filter(fn (Order $o) => $o->isAlreadyCompensated())
            ->values();
    }

    private function inRange(Builder $query, ?CarbonInterface $from, ?CarbonInterface $toExclusive): Builder
    {
        return $query
            ->where('is_test', false)
            ->whereNotNull('paid_at')
            ->when($from, fn (Builder $q) => $q->where('paid_at', '>=', $from))
            ->when($toExclusive, fn (Builder $q) => $q->where('paid_at', '<', $toExclusive));
    }
}
