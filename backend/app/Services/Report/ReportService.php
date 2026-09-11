<?php

namespace App\Services\Report;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\PricingBasis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RPT-1..3 (docs/prd.md §6.9). Every figure here is grilled and pinned
 * (2026-08-26 session) against two hard rules:
 *
 * - "Sales"/"orders count"/"latest order" only ever count
 *   payment_status=Paid orders, scoped by `paid_at` (when money actually
 *   arrived), never `created_at` (when checkout merely started) — an
 *   abandoned/still-pending checkout has no business value yet.
 * - "Profit" is never read off Order.platform_profit/affiliate_profit
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
 *
 * ADR-086 PR-1 (2026-09-11): every breakdown below aggregates in SQL
 * (`GROUP BY`), not PHP `->get()`+`foreach` — a null-year (all-time)
 * filter used to pull every paid order in history into memory on each
 * Reports-tab load. The #1 correctness risk in doing this is that every
 * delivered order carries **two** `order_profit` ledger rows (a
 * platform-split and an affiliate-split) — a single query that JOINs
 * `orders` to `ledger_entries` and also sums `final_amount` in that same
 * row set double-counts sales, since the order row is matched once per
 * ledger row. The fix, applied consistently: sales/count are always
 * aggregated from `orders` alone (`salesByGroup()`), profit is always
 * aggregated from `ledger_entries` JOINed to `orders` but *only* summing
 * `ledger_entries.amount`, grouped by `(key, owner_type)`
 * (`profitByGroup()`) — the two never share a row set, then get merged
 * by group key in PHP. `tests/Feature/Services/Report/ReportServiceTest.php`'s
 * `*_sales_not_doubled_by_dual_ledger_rows` tests lock this in per
 * dimension.
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

    public function summary(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): array
    {
        // One pass for the two aggregates (was a separate ->sum() and
        // ->count()); the latest-order row can't fold into a GROUP-less
        // aggregate, so it stays its own query — two, down from three.
        $totals = $this->scopedOrders($from, $toExclusive, $affiliateId)
            ->selectRaw('COALESCE(SUM(final_amount), 0) as total_sales, COUNT(*) as orders_count')
            ->first();
        $totalSales = (int) $totals->total_sales;
        $ordersCount = (int) $totals->orders_count;

        $latest = $this->scopedOrders($from, $toExclusive, $affiliateId)
            ->orderByDesc('paid_at')
            ->first(['order_number', 'paid_at', 'customer_email', 'final_amount']);

        $profit = $this->profitTotals($from, $toExclusive, $affiliateId);

        return [
            'total_sales' => $totalSales,
            'orders_count' => $ordersCount,
            'platform_profit' => $profit['platform'],
            'affiliate_profit' => $profit['affiliate'],
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
    public function dailyTrend(int $days, ?int $affiliateId): array
    {
        $todayKl = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $startKl = $todayKl->subDays($days - 1);
        $fromUtc = $startKl->setTimezone('UTC');
        $toExclusiveUtc = $todayKl->addDay()->setTimezone('UTC');

        $salesByDate = $this->salesByGroup($this->scopedOrders($fromUtc, $toExclusiveUtc, $affiliateId), $this->dayBucketExpr('paid_at'));
        $profitByDate = $this->profitByGroup($fromUtc, $toExclusiveUtc, $affiliateId, $this->dayBucketExpr('orders.paid_at'));

        $rows = [];
        $cursor = $startKl;

        while ($cursor->lte($todayKl)) {
            $key = $cursor->toDateString();
            $profit = $profitByDate->get($key);
            $rows[] = [
                'date' => $key,
                'sales' => (int) ($salesByDate->get($key)->sales ?? 0),
                'platform_profit' => $profit['platform'] ?? 0,
                'affiliate_profit' => $profit['affiliate'] ?? 0,
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
    public function dailyBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): array
    {
        $dayExpr = $this->dayBucketExpr('paid_at');

        $sales = $this->scopedOrders($from, $toExclusive, $affiliateId)
            ->selectRaw("{$dayExpr} as report_key, COALESCE(SUM(final_amount), 0) as sales, COUNT(*) as orders_count, COALESCE(SUM(transaction_fee), 0) as transaction_fees")
            ->groupBy('report_key')
            ->get()
            ->keyBy('report_key');

        $profitByDate = $this->profitByGroup($from, $toExclusive, $affiliateId, $this->dayBucketExpr('orders.paid_at'));

        $rows = [];

        foreach ($sales as $date => $row) {
            $ordersCount = (int) $row->orders_count;
            $profit = $profitByDate->get($date);

            $rows[] = [
                'date' => $date,
                'orders_count' => $ordersCount,
                'sales' => (int) $row->sales,
                'platform_profit' => $profit['platform'] ?? 0,
                'affiliate_profit' => $profit['affiliate'] ?? 0,
                'transaction_fees' => (int) $row->transaction_fees,
                'avg_order_value' => (int) round($row->sales / $ordersCount),
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
    public function gameBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId, ?int $limit = null): array
    {
        $sales = $this->salesByGroup($this->scopedOrders($from, $toExclusive, $affiliateId), 'COALESCE(game_id, 0)');
        $profitByGame = $this->profitByGroup($from, $toExclusive, $affiliateId, 'COALESCE(orders.game_id, 0)');

        $gameIds = $sales->keys()->reject(fn ($id) => (int) $id === 0)->all();
        $gameNames = Game::query()->whereIn('id', $gameIds)->pluck('name', 'id');
        $totalSales = (int) $sales->sum('sales');

        $rows = [];

        foreach ($sales as $gameId => $row) {
            $gameIdInt = (int) $gameId;
            $ordersCount = (int) $row->orders_count;
            $profit = $profitByGame->get($gameId);

            $rows[] = [
                'game_id' => $gameIdInt ?: null,
                'game_name' => $gameIdInt ? ($gameNames[$gameIdInt] ?? 'Unknown Game') : 'Unknown Game',
                'sales' => (int) $row->sales,
                'orders_count' => $ordersCount,
                'platform_profit' => $profit['platform'] ?? 0,
                'affiliate_profit' => $profit['affiliate'] ?? 0,
                'avg_order_value' => (int) round($row->sales / $ordersCount),
                'pct_of_sales' => $totalSales > 0 ? round($row->sales / $totalSales * 100, 2) : 0.0,
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
    public function paymentMethodBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): array
    {
        $sales = $this->salesByGroup($this->scopedOrders($from, $toExclusive, $affiliateId), "COALESCE(payment_method, 'unknown')");
        $totalSales = (int) $sales->sum('sales');

        $rows = [];

        foreach ($sales as $method => $row) {
            $rows[] = [
                'payment_method' => $method,
                'sales' => (int) $row->sales,
                'orders_count' => (int) $row->orders_count,
                'pct_of_sales' => $totalSales > 0 ? round($row->sales / $totalSales * 100, 2) : 0.0,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['sales'] <=> $a['sales']);

        return $rows;
    }

    /**
     * Affiliates tab — affiliate IS the grouping dimension here, so
     * $affiliateId (when passed) just narrows the breakdown to that one
     * row rather than being the usual whole-scope filter.
     */
    public function affiliateBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): array
    {
        $sales = $this->salesByGroup($this->scopedOrders($from, $toExclusive, $affiliateId), 'COALESCE(affiliate_id, 0)');
        $profitByAffiliate = $this->profitByGroup($from, $toExclusive, $affiliateId, 'COALESCE(orders.affiliate_id, 0)');

        $affiliateIds = $sales->keys()->reject(fn ($id) => (int) $id === 0)->all();
        $affiliateNames = Affiliate::query()->whereIn('id', $affiliateIds)->pluck('business_name', 'id');

        $rows = [];

        foreach ($sales as $id => $row) {
            $idInt = (int) $id;
            $ordersCount = (int) $row->orders_count;
            $profit = $profitByAffiliate->get($id);

            $rows[] = [
                'affiliate_id' => $idInt ?: null,
                'affiliate_name' => $idInt ? ($affiliateNames[$idInt] ?? 'Unknown Affiliate') : 'Unknown Affiliate',
                'sales' => (int) $row->sales,
                'orders_count' => $ordersCount,
                'platform_profit' => $profit['platform'] ?? 0,
                'affiliate_profit' => $profit['affiliate'] ?? 0,
                'avg_order_value' => (int) round($row->sales / $ordersCount),
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
    public function membershipBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): array
    {
        $member = PricingBasis::Member->value;

        // A single aggregate pass, `pricing_basis` as the CASE-WHEN
        // discriminant instead of a GROUP BY — only 2 buckets, and this
        // avoids fetching every order row into PHP. `margin_forgone` is
        // (normal_selling_price - selling_price) floored at 0 via the
        // CASE itself (portable across MySQL/sqlite — no GREATEST()/MAX()
        // scalar-function mismatch between the two).
        $totals = $this->scopedOrders($from, $toExclusive, $affiliateId)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN pricing_basis = ? THEN final_amount ELSE 0 END), 0) as member_sales,'
                .' SUM(CASE WHEN pricing_basis = ? THEN 1 ELSE 0 END) as member_orders_count,'
                .' COALESCE(SUM(CASE WHEN pricing_basis != ? THEN final_amount ELSE 0 END), 0) as standard_sales,'
                .' SUM(CASE WHEN pricing_basis != ? THEN 1 ELSE 0 END) as standard_orders_count,'
                .' COALESCE(SUM(CASE WHEN pricing_basis = ? AND (COALESCE(normal_selling_price, 0) - COALESCE(selling_price, 0)) > 0'
                .' THEN (COALESCE(normal_selling_price, 0) - COALESCE(selling_price, 0)) ELSE 0 END), 0) as margin_forgone',
                [$member, $member, $member, $member, $member],
            )
            ->first();

        $feeRevenueQuery = LedgerEntry::query()->where('type', 'membership_fee');

        if ($from !== null) {
            $feeRevenueQuery->where('created_at', '>=', $from);
        }

        if ($toExclusive !== null) {
            $feeRevenueQuery->where('created_at', '<', $toExclusive);
        }

        return [
            'member_sales' => (int) $totals->member_sales,
            'member_orders_count' => (int) $totals->member_orders_count,
            'standard_sales' => (int) $totals->standard_sales,
            'standard_orders_count' => (int) $totals->standard_orders_count,
            'margin_forgone' => (int) $totals->margin_forgone,
            'membership_fee_revenue' => (int) $feeRevenueQuery->sum('amount'),
        ];
    }

    /**
     * Orders tab — delivery-status funnel among Paid orders (payment
     * already scoped by scopedOrders()). Distinct from the full
     * actionable order list at /admin/orders — this is a health-at-a-
     * glance count, not a worklist.
     */
    public function orderStatusFunnel(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): array
    {
        $counts = $this->scopedOrders($from, $toExclusive, $affiliateId)
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
    public function exportRows(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): Collection
    {
        $orders = $this->scopedOrders($from, $toExclusive, $affiliateId)
            ->with('affiliate:id,business_name')
            ->orderBy('paid_at')
            ->get(['id', 'order_number', 'paid_at', 'customer_email', 'final_amount', 'affiliate_id']);

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
                'affiliate_name' => $order->affiliate?->business_name,
                'final_amount' => $order->final_amount,
                'platform_profit' => (int) $entries->where('owner_type', LedgerOwnerType::Platform->value)->sum('amount'),
                'affiliate_profit' => (int) $entries->where('owner_type', LedgerOwnerType::Affiliate->value)->sum('amount'),
            ];
        });
    }

    private function scopedOrders(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): Builder
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

        if ($affiliateId !== null) {
            $query->where('affiliate_id', $affiliateId);
        }

        return $query;
    }

    private function profitTotals(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): array
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

        if ($affiliateId !== null) {
            $query->where('orders.affiliate_id', $affiliateId);
        }

        $totals = $query->selectRaw('ledger_entries.owner_type as owner_type, SUM(ledger_entries.amount) as total')
            ->groupBy('ledger_entries.owner_type')
            ->pluck('total', 'owner_type');

        return [
            'platform' => (int) ($totals['platform'] ?? 0),
            'affiliate' => (int) ($totals['affiliate'] ?? 0),
        ];
    }

    /**
     * Generic sales+count rollup grouped by an arbitrary SQL expression
     * (a day bucket, `game_id`, `payment_method`, `affiliate_id`, ...),
     * shared by every breakdown method above. `$groupExpr` is a fixed,
     * hardcoded-per-callsite SQL fragment — never user input.
     */
    private function salesByGroup(Builder $ordersQuery, string $groupExpr): Collection
    {
        return $ordersQuery
            ->selectRaw("{$groupExpr} as report_key, COALESCE(SUM(final_amount), 0) as sales, COUNT(*) as orders_count")
            ->groupBy('report_key')
            ->get()
            ->keyBy('report_key');
    }

    /**
     * Generic profit rollup grouped by an arbitrary SQL expression over
     * the JOINed `orders` table (a day bucket, `orders.game_id`,
     * `orders.affiliate_id`, ...) — the counterpart to `salesByGroup()`.
     *
     * Deliberately never selects/sums `orders.final_amount` in this
     * joined row set — see this class's own doc comment for why (every
     * delivered order has two `order_profit` ledger rows, so summing
     * `final_amount` here would double it). Only `ledger_entries.amount`
     * is summed, grouped by `(key, owner_type)`, which is exactly what
     * the old row-by-row PHP accumulation did — just pushed into SQL.
     * `$groupExpr` is a fixed, hardcoded-per-callsite SQL fragment —
     * never user input.
     *
     * @return Collection<string, array{platform: int, affiliate: int}>
     */
    private function profitByGroup(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId, string $groupExpr): Collection
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

        if ($affiliateId !== null) {
            $query->where('orders.affiliate_id', $affiliateId);
        }

        return $query
            ->selectRaw("{$groupExpr} as report_key, ledger_entries.owner_type as owner_type, SUM(ledger_entries.amount) as total")
            ->groupBy('report_key', 'ledger_entries.owner_type')
            ->get()
            ->groupBy('report_key')
            ->map(fn (Collection $rows) => [
                'platform' => (int) ($rows->firstWhere('owner_type', LedgerOwnerType::Platform->value)->total ?? 0),
                'affiliate' => (int) ($rows->firstWhere('owner_type', LedgerOwnerType::Affiliate->value)->total ?? 0),
            ]);
    }

    /**
     * Asia/Kuala_Lumpur (fixed UTC+8, no DST) day-bucket SQL expression
     * for a UTC `datetime` column. Driver-conditional because the fast
     * test suite runs sqlite (`phpunit.xml`) while production is MySQL —
     * `CONVERT_TZ()` doesn't exist in sqlite, and sqlite's date functions
     * don't exist in MySQL. `$column` is a fixed, hardcoded-per-callsite
     * identifier — never user input.
     */
    private function dayBucketExpr(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date({$column}, '+8 hours')",
            default => "DATE(CONVERT_TZ({$column}, '+00:00', '+08:00'))",
        };
    }
}
