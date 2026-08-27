<?php

namespace App\Services\CustomerAnalytics;

use App\Models\Order;
use App\Models\PlatformSettings;
use App\Services\Order\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

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
 * - Reseller-aware from day one via an optional $resellerId filter,
 *   mirroring ReportService/ADR-013 (decision 7).
 *
 * All money figures are integer sen. All "days ago" comparisons use
 * Asia/Kuala_Lumpur "now," matching ReportService's own timezone.
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
    public function stats(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): array
    {
        $scoped = $this->aggregatesByEmail($from, $toExclusive, $resellerId);

        $totalCustomers = count($scoped);
        $totalSpent = array_sum(array_column($scoped, 'total_spent'));
        $totalOrders = array_sum(array_column($scoped, 'orders_count'));

        $isFiltered = $from !== null || $toExclusive !== null;
        $lifetime = $isFiltered ? $this->aggregatesByEmail(null, null, $resellerId) : $scoped;

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
    public function customers(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId, ?CustomerSegment $segmentFilter): array
    {
        $lifetime = $this->aggregatesByEmail(null, null, $resellerId);

        $isFiltered = $from !== null || $toExclusive !== null;
        $period = $isFiltered ? $this->aggregatesByEmail($from, $toExclusive, $resellerId) : null;

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
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['total_spent'] <=> $a['total_spent']);

        return $rows;
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
     * @return array<string, array{orders_count: int, total_spent: int, first_order_at: CarbonImmutable, last_order_at: CarbonImmutable, customer_name: ?string}>
     */
    private function aggregatesByEmail(?CarbonImmutable $from, ?CarbonImmutable $toExclusive, ?int $resellerId): array
    {
        $query = $this->scopedOrders($resellerId);

        if ($from !== null) {
            $query->where('paid_at', '>=', $from);
        }

        if ($toExclusive !== null) {
            $query->where('paid_at', '<', $toExclusive);
        }

        $rows = $query
            ->selectRaw('customer_email, COUNT(*) as orders_count, SUM(final_amount) as total_spent, MIN(paid_at) as first_order_at, MAX(paid_at) as last_order_at')
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

        $result = [];

        foreach ($rows as $row) {
            $result[$row->customer_email] = [
                'orders_count' => (int) $row->orders_count,
                'total_spent' => (int) $row->total_spent,
                'first_order_at' => CarbonImmutable::parse($row->first_order_at),
                'last_order_at' => CarbonImmutable::parse($row->last_order_at),
                'customer_name' => $names[$row->customer_email] ?? null,
            ];
        }

        return $result;
    }

    private function scopedOrders(?int $resellerId): Builder
    {
        $query = Order::query()
            ->where('is_test', false)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereNotNull('paid_at');

        if ($resellerId !== null) {
            $query->where('reseller_id', $resellerId);
        }

        return $query;
    }
}
