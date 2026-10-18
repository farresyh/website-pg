<?php

namespace App\Services\Dashboard;

use App\Models\BackupRun;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PendingPriceChange;
use App\Models\Review;
use App\Models\Supplier;
use App\Models\WhatsAppPendingLink;
use App\Models\Withdrawal;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Currency\CurrencyRateService;
use App\Services\Currency\CurrencyRateUnavailableException;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\OpenWa\OpenWaSessionStatus;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Scheduling\SchedulerLiveness;
use App\Services\Withdrawal\WithdrawalStatus;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * DASH-2 system health (ADR-045) and the Dashboard status strip
 * (`attention`, ADR-104 2026-10-15). The Dashboard's business figures
 * (sales, profit, trend, channels, games, busy hours, highlights) come
 * from ReportService through /reports/*, so there is one definition.
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
        private readonly OpenWaSessionStatus $openWaSessionStatus,
        private readonly CurrencyRateService $currencyRates,
        private readonly SchedulerLiveness $schedulerLiveness,
    ) {}

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
            $balance = $supplier->displayBalance();
            $lowBalance = $balance !== null
                && $threshold !== null
                && is_numeric($threshold)
                && $balance < (float) $threshold;

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
                // ADR-125 decisions 18 and 21: the polled balance for an API
                // supplier, the funding ledger for a manual one (negative =
                // owed, for a pay-after supplier).
                'balance' => $balance,
                'is_api_backed' => $supplier->isApiBacked(),
                'is_pay_after' => $supplier->isPayAfter(),
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
        // ADR-125 decision 21: only API suppliers' stock backs a reseller
        // wallet (no reseller channel sells a manual supplier's games).
        $totalSupplierBalanceMyrEquivalent = collect($suppliers)->where('is_api_backed', true)->sum('balance_myr_equivalent');

        $staleAfterMinutes = (int) config('services.delivery_reconciliation.stale_after_minutes');
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

        // ADR-125 decision 3: each supplier's own stale window
        // (Supplier::pendingPolicy()), the same rule the reconciler uses.
        // Pending orders are few, so they are judged in PHP.
        $stalePendingCount = Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::Pending->value)
            ->with(['supplier', 'package.components.supplier'])
            ->get()
            ->filter(fn (Order $order) => $order->isStalePending())
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
                'definition' => "COUNT of orders where delivery_status=needs_review, PLUS delivery_status=processing older than {$staleAfterMinutes} minutes, PLUS delivery_status=pending past its supplier's stale window (Supplier::pendingPolicy(): an API supplier's pending_stale_minutes, else DELIVERY_RECONCILIATION_PENDING_STALE_MINUTES; a manual supplier's reply SLA) — the same rule ReconcilePendingDeliveriesCommand uses. Excludes is_test orders.",
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
            'whatsapp_links_pending' => WhatsAppPendingLink::query()->count(),
            'backup_failed' => (int) ($lastBackup === 'failed'),
            'customer_whatsapp_disconnected' => (int) ($openWaCsSession !== null && $openWaCsSession['status'] !== 'connected'),
            // ADR-125 decision 21: a manual supplier has no drift check, and
            // recordOrderDrawdown() runs after commit and swallows errors,
            // so a Delivered order with no drawdown is surfaced here.
            'manual_delivery_without_drawdown' => $this->manualDeliveriesWithoutDrawdown(),
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
     * 2026-09-15 addendum: a MYR-equivalent for `health()`'s per-
     * supplier balance card — lets the founder compare a native-MYR
     * supplier (Gamevion) against a foreign-currency one (Digiflazz,
     * IDR) at a glance, without either being treated as the real
     * figure (that's still `balance`/`currency`, unconverted). Never
     * throws: `health()` is polled every 60s and must stay up even if
     * the FX API is down and nothing was ever cached for this pair —
     * null just means "comparison unavailable right now", not an error.
     */
    private function manualDeliveriesWithoutDrawdown(): int
    {
        return Order::query()
            ->where('is_test', false)
            ->where('delivery_status', DeliveryStatus::Delivered->value)
            ->whereHas('supplier', fn ($q) => $q->where('driver', Supplier::DRIVER_MANUAL))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('supplier_ledger_entries')
                ->where('supplier_ledger_entries.type', SupplierLedgerEntryType::OrderDrawdown->value)
                ->where('supplier_ledger_entries.reference_type', 'order')
                ->whereColumn('supplier_ledger_entries.reference_id', 'orders.id'))
            ->count();
    }

    private function balanceInMyr(Supplier $supplier): ?float
    {
        $balance = $supplier->displayBalance();

        if ($balance === null) {
            return null;
        }

        if ($supplier->currency === 'MYR') {
            return $balance;
        }

        try {
            return round($balance * $this->currencyRates->rate($supplier->currency, 'MYR'), 2);
        } catch (CurrencyRateUnavailableException) {
            return null;
        }
    }
}
