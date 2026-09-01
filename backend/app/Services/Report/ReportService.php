<?php

namespace App\Services\Report;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\PricingBasis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * RPT-1..3 (docs/prd.md §6.9). Every figure here is grilled and pinned
 * (2026-08-26 session) against two hard rules:
 *
 * - "Sales"/"orders count"/"latest order" only ever count
 *   payment_status=Paid orders, scoped by `paid_at` (when money actually
 *   arrived), never `created_at` (when checkout merely started) — an
 *   abandoned/still-pending checkout has no business value yet.
 * - "Profit" is never read off Order.platform_profit/reseller_profit
 *   directly (those columns are stamped at checkout time, before the
 *   delivery outcome is known). It is summed from `ledger_entries`
 *   type=order_profit instead, which OrderFulfillmentService only ever
 *   credits on a *successful delivery* — mirrors WTH-4's own "ledger,
 *   not a cached field" rule. An order that got paid but never
 *   delivered (and became a store-credit Voucher instead, ADR-004)
 *   correctly contributes $0 profit here, forever, unless it later
 *   delivers.
 *
 * All money figures are integer sen. All day-bucketing uses
 * Asia/Kuala_Lumpur (the business's own timezone), not server/UTC.
 */
final class ReportService
{
    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    /**
     * Resolves RPT-3's year/month filter into a [from, toExclusive) UTC
     * instant pair ready to feed into paid_at comparisons. Null year
     * means "no filter" (all-time) — both null.
     */
    public function dateRangeForYearMonth(?int $year, ?int $month): array
    {
        if ($year === null) {
            return [null, null];
        }

        $start = CarbonImmutable::create($year, $month ?? 1, 1, 0, 0, 0, self::TIMEZONE);
        $end = $month !== null ? $start->addMonth() : $start->addYear();

        return [$start->setTimezone('UTC'), $end->setTimezone('UTC')];
    }

    public function summary(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): array
    {
        $totalSales = (int) $this->scopedOrders($from, $toExclusive, $resellerId)->sum('final_amount');
        $ordersCount = $this->scopedOrders($from, $toExclusive, $resellerId)->count();
        $latest = $this->scopedOrders($from, $toExclusive, $resellerId)
            ->orderByDesc('paid_at')
            ->first(['order_number', 'paid_at', 'customer_email', 'final_amount']);

        $profit = $this->profitTotals($from, $toExclusive, $resellerId);

        return [
            'total_sales' => $totalSales,
            'orders_count' => $ordersCount,
            'platform_profit' => $profit['platform'],
            'reseller_profit' => $profit['reseller'],
            'margin_pct' => $totalSales > 0 ? round($profit['platform'] / $totalSales * 100, 2) : 0.0,
            'avg_order_value' => $ordersCount > 0 ? (int) round($totalSales / $ordersCount) : 0,
            'latest_order' => $latest ? [
                'order_number' => $latest->order_number,
                'paid_at' => $latest->paid_at?->setTimezone(self::TIMEZONE)->toIso8601String(),
                'customer_email' => $latest->customer_email,
                'final_amount' => $latest->final_amount,
            ] : null,
        ];
    }

    /**
     * RPT-2 — always "last N days from today", independent of RPT-3's
     * year/month filter (that filter only narrows the stat cards/export).
     * Both sales and profit are attributed to the order's own `paid_at`
     * day (KL), even though profit may only be ledger-credited later
     * once delivery completes — grilled 2026-08-26: acceptable because
     * delivery on this platform is near-instant in practice, and this
     * keeps the sales/profit overlay visually matched day-for-day.
     */
    public function dailyTrend(int $days, ?int $resellerId): array
    {
        $todayKl = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $startKl = $todayKl->subDays($days - 1);
        $fromUtc = $startKl->setTimezone('UTC');
        $toExclusiveUtc = $todayKl->addDay()->setTimezone('UTC');

        $orders = $this->scopedOrders($fromUtc, $toExclusiveUtc, $resellerId)
            ->get(['id', 'paid_at', 'final_amount']);

        $salesByDate = [];
        $orderDateById = [];

        foreach ($orders as $order) {
            $dateKey = $order->paid_at->setTimezone(self::TIMEZONE)->toDateString();
            $salesByDate[$dateKey] = ($salesByDate[$dateKey] ?? 0) + $order->final_amount;
            $orderDateById[$order->id] = $dateKey;
        }

        [$platformProfitByDate, $resellerProfitByDate] = $this->profitByKey($orderDateById);

        $rows = [];
        $cursor = $startKl;

        while ($cursor->lte($todayKl)) {
            $key = $cursor->toDateString();
            $rows[] = [
                'date' => $key,
                'sales' => $salesByDate[$key] ?? 0,
                'platform_profit' => $platformProfitByDate[$key] ?? 0,
                'reseller_profit' => $resellerProfitByDate[$key] ?? 0,
            ];
            $cursor = $cursor->addDay();
        }

        return $rows;
    }

    /**
     * Overview tab's Detailed Data Table — respects RPT-3's year/month
     * filter (unlike dailyTrend(), which is always "last N days from
     * now"). Only emits days that actually had a paid order — an
     * unbounded/all-time range zero-filled day-by-day would be an
     * unbounded row count. Newest first, matching the reference layout.
     */
    public function dailyBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): array
    {
        $orders = $this->scopedOrders($from, $toExclusive, $resellerId)
            ->get(['id', 'paid_at', 'final_amount', 'transaction_fee']);

        $salesByDate = [];
        $ordersCountByDate = [];
        $feesByDate = [];
        $orderDateById = [];

        foreach ($orders as $order) {
            $dateKey = $order->paid_at->setTimezone(self::TIMEZONE)->toDateString();
            $salesByDate[$dateKey] = ($salesByDate[$dateKey] ?? 0) + $order->final_amount;
            $ordersCountByDate[$dateKey] = ($ordersCountByDate[$dateKey] ?? 0) + 1;
            $feesByDate[$dateKey] = ($feesByDate[$dateKey] ?? 0) + $order->transaction_fee;
            $orderDateById[$order->id] = $dateKey;
        }

        [$platformProfitByDate, $resellerProfitByDate] = $this->profitByKey($orderDateById);

        $rows = [];

        foreach ($salesByDate as $date => $sales) {
            $rows[] = [
                'date' => $date,
                'orders_count' => $ordersCountByDate[$date],
                'sales' => $sales,
                'platform_profit' => $platformProfitByDate[$date] ?? 0,
                'reseller_profit' => $resellerProfitByDate[$date] ?? 0,
                'transaction_fees' => $feesByDate[$date],
                'avg_order_value' => (int) round($sales / $ordersCountByDate[$date]),
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($b['date'], $a['date']));

        return $rows;
    }

    /**
     * Games tab (+ Overview's "Top Performing Games" when limit is
     * passed) — same per-order grouping pattern as dailyBreakdown, keyed
     * by game_id instead of date.
     */
    public function gameBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId, ?int $limit = null): array
    {
        $orders = $this->scopedOrders($from, $toExclusive, $resellerId)
            ->get(['id', 'game_id', 'final_amount']);

        $salesByGame = [];
        $ordersCountByGame = [];
        $orderGameById = [];

        foreach ($orders as $order) {
            $key = $order->game_id ?? 0;
            $salesByGame[$key] = ($salesByGame[$key] ?? 0) + $order->final_amount;
            $ordersCountByGame[$key] = ($ordersCountByGame[$key] ?? 0) + 1;
            $orderGameById[$order->id] = $key;
        }

        [$platformProfitByGame, $resellerProfitByGame] = $this->profitByKey($orderGameById);

        $gameNames = Game::query()->whereIn('id', array_filter(array_keys($salesByGame)))->pluck('name', 'id');
        $totalSales = array_sum($salesByGame);

        $rows = [];

        foreach ($salesByGame as $gameId => $sales) {
            $rows[] = [
                'game_id' => $gameId ?: null,
                'game_name' => $gameId ? ($gameNames[$gameId] ?? 'Unknown Game') : 'Unknown Game',
                'sales' => $sales,
                'orders_count' => $ordersCountByGame[$gameId],
                'platform_profit' => $platformProfitByGame[$gameId] ?? 0,
                'reseller_profit' => $resellerProfitByGame[$gameId] ?? 0,
                'avg_order_value' => (int) round($sales / $ordersCountByGame[$gameId]),
                'pct_of_sales' => $totalSales > 0 ? round($sales / $totalSales * 100, 2) : 0.0,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['sales'] <=> $a['sales']);

        return $limit !== null ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * Payment Methods tab — sales/order share only (mirrors the
     * reference's "Sales by Payment Channel" panel), no profit split:
     * the payment channel a customer picked has no bearing on profit
     * recognition, which is delivery-driven, not payment-driven.
     */
    public function paymentMethodBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): array
    {
        $orders = $this->scopedOrders($from, $toExclusive, $resellerId)
            ->get(['payment_method', 'final_amount']);

        $salesByMethod = [];
        $ordersCountByMethod = [];

        foreach ($orders as $order) {
            $key = $order->payment_method ?? 'unknown';
            $salesByMethod[$key] = ($salesByMethod[$key] ?? 0) + $order->final_amount;
            $ordersCountByMethod[$key] = ($ordersCountByMethod[$key] ?? 0) + 1;
        }

        $totalSales = array_sum($salesByMethod);

        $rows = [];

        foreach ($salesByMethod as $method => $sales) {
            $rows[] = [
                'payment_method' => $method,
                'sales' => $sales,
                'orders_count' => $ordersCountByMethod[$method],
                'pct_of_sales' => $totalSales > 0 ? round($sales / $totalSales * 100, 2) : 0.0,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['sales'] <=> $a['sales']);

        return $rows;
    }

    /**
     * Resellers tab — reseller IS the grouping dimension here, so
     * $resellerId (when passed) just narrows the breakdown to that one
     * row rather than being the usual whole-scope filter.
     */
    public function resellerBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): array
    {
        $orders = $this->scopedOrders($from, $toExclusive, $resellerId)
            ->get(['id', 'reseller_id', 'final_amount']);

        $salesByReseller = [];
        $ordersCountByReseller = [];
        $orderResellerById = [];

        foreach ($orders as $order) {
            $key = $order->reseller_id ?? 0;
            $salesByReseller[$key] = ($salesByReseller[$key] ?? 0) + $order->final_amount;
            $ordersCountByReseller[$key] = ($ordersCountByReseller[$key] ?? 0) + 1;
            $orderResellerById[$order->id] = $key;
        }

        [$platformProfitByReseller, $resellerProfitByReseller] = $this->profitByKey($orderResellerById);

        $resellerNames = Reseller::query()->whereIn('id', array_filter(array_keys($salesByReseller)))->pluck('business_name', 'id');

        $rows = [];

        foreach ($salesByReseller as $id => $sales) {
            $rows[] = [
                'reseller_id' => $id ?: null,
                'reseller_name' => $id ? ($resellerNames[$id] ?? 'Unknown Reseller') : 'Unknown Reseller',
                'sales' => $sales,
                'orders_count' => $ordersCountByReseller[$id],
                'platform_profit' => $platformProfitByReseller[$id] ?? 0,
                'reseller_profit' => $resellerProfitByReseller[$id] ?? 0,
                'avg_order_value' => (int) round($sales / $ordersCountByReseller[$id]),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['sales'] <=> $a['sales']);

        return $rows;
    }

    /**
     * Membership tab (ADR-027 continued addendum decision 15 / Phase
     * 6.5, grilled 2026-08-29, Q10) — splits Paid-order sales by
     * `pricing_basis` (member vs standard), plus the two figures that
     * make the Costco model legible in the money views:
     *
     * - Margin forgone = Σ(normal_selling_price − selling_price) over
     *   member orders — the discount the platform gave members in cash
     *   terms (member orders always stamp normal_selling_price as the
     *   counterfactual retail; standard orders have it null).
     * - Membership fee revenue = Σ ledger type=membership_fee, the
     *   subscription income the member registry books — scoped by the
     *   ledger entry's own created_at against the same report range,
     *   since a fee is not an order and has no paid_at.
     */
    public function membershipBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): array
    {
        $orders = $this->scopedOrders($from, $toExclusive, $resellerId)
            ->get(['pricing_basis', 'final_amount', 'selling_price', 'normal_selling_price']);

        $memberSales = 0;
        $standardSales = 0;
        $memberCount = 0;
        $standardCount = 0;
        $marginForgone = 0;

        foreach ($orders as $order) {
            if ($order->pricing_basis === PricingBasis::Member) {
                $memberSales += $order->final_amount;
                $memberCount++;
                $marginForgone += max(0, ($order->normal_selling_price ?? 0) - ($order->selling_price ?? 0));
            } else {
                $standardSales += $order->final_amount;
                $standardCount++;
            }
        }

        $feeRevenueQuery = LedgerEntry::query()->where('type', 'membership_fee');

        if ($from !== null) {
            $feeRevenueQuery->where('created_at', '>=', $from);
        }

        if ($toExclusive !== null) {
            $feeRevenueQuery->where('created_at', '<', $toExclusive);
        }

        return [
            'member_sales' => $memberSales,
            'member_orders_count' => $memberCount,
            'standard_sales' => $standardSales,
            'standard_orders_count' => $standardCount,
            'margin_forgone' => $marginForgone,
            'membership_fee_revenue' => (int) $feeRevenueQuery->sum('amount'),
        ];
    }

    /**
     * Orders tab — delivery-status funnel among Paid orders (payment
     * already scoped by scopedOrders()). Distinct from the full
     * actionable order list at /admin/orders — this is a health-at-a-
     * glance count, not a worklist.
     */
    public function orderStatusFunnel(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): array
    {
        $counts = $this->scopedOrders($from, $toExclusive, $resellerId)
            ->selectRaw('delivery_status, COUNT(*) as count')
            ->groupBy('delivery_status')
            ->pluck('count', 'delivery_status');

        $byStatus = [];
        $total = 0;

        foreach (DeliveryStatus::cases() as $status) {
            $count = (int) ($counts[$status->value] ?? 0);
            $byStatus[$status->value] = $count;
            $total += $count;
        }

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'success_rate_pct' => $total > 0
                ? round($byStatus[DeliveryStatus::Delivered->value] / $total * 100, 2)
                : 0.0,
        ];
    }

    /**
     * RPT-3 export — one row per order (raw, not aggregated): the same
     * scope as summary(), joined to the order's actually-recognized
     * (ledger) profit rather than its cached column.
     */
    public function exportRows(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): Collection
    {
        $orders = $this->scopedOrders($from, $toExclusive, $resellerId)
            ->with('reseller:id,business_name')
            ->orderBy('paid_at')
            ->get(['id', 'order_number', 'paid_at', 'customer_email', 'final_amount', 'reseller_id']);

        $profitByOrder = LedgerEntry::query()
            ->where('type', 'order_profit')
            ->where('reference_type', 'order')
            ->whereIn('reference_id', $orders->pluck('id'))
            ->get(['reference_id', 'owner_type', 'amount'])
            ->groupBy('reference_id');

        return $orders->map(function (Order $order) use ($profitByOrder) {
            $entries = $profitByOrder->get($order->id, collect());

            return [
                'order_number' => $order->order_number,
                'paid_at' => $order->paid_at?->setTimezone(self::TIMEZONE)->toDateTimeString(),
                'customer_email' => $order->customer_email,
                'reseller_name' => $order->reseller?->business_name,
                'final_amount' => $order->final_amount,
                'platform_profit' => (int) $entries->where('owner_type', LedgerOwnerType::Platform->value)->sum('amount'),
                'reseller_profit' => (int) $entries->where('owner_type', LedgerOwnerType::Reseller->value)->sum('amount'),
            ];
        });
    }

    private function scopedOrders(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): Builder
    {
        $query = Order::query()
            ->where('is_test', false)
            ->where('payment_status', PaymentStatus::Paid->value)
            // Defensive floor, not just the year/month filter below: a
            // Paid order should always carry paid_at (see the
            // 2026-08-26 backfill migration for why this was ever
            // false on real data) — never let a null slip through as
            // an unscoped/epoch-dated row.
            ->whereNotNull('paid_at');

        if ($from !== null) {
            $query->where('paid_at', '>=', $from);
        }

        if ($toExclusive !== null) {
            $query->where('paid_at', '<', $toExclusive);
        }

        if ($resellerId !== null) {
            $query->where('reseller_id', $resellerId);
        }

        return $query;
    }

    private function profitTotals(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): array
    {
        $query = LedgerEntry::query()
            ->join('orders', function ($join) {
                $join->on('orders.id', '=', 'ledger_entries.reference_id')
                    ->where('ledger_entries.reference_type', '=', 'order');
            })
            ->where('ledger_entries.type', 'order_profit')
            ->where('orders.is_test', false)
            ->whereNotNull('orders.paid_at');

        if ($from !== null) {
            $query->where('orders.paid_at', '>=', $from);
        }

        if ($toExclusive !== null) {
            $query->where('orders.paid_at', '<', $toExclusive);
        }

        if ($resellerId !== null) {
            $query->where('orders.reseller_id', $resellerId);
        }

        $totals = $query->selectRaw('ledger_entries.owner_type as owner_type, SUM(ledger_entries.amount) as total')
            ->groupBy('ledger_entries.owner_type')
            ->pluck('total', 'owner_type');

        return [
            'platform' => (int) ($totals['platform'] ?? 0),
            'reseller' => (int) ($totals['reseller'] ?? 0),
        ];
    }

    /**
     * Generic order-id -> arbitrary group-key profit rollup (date, game
     * id, reseller id, ...) shared by every breakdown method above.
     *
     * @param  array<int, int|string>  $orderKeyById  order id => group key
     * @return array{0: array<int|string, int>, 1: array<int|string, int>}
     */
    private function profitByKey(array $orderKeyById): array
    {
        if ($orderKeyById === []) {
            return [[], []];
        }

        $entries = LedgerEntry::query()
            ->where('type', 'order_profit')
            ->where('reference_type', 'order')
            ->whereIn('reference_id', array_keys($orderKeyById))
            ->get(['reference_id', 'owner_type', 'amount']);

        $platformByKey = [];
        $resellerByKey = [];

        foreach ($entries as $entry) {
            $key = $orderKeyById[$entry->reference_id] ?? null;

            if ($key === null) {
                continue;
            }

            if ($entry->owner_type === LedgerOwnerType::Platform->value) {
                $platformByKey[$key] = ($platformByKey[$key] ?? 0) + $entry->amount;
            } elseif ($entry->owner_type === LedgerOwnerType::Reseller->value) {
                $resellerByKey[$key] = ($resellerByKey[$key] ?? 0) + $entry->amount;
            }
        }

        return [$platformByKey, $resellerByKey];
    }
}
