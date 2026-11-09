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
 * Revenue is recognised on delivery, filed by `recognised_at` (ADR-127
 * decision 1; it was `paid_at`, which put an order paid in one month and
 * delivered in the next into neither month's lines): Σ `selling_price`
 * of delivered orders, plus each settled partial order's `selling_price`
 * net of its compensation. It deliberately does not
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

    /**
     * Every order whose revenue is recognised in the range: the set the
     * Monthly Summary's COGS is costed over (ADR-083 2026-10-17 addendum),
     * so cost always follows revenue.
     *
     * @return Collection<int, Order>
     */
    public function recognisedOrders(?CarbonInterface $from, ?CarbonInterface $toExclusive): Collection
    {
        return $this->inRange(Order::query(), $from, $toExclusive)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->with('deliveryLegs.componentPackage:id,cost_price')
            ->get()
            ->concat($this->settledPartialOrders($from, $toExclusive))
            ->values();
    }

    /**
     * ADR-118 V13: the KOL promo discount given on the revenue recognised in
     * the range (gross = net + this). A settled partial order counts only the
     * share it kept, rounded down, beside keptSen().
     */
    public function promoDiscountSen(?CarbonInterface $from, ?CarbonInterface $toExclusive): int
    {
        return (int) $this->inRange(Order::query(), $from, $toExclusive)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->sum('promo_discount_sen')
            + (int) $this->settledPartialOrders($from, $toExclusive)->sum(fn (Order $o) => $this->keptPromoSen($o));
    }

    /**
     * ADR-118 V14: promoDiscountSen() per code, for the campaign scorecard.
     *
     * @return array<int, int> promo_code_id => sen
     */
    public function promoDiscountByCode(?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        $byCode = $this->inRange(Order::query(), $from, $toExclusive)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->whereNotNull('promo_code_id')
            ->groupBy('promo_code_id')
            ->selectRaw('promo_code_id, SUM(promo_discount_sen) as total')
            ->pluck('total', 'promo_code_id')
            ->map(fn ($sen) => (int) $sen)
            ->all();

        foreach ($this->settledPartialOrders($from, $toExclusive)->whereNotNull('promo_code_id') as $order) {
            $byCode[$order->promo_code_id] = ($byCode[$order->promo_code_id] ?? 0) + $this->keptPromoSen($order);
        }

        return $byCode;
    }

    public function keptPromoSen(Order $order): int
    {
        return $order->selling_price > 0 ? intdiv($order->promo_discount_sen * $this->keptSen($order), $order->selling_price) : 0;
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

    /**
     * ADR-139 decision 5: one reseller's recognised wallet sales and platform
     * profit in the range — the rebate's reach and cap read the same set the
     * Monthly Summary recognises, never a second definition.
     *
     * @return array{sales_sen: int, profit_sen: int}
     */
    public function walletResellerTotals(int $resellerId, ?CarbonInterface $from, ?CarbonInterface $toExclusive): array
    {
        $delivered = $this->inRange(Order::query(), $from, $toExclusive)
            ->where('wallet_reseller_id', $resellerId)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->selectRaw('COALESCE(SUM(selling_price), 0) AS sales, COALESCE(SUM(platform_profit), 0) AS profit')
            ->first();
        $partial = $this->settledPartialOrders($from, $toExclusive)->where('wallet_reseller_id', $resellerId);

        return [
            'sales_sen' => (int) $delivered->sales + (int) $partial->sum(fn (Order $o) => $this->keptSen($o)),
            'profit_sen' => (int) $delivered->profit + (int) $partial->sum('platform_profit'),
        ];
    }

    private function inRange(Builder $query, ?CarbonInterface $from, ?CarbonInterface $toExclusive): Builder
    {
        return $query
            ->where('is_test', false)
            ->whereNotNull('recognised_at')
            ->when($from, fn (Builder $q) => $q->where('recognised_at', '>=', $from))
            ->when($toExclusive, fn (Builder $q) => $q->where('recognised_at', '<', $toExclusive));
    }
}
