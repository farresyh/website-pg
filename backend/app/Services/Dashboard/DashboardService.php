<?php

namespace App\Services\Dashboard;

use App\Models\Order;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\OpenWa\OpenWaSessionStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DASH-1..6 (docs/prd.md §6.2, ADR-045). Every figure here is grilled
 * and pinned (2026-08-27 session, 27 decisions) the same way
 * ReportService's own figures were — see this class's individual
 * method doc comments for each one's exact source.
 *
 * Every returned metric carries its own `definition` string (ADR-045
 * decision 25) generated right next to the calculation it describes,
 * so a future change to the calculation and its own documentation can
 * never drift apart into two separate files.
 */
final class DashboardService
{
    public const TIMEZONE = ReportService::TIMEZONE;

    /** `orders` is the only queue this screen monitors (ADR-045 decision 9) — see DashboardService::health(). */
    private const MONITORED_QUEUE = 'orders';

    public function __construct(
        private readonly ReportService $reports,
        private readonly OpenWaSessionStatus $openWaSessionStatus,
    ) {}

    /**
     * DASH-1 — reuses ReportService's own pinned Sales/Orders/Profit
     * definitions verbatim (ledger-sourced profit, paid_at-scoped,
     * is_test-excluded) rather than re-deriving them. "Profit Today" is
     * platform profit only — this MVP has one internal owner today: a
     * true affiliate-profit split is Phase 2, and affiliate_profit is
     * already broken out per-affiliate in Reports for whoever needs it.
     */
    public function summary(): array
    {
        [$todayFrom, $todayTo] = $this->dayRangeUtc(0);
        [$yesterdayFrom, $yesterdayTo] = $this->dayRangeUtc(1);

        $today = $this->reports->summary($todayFrom, $todayTo, null);
        $yesterday = $this->reports->summary($yesterdayFrom, $yesterdayTo, null);

        $vouchersToday = $this->vouchersIssued($todayFrom, $todayTo);
        $vouchersYesterday = $this->vouchersIssued($yesterdayFrom, $yesterdayTo);

        return [
            'sales_today' => [
                'value' => $today['total_sales'],
                'comparison' => $this->comparison($today['total_sales'], $yesterday['total_sales']),
                'definition' => 'SUM(final_amount), sen, where payment_status=Paid, paid_at = today (Asia/Kuala_Lumpur), excludes is_test orders. Same rule Reports (RPT-1) uses.',
            ],
            'orders_today' => [
                'value' => $today['orders_count'],
                'comparison' => $this->comparison($today['orders_count'], $yesterday['orders_count']),
                'definition' => 'COUNT(*) where payment_status=Paid, paid_at = today (Asia/Kuala_Lumpur), excludes is_test orders.',
            ],
            'profit_today' => [
                'value' => $today['platform_profit'],
                'comparison' => $this->comparison($today['platform_profit'], $yesterday['platform_profit']),
                'definition' => 'SUM(ledger_entries.amount), sen, type=order_profit, owner_type=platform, for orders paid today (Asia/Kuala_Lumpur) — never Order.platform_profit directly, since that column is stamped at checkout time before the delivery outcome is known (a paid-but-undelivered order correctly contributes RM0 here until it delivers).',
            ],
            'vouchers_issued_today' => [
                'value' => $vouchersToday['count'],
                'amount_sen' => $vouchersToday['amount'],
                'comparison' => $this->comparison($vouchersToday['count'], $vouchersYesterday['count']),
                'definition' => 'COUNT(*)/SUM(amount) of Vouchers with a non-null order_id (Path B — issued to compensate a failed order, ADR-004), created today (Asia/Kuala_Lumpur), excluding vouchers on is_test orders. Standalone admin-issued (Path A) vouchers are not counted — this tile tracks the order-failure compensation signal specifically.',
            ],
        ];
    }

    /**
     * DASH-2 — supplier status is derived from CircuitBreaker::state(),
     * never a live ping (would blow the §9 2-second budget and risks
     * tripping the very breaker being displayed). "Stuck orders" reuses
     * the existing delivery_reconciliation config thresholds
     * (ReconcilePendingDeliveriesCommand's own "stale" definition) so
     * this screen's notion of "stuck" can never silently drift from the
     * reconciliation job's. No caching — every call computes fresh.
     */
    public function health(): array
    {
        $breakerConfig = config('services.circuit_breaker');

        $suppliers = Supplier::query()->get()->map(function (Supplier $supplier) use ($breakerConfig) {
            $breaker = new CircuitBreaker(
                name: $supplier->slug,
                failureThreshold: $breakerConfig['failure_threshold'],
                cooldownSeconds: $breakerConfig['cooldown_seconds'],
            );

            // ADR-069 decision 13 — low_balance is true only when both a
            // balance and a threshold exist and the balance is under it.
            $threshold = $supplier->api_config['low_balance_threshold'] ?? null;
            $lowBalance = $supplier->balance !== null
                && $threshold !== null
                && is_numeric($threshold)
                && (float) $supplier->balance < (float) $threshold;

            return [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'slug' => $supplier->slug,
                'balance' => (float) $supplier->balance,
                'low_balance' => $lowBalance,
                'circuit_state' => $breaker->state()->value,
            ];
        })->values()->all();

        $staleAfterMinutes = (int) config('services.delivery_reconciliation.stale_after_minutes');
        $pendingStaleMinutes = (int) config('services.delivery_reconciliation.pending_stale_minutes');
        $nowUtc = CarbonImmutable::now('UTC');

        $needsReviewCount = Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::NeedsReview->value)
            ->count();

        $staleProcessingCount = Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::Processing->value)
            ->where('updated_at', '<=', $nowUtc->subMinutes($staleAfterMinutes))
            ->count();

        $stalePendingCount = Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->where('updated_at', '<=', $nowUtc->subMinutes($pendingStaleMinutes))
            ->count();

        $pendingPaymentsCount = Order::query()
            ->where('is_test', false)
            ->where('payment_status', PaymentStatus::Pending->value)
            ->count();

        $openWaSession = $this->openWaSessionStatus->current();

        return [
            'suppliers' => $suppliers,
            'suppliers_definition' => 'circuit_state read from CircuitBreaker::state() (cache-backed, per-supplier breaker keyed by Supplier.slug) — never a live ping to the supplier. balance mirrors Supplier.balance, the last value the supplier\'s own API reported (not ledger-governed, ADR-002 does not apply to it).',
            // PR-F build addendum decision 5 — an active health signal
            // for the Reseller Bot channel's OpenWA session, reversed
            // from this screen's usual "no live ping" posture only in
            // that it's push- not poll-driven: the last-known
            // session.status webhook event, cache-backed, never a live
            // call to OpenWA itself. Null = no event has ever arrived
            // (not yet provisioned/linked), distinct from a known
            // 'disconnected' state.
            'openwa_session' => $openWaSession,
            'openwa_session_definition' => 'Last-known session.status event OpenWaWebhookController received, cache-backed (no live ping). Null means no event has ever arrived, not confirmed healthy.',
            'stuck_orders' => [
                'value' => $needsReviewCount + $staleProcessingCount + $stalePendingCount,
                'definition' => "COUNT of orders where delivery_status=needs_review, PLUS delivery_status=processing older than {$staleAfterMinutes} minutes, PLUS delivery_status=pending older than {$pendingStaleMinutes} minutes (both thresholds from the same delivery_reconciliation config ReconcilePendingDeliveriesCommand itself uses — DELIVERY_RECONCILIATION_STALE_AFTER_MINUTES/PENDING_STALE_MINUTES). Excludes is_test orders.",
            ],
            'pending_payments' => [
                'value' => $pendingPaymentsCount,
                'definition' => 'COUNT(*) where payment_status=pending, excludes is_test orders — the same population app:reconcile-pending-payments (PAY-3) targets.',
            ],
            'queue' => [
                'pending' => (int) DB::table('jobs')->where('queue', self::MONITORED_QUEUE)->count(),
                'failed' => (int) DB::table('failed_jobs')->where('queue', self::MONITORED_QUEUE)->count(),
                'definition' => "COUNT(*) from the jobs/failed_jobs tables, queue='".self::MONITORED_QUEUE."' only — the delivery-critical queue (FulfillOrderJob/ResendOrderDeliveryJob/CheckSupplierDeliveryJob). The default/price-sync/backups queues aren't part of the money-critical customer-facing path this screen monitors.",
            ],
        ];
    }

    /**
     * DASH-3 — a real cohort funnel: orders CREATED in the last 7 days
     * are the fixed cohort, and Paid/Delivered are measured as that
     * same cohort's own current state, not three independently-windowed
     * counts. Right-censoring (an order created on day 7 hasn't had
     * time to convert yet) is an accepted, documented limitation
     * (ADR-045 decision 13), not engineered around.
     */
    public function funnel(): array
    {
        [$from, $toExclusive] = $this->rollingWindowUtc(7);

        $cohort = Order::query()
            ->where('is_test', false)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->get(['payment_status', 'delivery_status']);

        $created = $cohort->count();
        $paid = $cohort->where('payment_status', PaymentStatus::Paid)->count();
        $delivered = $cohort->where('delivery_status', DeliveryStatus::Delivered)->count();

        return [
            'window_days' => 7,
            'created' => [
                'value' => $created,
                'definition' => 'COUNT(*) of orders where created_at falls in the last 7 days (Asia/Kuala_Lumpur), excludes is_test orders. This is the funnel\'s fixed cohort — every other step below measures this same set of orders\' current state.',
            ],
            'payment_confirmed' => [
                'value' => $paid,
                'definition' => 'Of the Created cohort above, COUNT where payment_status=Paid now — not re-windowed by paid_at, since this asks "did this order (from this creation cohort) ever get paid," not "was it paid within the 7 days."',
            ],
            'delivered' => [
                'value' => $delivered,
                'definition' => 'Of the Created cohort above, COUNT where delivery_status=delivered right now (a live snapshot, not a point-in-time-of-delivery check) — orders created near the end of the 7-day window haven\'t had time to fully convert yet (right-censoring), so a low figure here isn\'t necessarily a quality problem.',
            ],
        ];
    }

    /**
     * DASH-4 — thin wrapper over ReportService::gameBreakdown(), same
     * rolling-7-day window as DASH-3 so "weekly" means one thing across
     * this whole screen. "% change" compares against the prior 7-day
     * period (day 8-14 ago), same window shape.
     */
    public function topGames(int $limit = 5): array
    {
        [$from, $toExclusive] = $this->rollingWindowUtc(7);
        [$prevFrom, $prevToExclusive] = $this->rollingWindowUtc(7, 7);

        $current = $this->reports->gameBreakdown($from, $toExclusive, null, $limit);
        $previous = collect($this->reports->gameBreakdown($prevFrom, $prevToExclusive, null, null))
            ->keyBy('game_id');

        $games = array_map(function (array $row) use ($previous) {
            $prevSales = $previous->get($row['game_id'])['sales'] ?? 0;
            $row['comparison'] = $this->comparison($row['sales'], $prevSales);

            return $row;
        }, $current);

        return [
            'window_days' => 7,
            'games' => $games,
            'definition' => 'ReportService::gameBreakdown() unchanged — Paid orders, paid_at-scoped, is_test-excluded, ranked by sales — for a rolling trailing 7-day window. comparison.pct is this window\'s sales vs. the prior 7-day period (day 8-14 ago) for the same game.',
        ];
    }

    /**
     * DASH-5 — orders per hour for one selected day (not an aggregate
     * across the week — the day selector picks exactly one date).
     * Counts by created_at (raw activity/attempt volume), matching
     * DASH-3's Created leg definition, not paid_at.
     */
    public function hourlyActivity(string $date): array
    {
        $dayStartKl = CarbonImmutable::createFromFormat('Y-m-d', $date, self::TIMEZONE)->startOfDay();
        $dayEndKl = $dayStartKl->addDay();

        $orders = Order::query()
            ->where('is_test', false)
            ->where('created_at', '>=', $dayStartKl->setTimezone('UTC'))
            ->where('created_at', '<', $dayEndKl->setTimezone('UTC'))
            ->get(['created_at']);

        $countByHour = array_fill(0, 24, 0);

        foreach ($orders as $order) {
            $hour = (int) $order->created_at->setTimezone(self::TIMEZONE)->format('G');
            $countByHour[$hour]++;
        }

        $hours = [];
        foreach ($countByHour as $hour => $count) {
            $hours[] = ['hour' => $hour, 'count' => $count];
        }

        return [
            'date' => $date,
            'hours' => $hours,
            'definition' => 'COUNT(*) of orders by created_at, grouped by hour-of-day (00-23, Asia/Kuala_Lumpur) for the selected date — excludes is_test orders. Counts every order attempt regardless of outcome, not just Paid ones.',
        ];
    }

    /**
     * @return array{count: int, amount: int}
     */
    private function vouchersIssued(CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $vouchers = Voucher::query()
            ->whereNotNull('order_id')
            ->whereHas('sourceOrder', fn ($query) => $query->where('is_test', false))
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->get(['amount']);

        return [
            'count' => $vouchers->count(),
            'amount' => (int) $vouchers->sum('amount'),
        ];
    }

    /**
     * @return array{pct: float|null, direction: 'up'|'down'|'flat'|'new'}
     */
    private function comparison(int|float $current, int|float $previous): array
    {
        if ($previous == 0) {
            return ['pct' => null, 'direction' => $current == 0 ? 'flat' : 'new'];
        }

        $pct = round((($current - $previous) / $previous) * 100, 2);

        return [
            'pct' => abs($pct),
            'direction' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [from, toExclusive) in UTC
     */
    private function dayRangeUtc(int $daysAgo): array
    {
        $startKl = CarbonImmutable::now(self::TIMEZONE)->startOfDay()->subDays($daysAgo);

        return [$startKl->setTimezone('UTC'), $startKl->addDay()->setTimezone('UTC')];
    }

    /**
     * A rolling `$days`-day window ending `$offsetDays` ago (default:
     * ending today) — e.g. rollingWindowUtc(7, 7) is the 7-day period
     * immediately before rollingWindowUtc(7)'s own window, for a
     * week-over-week comparison.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [from, toExclusive) in UTC
     */
    private function rollingWindowUtc(int $days, int $offsetDays = 0): array
    {
        $toExclusiveKl = CarbonImmutable::now(self::TIMEZONE)->startOfDay()->addDay()->subDays($offsetDays);
        $fromKl = $toExclusiveKl->subDays($days);

        return [$fromKl->setTimezone('UTC'), $toExclusiveKl->setTimezone('UTC')];
    }
}
