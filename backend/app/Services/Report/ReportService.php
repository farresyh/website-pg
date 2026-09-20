<?php

namespace App\Services\Report;

use App\Models\Affiliate;
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
     *
     * Kept alongside `dateRangeFromDates()` below (added 2026-09-11 for
     * the Reports page's own filter-unification) because
     * `CustomerAnalyticsController` (ANL-1..4, ADR-049) still uses this
     * one for its own, unrelated year/month filter — Customer Analytics'
     * UX wasn't part of that change and stays as it is.
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

    /**
     * ADR-086 filter-unification follow-up (2026-09-11) — replaces the
     * Reports page's old separate Year/Month picker with one date-range
     * filter that every tab, including the trend chart, resolves the
     * same way. `$fromDate`/`$toDate` are KL calendar dates
     * ('YYYY-MM-DD'), inclusive on both ends from the caller's point of
     * view — `$toDate` is converted to an exclusive UTC instant (KL
     * midnight of the *next* day) so callers keep comparing with `<`,
     * never `<=`. Either or both null means "no bound" on that side —
     * both null is the "All time" filter.
     */
    public function dateRangeFromDates(?string $fromDate, ?string $toDate): array
    {
        $from = $fromDate !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $fromDate, self::TIMEZONE)->startOfDay()->setTimezone('UTC')
            : null;

        $toExclusive = $toDate !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $toDate, self::TIMEZONE)->startOfDay()->addDay()->setTimezone('UTC')
            : null;

        return [$from, $toExclusive];
    }

    public function summary(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): array
    {
        // One pass for the two aggregates (was a separate ->sum() and
        // ->count()); the latest-order row can't fold into a GROUP-less
        // aggregate, so it stays its own query — two, down from three.
        $totals = $this->scopedOrders($from, $toExclusive, $affiliateId)
            ->selectRaw("COALESCE(SUM({$this->netSalesExpr()}), 0) as total_sales, COUNT(*) as orders_count")
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
     * ADR-086 filter-unification follow-up (2026-09-11) — the trend chart
     * now follows the SAME date-range filter as every other tab, instead
     * of its own private "last N days from today" toggle (RPT-2's
     * original 2026-08-26 rule, reversed after the founder found the two
     * filters silently disagreeing in production). `$from`/`$toExclusive`
     * are always concrete (never null) — `ReportController` is
     * responsible for substituting a bounded fallback window (the
     * previous last-30-days default) when the page's resolved filter is
     * unbounded ("All time"), so this method never has to guess a range
     * to zero-fill.
     *
     * Both sales and profit are attributed to the order's own `paid_at`
     * day (KL), even though profit may only be ledger-credited later
     * once delivery completes — grilled 2026-08-26: acceptable because
     * delivery on this platform is near-instant in practice, and this
     * keeps the sales/profit overlay visually matched day-for-day.
     */
    public function dailyTrend(CarbonImmutable $from, CarbonImmutable $toExclusive, ?int $affiliateId): array
    {
        $salesByDate = $this->salesByGroup($this->scopedOrders($from, $toExclusive, $affiliateId), $this->dayBucketExpr('paid_at'));
        $profitByDate = $this->profitByGroup($from, $toExclusive, $affiliateId, $this->dayBucketExpr('orders.paid_at'));

        $startKl = $from->setTimezone(self::TIMEZONE)->startOfDay();
        $endKlExclusive = $toExclusive->setTimezone(self::TIMEZONE)->startOfDay();

        $rows = [];
        $cursor = $startKl;

        while ($cursor->lt($endKlExclusive)) {
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
     * Overview tab's Detailed Data Table. Only emits days that actually
     * had a paid order — unlike dailyTrend(), which zero-fills every day
     * in range (needed for a continuous chart line); doing that here too
     * for an unbounded/all-time range would be an unbounded row count.
     * Newest first, matching the reference layout.
     */
    public function dailyBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): array
    {
        $dayExpr = $this->dayBucketExpr('paid_at');

        $sales = $this->scopedOrders($from, $toExclusive, $affiliateId)
            ->selectRaw("{$dayExpr} as report_key, COALESCE(SUM({$this->netSalesExpr()}), 0) as sales, COUNT(*) as orders_count, COALESCE(SUM(transaction_fee), 0) as transaction_fees")
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
     * ADR-086 PR-2 — Reseller-wallet breakdown. `wallet_reseller_id` (ADR-
     * 072..075) is a column distinct from `affiliate_id`: a wallet order
     * is placed by a prepaid-wallet Reseller account, not an Affiliate
     * whitelabel brand. A wallet order's `affiliate_profit` is always 0
     * (no revenue-share channel — the reseller pays the wholesale price
     * and the platform keeps the full margin), so this breakdown only
     * ever reports platform profit, not an affiliate split.
     *
     * Unlike affiliateBreakdown(), the "not a wallet order" (key 0)
     * bucket is dropped rather than surfaced as "Unknown" — it's the
     * overwhelming majority of normal orders, already covered by
     * affiliateBreakdown()/the rest of Reports, and showing it here
     * as "Unknown Reseller" would misleadingly imply those sales are
     * reseller-channel activity. `withTrashed()` on the name lookup so a
     * since-removed reseller's historical sales keep their name, not
     * "Unknown Reseller".
     */
    public function resellerBreakdown(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): array
    {
        $sales = $this->salesByGroup($this->scopedOrders($from, $toExclusive, $affiliateId), 'COALESCE(wallet_reseller_id, 0)');
        $profitByReseller = $this->profitByGroup($from, $toExclusive, $affiliateId, 'COALESCE(orders.wallet_reseller_id, 0)');

        $resellerIds = $sales->keys()->reject(fn ($id) => (int) $id === 0)->all();
        $resellerNames = Reseller::query()->withTrashed()->whereIn('id', $resellerIds)->pluck('business_name', 'id');

        $rows = [];

        foreach ($sales as $id => $row) {
            $idInt = (int) $id;

            if ($idInt === 0) {
                continue;
            }

            $ordersCount = (int) $row->orders_count;
            $profit = $profitByReseller->get($id);

            $rows[] = [
                'reseller_id' => $idInt,
                'reseller_name' => $resellerNames[$idInt] ?? 'Unknown Reseller',
                'sales' => (int) $row->sales,
                'orders_count' => $ordersCount,
                'platform_profit' => $profit['platform'] ?? 0,
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
        $standard = PricingBasis::Standard->value;

        // A single aggregate pass, `pricing_basis` as the CASE-WHEN
        // discriminant instead of a GROUP BY — only 2 buckets, and this
        // avoids fetching every order row into PHP. `margin_forgone` is
        // (normal_selling_price - selling_price) floored at 0 via the
        // CASE itself (portable across MySQL/sqlite — no GREATEST()/MAX()
        // scalar-function mismatch between the two).
        //
        // 2026-09-21 fix (ADR-086 addendum) — `standard_sales` used to be
        // `pricing_basis != 'member'`, which silently absorbed
        // reseller-wallet and affiliate-wholesale-tier orders too (4
        // real values exist: standard/member/reseller-wallet/affiliate —
        // found live in prod: "Standard (guest)" was 93% wholesale
        // reseller volume). Now an exact `= 'standard'` match; the other
        // two non-member bases are excluded from this tab entirely —
        // they have their own dedicated breakdowns
        // (resellerBreakdown()/affiliateBreakdown()).
        $totals = $this->scopedOrders($from, $toExclusive, $affiliateId)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN pricing_basis = ? THEN final_amount ELSE 0 END), 0) as member_sales,'
                .' SUM(CASE WHEN pricing_basis = ? THEN 1 ELSE 0 END) as member_orders_count,'
                .' COALESCE(SUM(CASE WHEN pricing_basis = ? THEN final_amount ELSE 0 END), 0) as standard_sales,'
                .' SUM(CASE WHEN pricing_basis = ? THEN 1 ELSE 0 END) as standard_orders_count,'
                .' COALESCE(SUM(CASE WHEN pricing_basis = ? AND (COALESCE(normal_selling_price, 0) - COALESCE(selling_price, 0)) > 0'
                .' THEN (COALESCE(normal_selling_price, 0) - COALESCE(selling_price, 0)) ELSE 0 END), 0) as margin_forgone',
                [$member, $member, $standard, $standard, $member],
            )
            ->first();

        // 2026-09-21 fix (ADR-086 addendum) — this used to never apply
        // $affiliateId at all, even though every other figure in this
        // breakdown does. Membership is genuinely per-affiliate
        // (`memberships.(affiliate_id, email)`, ADR-061 PR-B decision
        // 5), so joins through `memberships` on the ledger entry's own
        // `reference_id` (`reference_type='membership'`) the same way
        // the LLM Report Assistant's own `llm_report_membership_fees`
        // view already does — one source of truth for this join, not
        // two that can drift.
        $feeRevenueQuery = LedgerEntry::query()
            ->join('memberships', function ($join) {
                $join->on('memberships.id', '=', 'ledger_entries.reference_id')
                    ->where('ledger_entries.reference_type', '=', 'membership');
            })
            ->where('ledger_entries.type', 'membership_fee');

        if ($from !== null) {
            $feeRevenueQuery->where('ledger_entries.created_at', '>=', $from);
        }

        if ($toExclusive !== null) {
            $feeRevenueQuery->where('ledger_entries.created_at', '<', $toExclusive);
        }

        if ($affiliateId !== null) {
            $feeRevenueQuery->where('memberships.affiliate_id', $affiliateId);
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
    /**
     * ADR-086 export-widening follow-up (2026-09-11) — RPT-3's export is
     * meant to be a self-sufficient source for external pivot analysis
     * (Excel/Power BI), which the original 7-column shape couldn't
     * actually support: it had no Game/Payment Method/Pricing Basis/
     * Reseller columns, so none of those breakdowns could be reconstructed
     * from the exported file. Widened to carry every dimension the
     * on-screen breakdown tabs already group by. Column naming/values
     * deliberately mirror `Admin\OrderController`'s own detail response
     * (`game.name`, `package.name`, `wallet_reseller.business_name`) —
     * same terms an admin already knows from `/admin/orders`, not
     * export-only labels. `delivery_status` is included too: a paid
     * order's `platform_profit`/`affiliate_profit` here can legitimately
     * be RM0 while `/admin/orders` still shows its checkout-time-stamped
     * (non-ledger) snapshot for a failed delivery — this column is what
     * explains that discrepancy to whoever's reading the export.
     */
    public function exportRows(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId): Collection
    {
        $orders = $this->scopedOrders($from, $toExclusive, $affiliateId)
            ->with(['affiliate:id,business_name', 'game:id,name', 'package:id,name', 'walletReseller:id,business_name'])
            ->orderBy('paid_at')
            ->get([
                'id', 'order_number', 'paid_at', 'customer_email', 'final_amount',
                'affiliate_id', 'game_id', 'package_id', 'wallet_reseller_id',
                'payment_method', 'pricing_basis', 'delivery_status',
            ]);

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
                'game_name' => $order->game?->name,
                'package_name' => $order->package?->name,
                'payment_method' => $order->payment_method,
                'pricing_basis' => $order->pricing_basis === PricingBasis::Member ? 'Member' : 'Standard',
                'reseller_name' => $order->walletReseller?->business_name,
                'delivery_status' => $order->delivery_status->value,
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
            ->selectRaw("{$groupExpr} as report_key, COALESCE(SUM({$this->netSalesExpr()}), 0) as sales, COUNT(*) as orders_count")
            ->groupBy('report_key')
            ->get()
            ->keyBy('report_key');
    }

    /**
     * 2026-09-21 fix (ADR-086 addendum, Bug 4) — a reseller-wallet
     * order's compensation (`wallet_refund` ledger entry, ADR-073/
     * ADR-102) genuinely reverses the wallet debit, unlike a storefront
     * Voucher (whose `voucher_discount` already nets out of a later
     * redeeming order's own `final_amount` via
     * `CheckoutTotalService::calculate()` — no double count there, so
     * this expression only needs to cover the wallet-refund case).
     *
     * Net Sales = Gross `final_amount` − Σ(`wallet_refund` ledger amount
     * for THIS order) — a correlated subquery scoped by `orders.id`
     * (never the refund's own `created_at`), so a refund posted on a
     * later day still nets against the day the order was originally
     * *paid* (Q5's own "each day stays self-contained" call), not the
     * day it happened to be reversed. Real prod evidence this fixes:
     * reseller "Naeem Industries" order 27 (RM343.51, failed+refunded
     * same day) and "FixFast" order 5 (92 sen, refunded then re-spent on
     * two later orders) both inflated Sales before this fix.
     */
    private function netSalesExpr(): string
    {
        return "final_amount - COALESCE((SELECT SUM(wr.amount) FROM ledger_entries wr WHERE wr.reference_type = 'order' AND wr.reference_id = orders.id AND wr.type = 'wallet_refund'), 0)";
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
