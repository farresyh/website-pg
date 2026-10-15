<?php

namespace App\Services\Dashboard;

use App\Models\BackupRun;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PendingPriceChange;
use App\Models\ResellerWhatsAppPendingLink;
use App\Models\Review;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\Withdrawal;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Currency\CurrencyRateService;
use App\Services\Currency\CurrencyRateUnavailableException;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\OpenWa\OpenWaSessionStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Report\ReportService;
use App\Services\Scheduling\SchedulerLiveness;
use App\Services\Withdrawal\WithdrawalStatus;
use App\Support\MalaysiaTime;
use App\Support\PeriodComparison;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

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
    /**
     * The delivery-critical order lanes this screen monitors (ADR-045
     * decision 9; lanes per the ADR-048 2026-09-29 addendum) — see
     * DashboardService::health().
     */
    private const MONITORED_QUEUES = ['orders', 'orders-reseller', 'orders-combo'];

    public function __construct(
        private readonly ReportService $reports,
        private readonly OpenWaSessionStatus $openWaSessionStatus,
        private readonly CurrencyRateService $currencyRates,
        private readonly SchedulerLiveness $schedulerLiveness,
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
                'comparison' => PeriodComparison::change($today['total_sales'], $yesterday['total_sales']),
                'definition' => 'Paid sales: SUM(final_amount) minus wallet refunds (Order::netSalesSql), sen, where payment_status=Paid, paid_at = today (Asia/Kuala_Lumpur), excludes is_test orders. Same rule Reports (RPT-1) uses.',
            ],
            'orders_today' => [
                'value' => $today['orders_count'],
                'comparison' => PeriodComparison::change($today['orders_count'], $yesterday['orders_count']),
                'definition' => 'COUNT(*) where payment_status=Paid, paid_at = today (Asia/Kuala_Lumpur), excludes is_test orders.',
            ],
            'profit_today' => [
                'value' => $today['platform_profit'],
                'comparison' => PeriodComparison::change($today['platform_profit'], $yesterday['platform_profit']),
                'definition' => 'SUM(ledger_entries.amount), sen, type=order_profit, owner_type=platform, for orders paid today (Asia/Kuala_Lumpur) — never Order.platform_profit directly, since that column is stamped at checkout time before the delivery outcome is known (a paid-but-undelivered order correctly contributes RM0 here until it delivers).',
            ],
            'vouchers_issued_today' => [
                'value' => $vouchersToday['count'],
                'amount_sen' => $vouchersToday['amount'],
                'comparison' => PeriodComparison::change($vouchersToday['count'], $vouchersYesterday['count']),
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

            // ADR-083 decision 6 — same computation
            // app:refresh-supplier-balances logs from, exposed here for
            // the amber chip. Null (drift_threshold unconfigured) means
            // "not watched", not "not drifted" — the frontend must not
            // conflate the two.
            $drift = $supplier->fundingDrift();

            return [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'slug' => $supplier->slug,
                'balance' => (float) $supplier->balance,
                // Found live, 2026-09-15: this endpoint never carried
                // `currency` at all — both this screen and the
                // /middleware dashboard (same endpoint) either
                // hardcoded "RM" or showed a bare, unlabeled number
                // regardless of the real supplier currency (Digiflazz
                // is IDR, not MYR). `balance_myr_equivalent` is a
                // display-only convenience for comparing suppliers
                // at a glance — `balance`/`currency` stay the real,
                // receipt/dashboard-verifiable figures.
                'currency' => $supplier->currency,
                'balance_myr_equivalent' => $this->balanceInMyr($supplier),
                'low_balance' => $lowBalance,
                'drift' => $drift,
                'circuit_state' => $breaker->state()->value,
            ];
        })->values()->all();

        // 2026-09-28 addendum: a real treasury/liquidity risk the founder
        // flagged — a reseller's wallet top-up credits their spendable
        // balance immediately, but the CHIP cash backing it only settles
        // to the company bank T+1/T+2. Combined supplier balance
        // (`$suppliers` above) must stay ahead of the total outstanding
        // reseller wallet float, or a reseller spending against a
        // freshly-credited top-up can draw a supplier balance down before
        // the cash that's meant to replenish it has actually landed.
        // Deliberately just the two raw numbers side by side for now — no
        // threshold/alert yet (agreed: revisit once real reseller volume
        // gives a real buffer % to tune against, not a guessed one).
        $resellerWalletLiabilitySen = (int) LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->sum('amount');
        $totalSupplierBalanceMyrEquivalent = collect($suppliers)->sum('balance_myr_equivalent');

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
        $openWaCsSession = $this->openWaSessionStatus->current(OpenWaSessionStatus::CUSTOMER_SUPPORT);
        $scheduledTasks = $this->schedulerLiveness->tasks();
        $failedJobs = (int) DB::table('failed_jobs')->whereIn('queue', self::MONITORED_QUEUES)->count();

        return [
            'attention' => $this->attention($suppliers, $needsReviewCount, $staleProcessingCount + $stalePendingCount, $failedJobs, $scheduledTasks, $openWaSession, $openWaCsSession),
            'suppliers' => $suppliers,
            'suppliers_definition' => 'circuit_state read from CircuitBreaker::state() (cache-backed, per-supplier breaker keyed by Supplier.slug) — never a live ping to the supplier. balance mirrors Supplier.balance, the last value the supplier\'s own API reported (not ledger-governed, ADR-002 does not apply to it), in that supplier\'s own currency — never assume MYR. balance_myr_equivalent is a display-only conversion (CurrencyRateService, cached ~24h) for comparing suppliers at a glance; null means the conversion is unavailable right now, not that the balance is zero. drift (ADR-083 decision 6) compares that same balance against SUM(supplier_ledger_entries) — null when no drift_threshold is configured for this supplier, meaning "not watched", not "not drifted".',
            // 2026-09-28 addendum: real-time reseller-wallet-float vs
            // supplier-balance visibility (a genuine treasury risk, not
            // a bookkeeping concern — see the comment above where this is
            // computed). Side-by-side numbers only, no alert threshold
            // yet.
            'reseller_wallet_liability_sen' => $resellerWalletLiabilitySen,
            'total_supplier_balance_myr_equivalent' => $totalSupplierBalanceMyrEquivalent,
            'reseller_wallet_liability_definition' => 'reseller_wallet_liability_sen = SUM(ledger_entries.amount), sen, owner_type=reseller_wallet, across every reseller — the total outstanding spendable credit resellers are currently holding. total_supplier_balance_myr_equivalent sums every supplier\'s own balance_myr_equivalent above. This should stay comfortably above the wallet liability — a reseller can spend their wallet balance against supplier stock immediately, while the CHIP cash that funded that top-up only settles to the company bank T+1/T+2.',
            // PR-F build addendum decision 5 — an active health signal
            // for the Reseller Bot channel's OpenWA session, reversed
            // from this screen's usual "no live ping" posture only in
            // that it's push- not poll-driven: the last-known
            // session.status webhook event, cache-backed, never a live
            // call to OpenWA itself. Null = no event in the last hour
            // (not yet provisioned/linked, or nothing heard — the key
            // carries a 1h TTL since ADR-077), distinct from a known
            // 'disconnected' state.
            'openwa_session' => $openWaSession,
            // ADR-116 decision 11 — the customer-support session, same shape.
            'openwa_cs_session' => $openWaCsSession,
            'openwa_session_definition' => 'Last-known session.status event OpenWaWebhookController received, cache-backed (no live ping), 1h TTL. Null means no event in the last hour, not confirmed healthy.',
            'stuck_orders' => [
                'value' => $needsReviewCount + $staleProcessingCount + $stalePendingCount,
                'definition' => "COUNT of orders where delivery_status=needs_review, PLUS delivery_status=processing older than {$staleAfterMinutes} minutes, PLUS delivery_status=pending older than {$pendingStaleMinutes} minutes (both thresholds from the same delivery_reconciliation config ReconcilePendingDeliveriesCommand itself uses — DELIVERY_RECONCILIATION_STALE_AFTER_MINUTES/PENDING_STALE_MINUTES). Excludes is_test orders.",
            ],
            'pending_payments' => [
                'value' => $pendingPaymentsCount,
                'definition' => 'COUNT(*) where payment_status=pending, excludes is_test orders — the same population app:reconcile-pending-payments (PAY-3) targets.',
            ],
            'queue' => [
                // Live Redis queue size (waiting + delayed + reserved) — the
                // `jobs` table this used to read is unused since ADR-048.
                'pending' => (int) collect(self::MONITORED_QUEUES)->sum(fn (string $queue) => Queue::size($queue)),
                'failed' => $failedJobs,
                'definition' => 'Pending = live Redis queue size (waiting + delayed + in-flight); failed = COUNT(*) from failed_jobs. Order lanes only ('.implode(', ', self::MONITORED_QUEUES).') — the delivery-critical FulfillOrderJob/ResendOrderDeliveryJob/CheckSupplierDeliveryJob path. The default/price-sync/backups queues aren\'t part of the money-critical customer-facing path this screen monitors.',
            ],
            // ADR-122 — the detail behind /api/health's `scheduler` boolean.
            'scheduled_tasks' => [
                'tasks' => $scheduledTasks,
                'definition' => 'One row per scheduled task (scheduled_task_heartbeats). last_ok_at = the scheduler last ran it with exit code 0 (a run skipped by its overlap lock does not count). stale = it missed its due runs: a task that runs more than once a day may miss one, then 30 minutes grace; a daily task may miss none, 60 minutes grace. A stale critical task fails /api/health (UptimeRobot alerts); a stale routine task shows here only. This is whether the task ran, not whether a job it dispatched (backup, price sync) succeeded.',
            ],
        ];
    }

    /**
     * ADR-104 2026-10-15 (grill Q9, S4) — the Dashboard status strip, built
     * from the same figures as the health panel so the two can't disagree.
     * Red and amber items count toward "N need action"; neutral ones show
     * but don't count. Stuck orders here are only the stale processing /
     * pending ones: needs_review is its own item, never counted twice.
     * A null OpenWA session means no event in the last hour, not
     * disconnected, so only an explicit non-connected status is flagged.
     *
     * @return array{items: list<array{key: string, level: string, count: int, subject: string|null}>, needs_action_count: int, last_paid_at: string|null}
     */
    private function attention(array $suppliers, int $needsReview, int $staleOrders, int $failedJobs, array $scheduledTasks, ?array $openWaSession, ?array $openWaCsSession): array
    {
        $item = fn (string $key, string $level, int $count = 1, ?string $subject = null) => compact('key', 'level', 'count', 'subject');
        $items = [];

        foreach ($suppliers as $supplier) {
            if ($supplier['circuit_state'] === 'open') {
                $items[] = $item('supplier_circuit_open', 'red', 1, $supplier['name']);
            }
        }

        $criticalStale = collect($scheduledTasks)->where('tier', 'critical')->where('stale', true)->count();
        $needsActionOrders = Order::query()->where('is_test', false)->needsAction()->count();
        $red = [
            'scheduler_critical_stale' => $criticalStale,
            'queue_failed' => $failedJobs,
            'stuck_orders' => $staleOrders,
            'needs_action_orders' => $needsActionOrders,
            'needs_review' => $needsReview,
            'reseller_bot_disconnected' => (int) ($openWaSession !== null && $openWaSession['status'] !== 'connected'),
        ];

        foreach ($suppliers as $supplier) {
            if ($supplier['low_balance']) {
                $items[] = $item('supplier_low_balance', 'amber', 1, $supplier['name']);
            }
            if ($supplier['drift']['is_drifted'] ?? false) {
                $items[] = $item('supplier_drift', 'amber', 1, $supplier['name']);
            }
        }

        $lastBackup = BackupRun::query()->whereIn('status', ['success', 'failed'])->latest('id')->value('status');
        $amber = [
            'withdrawals_to_process' => Withdrawal::query()->whereIn('status', [WithdrawalStatus::Pending, WithdrawalStatus::Approved])->count(),
            'price_changes_pending' => PendingPriceChange::query()->where('status', 'pending')->count(),
            'whatsapp_links_pending' => ResellerWhatsAppPendingLink::query()->count(),
            'backup_failed' => (int) ($lastBackup === 'failed'),
            'customer_whatsapp_disconnected' => (int) ($openWaCsSession !== null && $openWaCsSession['status'] !== 'connected'),
        ];

        $methods = PaymentMethod::query()->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_active THEN 1 ELSE 0 END) as active')->first();
        $neutral = [
            'payments_off' => (int) ($methods->total > 0 && (int) $methods->active === 0),
            'reviews_pending' => Review::query()->where('status', 'pending')->count(),
        ];

        foreach (['red' => $red, 'amber' => $amber, 'neutral' => $neutral] as $level => $counts) {
            foreach ($counts as $key => $count) {
                if ($count > 0) {
                    $items[] = $item($key, $level, $count);
                }
            }
        }

        usort($items, fn (array $a, array $b) => array_search($a['level'], ['red', 'amber', 'neutral']) <=> array_search($b['level'], ['red', 'amber', 'neutral']));
        $lastPaidAt = Order::query()->where('is_test', false)->where('payment_status', PaymentStatus::Paid->value)->max('paid_at');

        return [
            'items' => $items,
            'needs_action_count' => collect($items)->where('level', '!=', 'neutral')->count(),
            'last_paid_at' => $lastPaidAt === null ? null : CarbonImmutable::parse($lastPaidAt, 'UTC')->setTimezone(MalaysiaTime::TIMEZONE)->toIso8601String(),
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
            $row['comparison'] = PeriodComparison::change($row['sales'], $prevSales);

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
        $dayStartKl = CarbonImmutable::createFromFormat('Y-m-d', $date, MalaysiaTime::TIMEZONE)->startOfDay();
        $dayEndKl = $dayStartKl->addDay();

        $orders = Order::query()
            ->where('is_test', false)
            ->where('created_at', '>=', $dayStartKl->setTimezone('UTC'))
            ->where('created_at', '<', $dayEndKl->setTimezone('UTC'))
            ->get(['created_at']);

        $countByHour = array_fill(0, 24, 0);

        foreach ($orders as $order) {
            $hour = (int) $order->created_at->setTimezone(MalaysiaTime::TIMEZONE)->format('G');
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
            ->compensation()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $toExclusive)
            ->get(['amount']);

        return [
            'count' => $vouchers->count(),
            'amount' => (int) $vouchers->sum('amount'),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [from, toExclusive) in UTC
     */
    private function dayRangeUtc(int $daysAgo): array
    {
        $startKl = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->startOfDay()->subDays($daysAgo);

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
        $toExclusiveKl = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->startOfDay()->addDay()->subDays($offsetDays);
        $fromKl = $toExclusiveKl->subDays($days);

        return [$fromKl->setTimezone('UTC'), $toExclusiveKl->setTimezone('UTC')];
    }

    /**
     * 2026-09-15 addendum: a MYR-equivalent for `health()`'s per-
     * supplier balance card — lets the founder compare a native-MYR
     * supplier (Gamevion) against a foreign-currency one (Digiflazz,
     * IDR) at a glance, without either being treated as the real
     * figure (that's still `balance`/`currency`, unconverted). Never
     * throws: `health()` is polled every 60s and must stay up even if
     * the FX API is down and nothing was ever cached for this pair —
     * null just means "comparison unavailable right now", not an error.
     */
    private function balanceInMyr(Supplier $supplier): ?float
    {
        if ($supplier->balance === null) {
            return null;
        }

        if ($supplier->currency === 'MYR') {
            return (float) $supplier->balance;
        }

        try {
            return round((float) $supplier->balance * $this->currencyRates->rate($supplier->currency, 'MYR'), 2);
        } catch (CurrencyRateUnavailableException) {
            return null;
        }
    }
}
