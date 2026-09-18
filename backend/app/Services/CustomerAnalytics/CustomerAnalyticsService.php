<?php

namespace App\Services\CustomerAnalytics;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ANL-1..4 (docs/prd.md §6.12). Grilled and pinned as ADR-049 before any
 * code was written — every rule below traces back to one of that ADR's
 * decisions, not an ad-hoc call made while coding:
 *
 * - "Customer" is a derived grouping of Order rows by `customer_email`,
 *   never a stored entity (ADR-049 decision 1) — this does not reopen
 *   ADR-011 (guest checkout, no Customer model/login).
 * - Money/order-count figures reuse ReportService's pinned rule
 *   verbatim (decision 2): `payment_status=Paid`, scoped by `paid_at`
 *   (never `created_at`), `final_amount`, `is_test` excluded. Not
 *   reused via a shared method call — ReportService's own scoping
 *   returns per-order rows, this needs a per-customer-email grouping,
 *   so the same rule is re-expressed here rather than forced through
 *   an incompatible shape.
 * - Segmentation is always computed from a customer's full LIFETIME
 *   order history, never re-sliced by the date-range filter (decision
 *   3) — segment membership is a standing attribute, not a snapshot.
 *   The date-range filter only narrows the displayed orders_count/
 *   total_spent figures (and which rows a CSV export contains).
 * - Only the VIP threshold is configurable (PlatformSettings,
 *   decision 5); Frequent/Dormant/New/One-time stay hardcoded
 *   constants below.
 * - Computed live on every request, no caching (decision 6) — matches
 *   ReportService's own precedent.
 * - Affiliate-aware from day one via an optional $affiliateId filter,
 *   mirroring ReportService/ADR-013 (decision 7).
 *
 * All money figures are integer sen. All "days ago" comparisons use
 * Asia/Kuala_Lumpur "now," matching ReportService's own timezone.
 *
 * **ADR-049/050 addendum (2026-09-18)** — reseller-wallet awareness.
 * A `wallet_reseller_id`-owned order (ADR-072/073) always carries
 * `affiliate_id = Affiliate::primary()->id` by construction (ADR-073
 * decision 5 — there is no whitelabel storefront involved), so before
 * this addendum every wallet-Reseller "customer" row showed up
 * indistinguishable from a genuine retail buyer of the primary brand —
 * same gap ADR-086 PR-2 already closed for Reports via its own
 * `resellerBreakdown()`. Extends decision 7's existing affiliate-filter
 * mechanism with a symmetric, independently-combinable `$resellerId`
 * filter (mirrors `ReportService::resellerBreakdown()`'s own
 * `wallet_reseller_id` grouping) rather than excluding wallet orders
 * from this population — the founder's explicit call: keep them
 * visible and filterable, don't hide them. Segmentation itself
 * (decision 4's classify()) is **deliberately unchanged** — a
 * high-volume reseller can still earn a VIP/Frequent tag under the
 * same rules as any other customer_email; the Source label (below) is
 * what tells an admin it's a B2B row, not a special-cased threshold.
 */
final class CustomerAnalyticsService
{
    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    private const FREQUENT_ORDERS_THRESHOLD = 20;

    private const DORMANT_AFTER_DAYS = 60;

    private const NEW_WITHIN_DAYS = 30;

    /**
     * ANL-1 — stats() is period-scoped like ReportService::summary():
     * total_customers/avg_order_value/top_spender all reflect the
     * selected date range (or all-time when none is given). repeat_rate
     * is the one exception — "has this customer ever repeated" is a
     * lifetime question by definition, so it's evaluated against each
     * scoped customer's lifetime order count, not their in-range count.
     */
    public function stats(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId, ?int $resellerId = null): array
    {
        $scoped = $this->aggregatesByEmail($from, $toExclusive, $affiliateId, $resellerId);

        $totalCustomers = count($scoped);
        $totalSpent = array_sum(array_column($scoped, 'total_spent'));
        $totalOrders = array_sum(array_column($scoped, 'orders_count'));

        $isFiltered = $from !== null || $toExclusive !== null;
        $lifetime = $isFiltered ? $this->aggregatesByEmail(null, null, $affiliateId, $resellerId) : $scoped;

        $repeatCustomers = 0;
        foreach (array_keys($scoped) as $email) {
            if (($lifetime[$email]['orders_count'] ?? 0) > 1) {
                $repeatCustomers++;
            }
        }

        $topSpenderEmail = null;
        $topSpenderAmount = 0;
        foreach ($scoped as $email => $agg) {
            if ($agg['total_spent'] > $topSpenderAmount) {
                $topSpenderAmount = $agg['total_spent'];
                $topSpenderEmail = $email;
            }
        }

        return [
            'total_customers' => $totalCustomers,
            'avg_order_value' => $totalOrders > 0 ? (int) round($totalSpent / $totalOrders) : 0,
            'repeat_rate_pct' => $totalCustomers > 0 ? round($repeatCustomers / $totalCustomers * 100, 2) : 0.0,
            'top_spender' => $topSpenderEmail !== null ? [
                'customer_email' => $topSpenderEmail,
                'customer_name' => $scoped[$topSpenderEmail]['customer_name'],
                'total_spent' => $topSpenderAmount,
            ] : null,
        ];
    }

    /**
     * ANL-3/4 — one row per customer. Segment (decision 4) is always
     * computed from lifetime data; orders_count/total_spent/last_order
     * reflect the date-range filter when one is given (decision 3). A
     * customer with zero orders inside a given range is excluded
     * entirely from a range-filtered view — nothing to show for them.
     *
     * @return list<array<string, mixed>>
     */
    public function customers(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId, ?CustomerSegment $segmentFilter, ?int $resellerId = null): array
    {
        $lifetime = $this->aggregatesByEmail(null, null, $affiliateId, $resellerId);

        $isFiltered = $from !== null || $toExclusive !== null;
        $period = $isFiltered ? $this->aggregatesByEmail($from, $toExclusive, $affiliateId, $resellerId) : null;

        $vipThresholdSen = PlatformSettings::current()->vip_spend_threshold_sen;

        $rows = [];

        foreach ($lifetime as $email => $agg) {
            $display = $agg;

            if ($period !== null) {
                if (! isset($period[$email])) {
                    continue;
                }

                $display = $period[$email];
            }

            $segment = $this->classify($agg, $vipThresholdSen);

            if ($segmentFilter !== null && $segment !== $segmentFilter) {
                continue;
            }

            $rows[] = [
                'customer_email' => $email,
                'customer_name' => $agg['customer_name'],
                'segment' => $segment?->value,
                'segment_label' => $segment?->label() ?? '—',
                'orders_count' => $display['orders_count'],
                'total_spent' => $display['total_spent'],
                'last_order_at' => $display['last_order_at']->setTimezone(self::TIMEZONE)->toIso8601String(),
                // ADR-049 addendum — always the LIFETIME wallet_reseller_id
                // (from $agg, never $display), same "identity is a lifetime
                // fact, figures are period-scoped" split the segment above
                // already follows.
                'wallet_reseller_id' => $agg['wallet_reseller_id'],
                'reseller_name' => $agg['reseller_name'],
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['total_spent'] <=> $a['total_spent']);

        return $rows;
    }

    /**
     * ANL-5 (ADR-050) — full drill-down for one customer. Returns null
     * if the email has no Paid, non-test orders at all.
     *
     * Two deliberately different scopes on one page (ADR-050 decision
     * 2): stats/monthly-trend/top-packages/top-affiliates/order-history
     * are Paid-scoped (same rule as the list screen); the Profit
     * Analysis panel is scoped to *delivered* orders only, so its five
     * lines (Revenue/Cost/Commission/Fees/Profit) share one population
     * and actually reconcile — a paid-but-undelivered order otherwise
     * contributes real spend but zero recognized profit (ReportService's
     * own rule), which would silently break that arithmetic.
     *
     * Always the customer's full lifetime data across every affiliate
     * (ADR-050 decision 6) — the $affiliateId list-filter is deliberately
     * not accepted here.
     *
     * @return array<string, mixed>|null
     */
    public function customerDetail(string $customerEmail): ?array
    {
        $orders = $this->scopedOrders(null)
            ->where('customer_email', $customerEmail)
            ->with(['package:id,name', 'affiliate:id,business_name', 'walletReseller:id,business_name'])
            ->orderByDesc('paid_at')
            ->get();

        if ($orders->isEmpty()) {
            return null;
        }

        $vipThresholdSen = PlatformSettings::current()->vip_spend_threshold_sen;

        $totalOrders = $orders->count();
        $totalSpent = (int) $orders->sum('final_amount');
        $firstOrderAt = CarbonImmutable::parse($orders->min('paid_at'));
        $lastOrderAt = CarbonImmutable::parse($orders->max('paid_at'));

        $segment = $this->classify([
            'total_spent' => $totalSpent,
            'orders_count' => $totalOrders,
            'first_order_at' => $firstOrderAt,
            'last_order_at' => $lastOrderAt,
        ], $vipThresholdSen);

        $delivered = $orders->where('delivery_status', DeliveryStatus::Delivered->value);

        $profitByOrder = LedgerEntry::query()
            ->where('type', 'order_profit')
            ->where('reference_type', 'order')
            ->whereIn('reference_id', $delivered->pluck('id'))
            ->get(['reference_id', 'owner_type', 'amount'])
            ->groupBy('reference_id');

        $affiliateCommission = 0;
        $systemProfit = 0;
        foreach ($profitByOrder as $entries) {
            $affiliateCommission += (int) $entries->where('owner_type', LedgerOwnerType::Affiliate->value)->sum('amount');
            $systemProfit += (int) $entries->where('owner_type', LedgerOwnerType::Platform->value)->sum('amount');
        }

        $firstNamedOrder = $orders->first(fn (Order $o) => $o->customer_name !== null);
        $firstPhonedOrder = $orders->first(fn (Order $o) => $o->customer_phone !== null);

        return [
            'customer_email' => $customerEmail,
            'customer_name' => $firstNamedOrder?->customer_name,
            'customer_phone' => $firstPhonedOrder?->customer_phone,
            'segment' => $segment?->value,
            'segment_label' => $segment?->label() ?? '—',
            'stats' => [
                'total_orders' => $totalOrders,
                'total_spent' => $totalSpent,
                'avg_order_value' => (int) round($totalSpent / $totalOrders),
                'customer_since' => $firstOrderAt->setTimezone(self::TIMEZONE)->toDateString(),
            ],
            'profit_analysis' => [
                'total_revenue' => (int) $delivered->sum('final_amount'),
                'supplier_cost' => (int) $delivered->sum('cost_price'),
                'affiliate_commission' => $affiliateCommission,
                'transaction_fees' => (int) $delivered->sum('transaction_fee'),
                'system_profit' => $systemProfit,
            ],
            'monthly_trend' => $this->monthlyTrend($orders),
            'top_packages' => $this->topSpendBreakdown(
                $orders,
                'package_id',
                fn (Order $o) => $o->package?->name ?? 'Unknown Package',
            ),
            // ADR-050 addendum — "Source" (not "Affiliate"): a wallet
            // Reseller order's affiliate_id is always the primary brand
            // (ADR-073 decision 5), so grouping by affiliate_id alone
            // would silently merge a reseller's wholesale volume into the
            // primary brand's own direct-sales bucket. Groups by a
            // composite key instead — 'reseller:{id}' or
            // 'affiliate:{id}' — so the two populations never collapse
            // into one row.
            'top_sources' => $this->topSpendBreakdown(
                $orders,
                fn (Order $o) => $o->wallet_reseller_id !== null ? 'reseller:'.$o->wallet_reseller_id : 'affiliate:'.$o->affiliate_id,
                fn (Order $o) => $o->wallet_reseller_id !== null
                    ? 'Reseller: '.($o->walletReseller?->business_name ?? 'Unknown Reseller')
                    : ($o->affiliate?->business_name ?? 'Unknown Affiliate'),
                fn (Order $o) => $o->wallet_reseller_id ?? $o->affiliate_id,
            ),
            'order_history' => $orders->map(function (Order $order) use ($profitByOrder) {
                // Order.delivery_status is cast to the DeliveryStatus enum
                // (App\Models\Order's own $casts) — comparing it to a raw
                // ->value string with === always fails silently (an enum
                // instance is never === its own backing scalar); compare
                // enum-to-enum instead.
                $isDelivered = $order->delivery_status === DeliveryStatus::Delivered;
                $entries = $profitByOrder->get($order->id);

                // ADR-050 addendum — same Source logic as top_sources
                // above, and the exact "Reseller: {name}" wording
                // Admin\OrderController's own Source column already uses.
                $sourceName = $order->wallet_reseller_id !== null
                    ? 'Reseller: '.($order->walletReseller?->business_name ?? 'Unknown Reseller')
                    : ($order->affiliate?->business_name ?? 'Unknown Affiliate');

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'paid_at' => $order->paid_at->setTimezone(self::TIMEZONE)->toIso8601String(),
                    'package_name' => $order->package?->name ?? 'Unknown Package',
                    'source_name' => $sourceName,
                    'final_amount' => $order->final_amount,
                    'affiliate_profit' => $isDelivered ? (int) $entries?->where('owner_type', LedgerOwnerType::Affiliate->value)->sum('amount') : null,
                    'system_profit' => $isDelivered ? (int) $entries?->where('owner_type', LedgerOwnerType::Platform->value)->sum('amount') : null,
                    'delivery_status' => $order->delivery_status,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Monthly spend, last 12 months (Asia/Kuala_Lumpur), Paid-scoped —
     * only emits months that actually had a paid order (same
     * only-non-empty-buckets convention ReportService::dailyBreakdown()
     * uses), oldest first.
     *
     * @return list<array{month: string, label: string, total_spent: int}>
     */
    private function monthlyTrend(Collection $orders): array
    {
        $windowStart = CarbonImmutable::now(self::TIMEZONE)->subMonths(11)->startOfMonth();

        $byMonth = [];

        foreach ($orders as $order) {
            $paidAtKl = CarbonImmutable::parse($order->paid_at)->setTimezone(self::TIMEZONE);

            if ($paidAtKl->lt($windowStart)) {
                continue;
            }

            $key = $paidAtKl->format('Y-m');
            $byMonth[$key] = ($byMonth[$key] ?? 0) + $order->final_amount;
        }

        ksort($byMonth);

        $rows = [];

        foreach ($byMonth as $month => $total) {
            $rows[] = [
                'month' => $month,
                'label' => CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', self::TIMEZONE)->format('M'),
                'total_spent' => $total,
            ];
        }

        return $rows;
    }

    /**
     * ADR-050 decision 4 — top 5 by spend, Paid-scoped, grouped by an
     * arbitrary FK column (package_id) or, since the ADR-050 addendum,
     * a computed composite key (top_sources' 'reseller:{id}'/
     * 'affiliate:{id}') — hence $groupKey now also accepts a callable,
     * and $idResolver lets a composite-key caller supply a real id
     * (Collection::groupBy() has no column to read one off of).
     *
     * @return list<array{id: int|string|null, name: string, orders_count: int, total_spent: int, pct_of_spend: float}>
     */
    private function topSpendBreakdown(Collection $orders, string|callable $groupKey, callable $nameResolver, ?callable $idResolver = null): array
    {
        $totalSpent = (int) $orders->sum('final_amount');

        $rows = $orders->groupBy($groupKey)->map(function (Collection $group) use ($groupKey, $nameResolver, $idResolver, $totalSpent) {
            $spent = (int) $group->sum('final_amount');
            $first = $group->first();

            return [
                'id' => $idResolver !== null ? $idResolver($first) : (is_string($groupKey) ? $first->{$groupKey} : null),
                'name' => $nameResolver($first),
                'orders_count' => $group->count(),
                'total_spent' => $spent,
                'pct_of_spend' => $totalSpent > 0 ? round($spent / $totalSpent * 100, 2) : 0.0,
            ];
        })->values()->all();

        usort($rows, fn (array $a, array $b) => $b['total_spent'] <=> $a['total_spent']);

        return array_slice($rows, 0, 5);
    }

    /**
     * ADR-049 decision 4's evaluation order, most-significant-first —
     * a customer matching more than one rule gets only the first match.
     * Always evaluated against $agg's LIFETIME figures, never the
     * date-range-scoped ones (decision 3).
     */
    private function classify(array $agg, int $vipThresholdSen): ?CustomerSegment
    {
        if ($agg['total_spent'] >= $vipThresholdSen) {
            return CustomerSegment::Vip;
        }

        if ($agg['orders_count'] >= self::FREQUENT_ORDERS_THRESHOLD) {
            return CustomerSegment::Frequent;
        }

        // diffInDays() is signed in this Carbon version (past->now is
        // positive, now->past is negative) — call it FROM the stored
        // past timestamp TO now, never the other way round, or a
        // negative result would silently satisfy both "< 30" and never
        // "> 60", corrupting every comparison below.
        if ($agg['last_order_at']->diffInDays(CarbonImmutable::now()) > self::DORMANT_AFTER_DAYS) {
            return CustomerSegment::Dormant;
        }

        if ($agg['first_order_at']->diffInDays(CarbonImmutable::now()) < self::NEW_WITHIN_DAYS) {
            return CustomerSegment::NewCustomer;
        }

        if ($agg['orders_count'] === 1) {
            return CustomerSegment::OneTime;
        }

        return null;
    }

    /**
     * Same Paid/is_test/paid_at scoping rule ReportService::
     * scopedOrders() applies (ADR-049 decision 2), re-expressed here
     * because this needs a per-customer-email GROUP BY rather than
     * ReportService's per-order rows.
     *
     * ADR-049 addendum — also resolves each email group's
     * `wallet_reseller_id` (`MAX()`, since a wallet-Reseller's own
     * customer_email is a dedicated bucket that in practice never mixes
     * wallet and non-wallet orders) and, when set, the Reseller's
     * `business_name` — the same batched-lookup shape the customer_name
     * pass below already uses, and the same `withTrashed()` precedent
     * `ReportService::resellerBreakdown()` uses so a since-removed
     * reseller's historical rows keep their name.
     *
     * @return array<string, array{orders_count: int, total_spent: int, first_order_at: CarbonImmutable, last_order_at: CarbonImmutable, customer_name: ?string, wallet_reseller_id: ?int, reseller_name: ?string}>
     */
    private function aggregatesByEmail(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $affiliateId, ?int $resellerId = null): array
    {
        $query = $this->scopedOrders($affiliateId, $resellerId);

        if ($from !== null) {
            $query->where('paid_at', '>=', $from);
        }

        if ($toExclusive !== null) {
            $query->where('paid_at', '<', $toExclusive);
        }

        $rows = $query
            ->selectRaw('customer_email, COUNT(*) as orders_count, SUM(final_amount) as total_spent, MIN(paid_at) as first_order_at, MAX(paid_at) as last_order_at, MAX(wallet_reseller_id) as wallet_reseller_id')
            ->groupBy('customer_email')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // customer_name is only ever a display convenience, not part of
        // the identity key (decision 1) — one extra pass grabs each
        // email's most recent non-null name.
        $names = Order::query()
            ->where('is_test', false)
            ->whereIn('customer_email', $rows->pluck('customer_email'))
            ->whereNotNull('customer_name')
            ->orderByDesc('paid_at')
            ->get(['customer_email', 'customer_name'])
            ->unique('customer_email')
            ->pluck('customer_name', 'customer_email');

        $resellerIds = $rows->pluck('wallet_reseller_id')->filter()->unique()->all();
        $resellerNames = $resellerIds !== []
            ? Reseller::query()->withTrashed()->whereIn('id', $resellerIds)->pluck('business_name', 'id')
            : collect();

        $result = [];

        foreach ($rows as $row) {
            $walletResellerId = $row->wallet_reseller_id !== null ? (int) $row->wallet_reseller_id : null;

            $result[$row->customer_email] = [
                'orders_count' => (int) $row->orders_count,
                'total_spent' => (int) $row->total_spent,
                'first_order_at' => CarbonImmutable::parse($row->first_order_at),
                'last_order_at' => CarbonImmutable::parse($row->last_order_at),
                'customer_name' => $names[$row->customer_email] ?? null,
                'wallet_reseller_id' => $walletResellerId,
                'reseller_name' => $walletResellerId !== null ? ($resellerNames[$walletResellerId] ?? 'Unknown Reseller') : null,
            ];
        }

        return $result;
    }

    private function scopedOrders(?int $affiliateId, ?int $resellerId = null): Builder
    {
        $query = Order::query()
            ->where('is_test', false)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereNotNull('paid_at');

        if ($affiliateId !== null) {
            $query->where('affiliate_id', $affiliateId);
        }

        // ADR-049 addendum — independently combinable with $affiliateId
        // above (mirrors ReportService's own filter shape): a
        // wallet-Reseller order's affiliate_id is always the primary
        // brand (ADR-073 decision 5), so picking a non-primary Affiliate
        // AND a Reseller at once naturally returns nothing — not a
        // conflict to guard against, the same way an impossible
        // affiliate+date combination already just returns an empty list.
        if ($resellerId !== null) {
            $query->where('wallet_reseller_id', $resellerId);
        }

        return $query;
    }
}
