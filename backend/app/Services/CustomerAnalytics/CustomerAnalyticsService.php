<?php

namespace App\Services\CustomerAnalytics;

use App\Models\Affiliate;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer Analytics, ANL-1..5 (docs/prd.md §6.12, ADR-049, ADR-050).
 *
 * - "Customer" is a grouping of Order rows by `customer_email`, never a
 *   stored entity (ADR-049 decision 1; ADR-011 untouched). Letter case is
 *   left to the database collation (2026-10-15 addendum decision 12).
 * - Money is ReportService's rule: paid, non-test orders by `paid_at`,
 *   net of wallet refunds through `Order::netSalesSql()` (decision 2).
 * - Every list, stat and detail figure comes from one SQL aggregate,
 *   `segmentedCustomers()` (2026-10-15 addendum, item 103): one
 *   `GROUP BY customer_email` over every paid order, with conditional sums
 *   for the filtered figures. Segment, repeat rate and Source come from all
 *   of a customer's orders whatever the filters; the date, Affiliate and
 *   Reseller filters narrow only orders_count/total_spent/last_order_at.
 * - The segment is a SQL CASE, the only copy of the rule. Its cut-offs are
 *   bound from PHP so sqlite and MySQL run the same SQL.
 *
 * ponytail: every request scans all paid orders once in MySQL, as Reports
 * does. Add a summary table when Pulse shows these endpoints over 500 ms.
 */
final class CustomerAnalyticsService
{
    public const SORTS = ['total_spent', 'orders_count', 'last_order_at'];

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public const HISTORY_PER_PAGE = 25;

    private const FREQUENT_ORDERS_THRESHOLD = 20;

    private const DORMANT_AFTER_DAYS = 60;

    private const NEW_WITHIN_DAYS = 30;

    private const EXPORT_BATCH = 500;

    /**
     * ANL-1 — period-scoped like ReportService::summary(). repeat_rate
     * counts a customer as repeat when they have more than one paid order
     * anywhere, ever (addendum decision 3). Ignores segment and search.
     */
    public function stats(CustomerFilters $filters): array
    {
        $customers = $this->segmentedCustomers($filters->withoutListFilters());

        $totals = DB::query()->fromSub($customers, 's')
            ->selectRaw('COUNT(*) as total_customers, COALESCE(SUM(total_spent), 0) as total_spent, COALESCE(SUM(orders_count), 0) as total_orders, COALESCE(SUM(CASE WHEN lifetime_orders > 1 THEN 1 ELSE 0 END), 0) as repeat_customers')
            ->first();

        $top = DB::query()->fromSub($customers, 's')
            ->where('total_spent', '>', 0)
            ->orderByDesc('total_spent')
            ->orderBy('customer_email')
            ->first();

        $totalCustomers = (int) $totals->total_customers;
        $totalOrders = (int) $totals->total_orders;

        return [
            'total_customers' => $totalCustomers,
            'avg_order_value' => $totalOrders > 0 ? (int) round((int) $totals->total_spent / $totalOrders) : 0,
            'repeat_rate_pct' => $totalCustomers > 0 ? round((int) $totals->repeat_customers / $totalCustomers * 100, 2) : 0.0,
            'top_spender' => $top !== null ? [
                'customer_email' => $top->customer_email,
                'customer_name' => $this->latestValues('customer_name', [$top->customer_email])[Str::lower($top->customer_email)] ?? null,
                'total_spent' => (int) $top->total_spent,
            ] : null,
        ];
    }

    /** ANL-3/4 — one page of customers, sorted on the server (addendum decision 4). */
    public function customers(CustomerFilters $filters, string $sort = 'total_spent', int $page = 1, int $perPage = 25): LengthAwarePaginator
    {
        $paginator = $this->listQuery($filters, $sort)->paginate($perPage, ['*'], 'page', $page);

        return $paginator->setCollection(collect($this->present(collect($paginator->items()))));
    }

    /**
     * Every matching customer for the CSV export, streamed (addendum
     * decision 9): one cursor query, presented in batches so the name and
     * Source lookups stay one query per batch.
     *
     * @return iterable<array<string, mixed>>
     */
    public function exportRows(CustomerFilters $filters, string $sort = 'total_spent'): iterable
    {
        $batch = [];

        foreach ($this->listQuery($filters, $sort)->cursor() as $row) {
            $batch[] = $row;

            if (count($batch) === self::EXPORT_BATCH) {
                yield from $this->present(collect($batch));
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield from $this->present(collect($batch));
        }
    }

    /**
     * ANL-5 (ADR-050) — one customer, always their full lifetime across
     * every Affiliate and Reseller (ADR-050 decision 6). Null when the email
     * has no paid, non-test order.
     *
     * Profit Analysis covers realised orders only (ADR-050 decision 2) and
     * keeps the per-order `Order` methods, the only copy of the cost and
     * compensation rules (addendum decision 11).
     *
     * @return array<string, mixed>|null
     */
    public function customerDetail(string $customerEmail, int $historyPage = 1): ?array
    {
        $row = $this->segmentedCustomers(new CustomerFilters, $customerEmail)->first();

        if ($row === null) {
            return null;
        }

        $totalOrders = (int) $row->lifetime_orders;
        $totalSpent = (int) $row->lifetime_spent;
        $key = Str::lower($customerEmail);

        return [
            'customer_email' => $customerEmail,
            'customer_name' => $this->latestValues('customer_name', [$customerEmail])[$key] ?? null,
            'customer_phone' => $this->latestValues('customer_phone', [$customerEmail])[$key] ?? null,
            'segment' => $row->segment,
            'segment_label' => $this->segmentLabel($row->segment),
            'stats' => [
                'total_orders' => $totalOrders,
                'total_spent' => $totalSpent,
                'avg_order_value' => (int) round($totalSpent / $totalOrders),
                'customer_since' => CarbonImmutable::parse($row->first_order_at)->setTimezone(MalaysiaTime::TIMEZONE)->toDateString(),
            ],
            'profit_analysis' => $this->profitAnalysis($customerEmail),
            'monthly_trend' => $this->monthlyTrend($customerEmail),
            'top_packages' => $this->topPackages($customerEmail, $totalSpent),
            'top_sources' => $this->topSources($customerEmail, $totalSpent),
            'order_history' => $this->orderHistory($customerEmail, $historyPage),
        ];
    }

    /**
     * One row per customer: lifetime figures (segment, repeat, Source) and
     * the filtered figures side by side, in one scan. A customer with no
     * order inside the filters is dropped.
     */
    private function segmentedCustomers(CustomerFilters $filters, ?string $email = null): QueryBuilder
    {
        [$inScope, $scopeBindings] = $this->filterCondition($filters);
        $net = Order::netSalesSql();

        $aggregates = $this->paidOrders()->toBase()
            ->selectRaw(
                'customer_email, COUNT(*) as lifetime_orders, SUM('.$net.') as lifetime_spent,'
                .' MIN(paid_at) as first_order_at, MAX(paid_at) as lifetime_last_order_at,'
                .' SUM(CASE WHEN wallet_reseller_id IS NULL THEN 1 ELSE 0 END) as retail_orders,'
                ." SUM(CASE WHEN {$inScope} THEN 1 ELSE 0 END) as orders_count,"
                ." SUM(CASE WHEN {$inScope} THEN {$net} ELSE 0 END) as total_spent,"
                ." MAX(CASE WHEN {$inScope} THEN paid_at END) as last_order_at",
                [...$scopeBindings, ...$scopeBindings, ...$scopeBindings],
            )
            ->when($email !== null, fn (QueryBuilder $q) => $q->where('customer_email', $email))
            // "!" escapes LIKE wildcards: sqlite has no default escape and MySQL's backslash needs different quoting.
            ->when($filters->search !== null, fn (QueryBuilder $q) => $q->whereRaw("customer_email LIKE ? ESCAPE '!'", ['%'.strtr($filters->search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%']))
            ->groupBy('customer_email');

        $now = CarbonImmutable::now();

        $segmented = DB::query()->fromSub($aggregates, 'c')
            ->select('c.*')
            ->selectRaw(
                'CASE WHEN c.lifetime_spent >= ? THEN ? WHEN c.lifetime_orders >= ? THEN ? WHEN c.lifetime_last_order_at < ? THEN ? WHEN c.first_order_at > ? THEN ? WHEN c.lifetime_orders = 1 THEN ? END as segment',
                [
                    PlatformSettings::current()->vip_spend_threshold_sen, CustomerSegment::Vip->value,
                    self::FREQUENT_ORDERS_THRESHOLD, CustomerSegment::Frequent->value,
                    $this->sqlTime($now->subDays(self::DORMANT_AFTER_DAYS)), CustomerSegment::Dormant->value,
                    $this->sqlTime($now->subDays(self::NEW_WITHIN_DAYS)), CustomerSegment::NewCustomer->value,
                    CustomerSegment::OneTime->value,
                ],
            )
            ->where('c.orders_count', '>', 0);

        return DB::query()->fromSub($segmented, 's')
            ->when($filters->segment !== null, fn (QueryBuilder $q) => $q->where('s.segment', $filters->segment->value));
    }

    private function listQuery(CustomerFilters $filters, string $sort): QueryBuilder
    {
        return $this->segmentedCustomers($filters)
            ->orderByDesc('s.'.(in_array($sort, self::SORTS, true) ? $sort : 'total_spent'))
            ->orderBy('s.customer_email');
    }

    /**
     * The date, Affiliate and Reseller filters as one SQL condition, so the
     * aggregate can count lifetime and filtered figures in the same scan.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function filterCondition(CustomerFilters $filters): array
    {
        $parts = [];
        $bindings = [];

        foreach ([
            ['paid_at >= ?', $filters->from !== null ? $this->sqlTime($filters->from) : null],
            ['paid_at < ?', $filters->toExclusive !== null ? $this->sqlTime($filters->toExclusive) : null],
            ['affiliate_id = ?', $filters->affiliateId],
            ['wallet_reseller_id = ?', $filters->resellerId],
        ] as [$sql, $value]) {
            if ($value !== null) {
                $parts[] = $sql;
                $bindings[] = $value;
            }
        }

        return [$parts === [] ? '1 = 1' : implode(' AND ', $parts), $bindings];
    }

    /**
     * Turns aggregate rows into the list/export shape: latest name and the
     * Source label (addendum decisions 6 and 7), one query each per batch.
     *
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $emails = $rows->pluck('customer_email')->all();
        $names = $this->latestValues('customer_name', $emails);

        $walletPairs = $this->paidOrders()->toBase()
            ->whereIn('customer_email', $emails)
            ->whereNotNull('wallet_reseller_id')
            ->distinct()
            ->get(['customer_email', 'wallet_reseller_id']);
        $resellerNames = $walletPairs->isEmpty() ? collect() : Reseller::query()->withTrashed()
            ->whereIn('id', $walletPairs->pluck('wallet_reseller_id')->unique())
            ->pluck('business_name', 'id');
        $resellersByEmail = $walletPairs->groupBy(fn (object $pair) => Str::lower($pair->customer_email));

        return $rows->map(function (object $row) use ($names, $resellersByEmail, $resellerNames) {
            $key = Str::lower($row->customer_email);
            $resellers = ($resellersByEmail[$key] ?? collect())
                ->map(fn (object $pair) => $resellerNames[$pair->wallet_reseller_id] ?? 'Unknown Reseller')
                ->sort()
                ->values();

            return [
                'customer_email' => $row->customer_email,
                'customer_name' => $names[$key] ?? null,
                'segment' => $row->segment,
                'segment_label' => $this->segmentLabel($row->segment),
                'orders_count' => (int) $row->orders_count,
                'total_spent' => (int) $row->total_spent,
                'last_order_at' => CarbonImmutable::parse($row->last_order_at)->setTimezone(MalaysiaTime::TIMEZONE)->toIso8601String(),
                'has_reseller_orders' => $resellers->isNotEmpty(),
                'source_label' => $this->sourceLabel($resellers->all(), (int) $row->retail_orders > 0),
            ];
        })->values()->all();
    }

    /** @param  list<string>  $resellerNames */
    private function sourceLabel(array $resellerNames, bool $hasRetail): string
    {
        if ($resellerNames === []) {
            return 'Retail';
        }

        return 'Reseller: '.implode(', ', $resellerNames).($hasRetail ? ' + Retail' : '');
    }

    /**
     * Latest non-empty value of a contact column on a paid order, per
     * email, keyed by lower-cased email (the collation's notion of "same").
     *
     * @param  list<string>  $emails
     * @return Collection<string, string>
     */
    private function latestValues(string $column, array $emails): Collection
    {
        $ranked = $this->paidOrders()->toBase()
            ->whereIn('customer_email', $emails)
            ->whereNotNull($column)
            ->selectRaw("customer_email, {$column} as value, ROW_NUMBER() OVER (PARTITION BY customer_email ORDER BY paid_at DESC, id DESC) as rn");

        return DB::query()->fromSub($ranked, 'r')
            ->where('rn', 1)
            ->get(['customer_email', 'value'])
            ->mapWithKeys(fn (object $r) => [Str::lower($r->customer_email) => $r->value]);
    }

    /** @return array{total_revenue: int, supplier_cost: int, affiliate_commission: int, transaction_fees: int, system_profit: int} */
    private function profitAnalysis(string $customerEmail): array
    {
        $totals = ['total_revenue' => 0, 'supplier_cost' => 0, 'affiliate_commission' => 0, 'transaction_fees' => 0, 'system_profit' => 0];

        // ponytail: cashCompensationSen() runs one wallet-refund query per
        // order, so this panel costs one query per realised order. Batch it
        // in the Order model (shared with OrderController and Accounting)
        // if a big reseller's page gets slow.

        $this->paidOrders()
            ->where('customer_email', $customerEmail)
            ->whereIn('delivery_status', [DeliveryStatus::Delivered->value, DeliveryStatus::PartiallyDelivered->value])
            ->with(['deliveryLegs.componentPackage', 'voucher', 'voucherRedemption'])
            ->chunkById(self::EXPORT_BATCH, function (Collection $orders) use (&$totals) {
                // ADR-094 decision 41: a settled partial delivery is realised too.
                $realised = $orders->filter(fn (Order $o) => $o->isRealised());

                foreach ($realised as $order) {
                    $totals['total_revenue'] += $order->final_amount - $order->cashCompensationSen();
                    $totals['supplier_cost'] += $order->delivery_status === DeliveryStatus::Delivered ? $order->cost_price : $order->effectiveCostPriceSen();
                    $totals['transaction_fees'] += $order->transaction_fee;
                }

                $profit = $this->profitByOwner($realised->pluck('id')->all());
                $totals['affiliate_commission'] += (int) $profit->where('owner_type', LedgerOwnerType::Affiliate->value)->sum('amount');
                $totals['system_profit'] += (int) $profit->where('owner_type', LedgerOwnerType::Platform->value)->sum('amount');
            });

        return $totals;
    }

    /**
     * Monthly spend, last 12 Malaysia months, oldest first, only months
     * with a paid order. Summed per Malaysia day in SQL (at most ~366
     * rows), folded into months here.
     *
     * @return list<array{month: string, label: string, total_spent: int}>
     */
    private function monthlyTrend(string $customerEmail): array
    {
        $windowStart = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->subMonths(11)->startOfMonth();
        $day = MalaysiaTime::dateSql('paid_at');

        $byMonth = $this->paidOrders()->toBase()
            ->where('customer_email', $customerEmail)
            ->where('paid_at', '>=', $this->sqlTime($windowStart))
            ->selectRaw("{$day} as day, SUM(".Order::netSalesSql().') as total')
            ->groupByRaw($day)
            ->get()
            ->groupBy(fn (object $r) => substr((string) $r->day, 0, 7))
            ->map(fn (Collection $days) => (int) $days->sum('total'))
            ->sortKeys();

        return $byMonth->map(fn (int $total, string $month) => [
            'month' => $month,
            'label' => CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', MalaysiaTime::TIMEZONE)->format('M'),
            'total_spent' => $total,
        ])->values()->all();
    }

    /** ADR-050 decision 4 — top 5 packages by spend. */
    private function topPackages(string $customerEmail, int $totalSpent): array
    {
        $rows = $this->paidOrders()->toBase()
            ->where('customer_email', $customerEmail)
            ->selectRaw('package_id, COUNT(*) as orders_count, SUM('.Order::netSalesSql().') as total_spent')
            ->groupBy('package_id')
            ->get();

        $names = Package::query()->whereIn('id', $rows->pluck('package_id')->filter())->pluck('name', 'id');

        return $this->topFive($rows->map(fn (object $r) => [
            'id' => $r->package_id !== null ? (int) $r->package_id : null,
            'name' => $names[$r->package_id] ?? 'Unknown Package',
            'orders_count' => (int) $r->orders_count,
            'total_spent' => (int) $r->total_spent,
        ]), $totalSpent);
    }

    /**
     * ADR-050 addendum — "Source": a wallet Reseller order's affiliate_id
     * is always the primary brand (ADR-073 decision 5), so a reseller's
     * orders group by reseller, everything else by Affiliate.
     */
    private function topSources(string $customerEmail, int $totalSpent): array
    {
        $affiliateKey = 'CASE WHEN wallet_reseller_id IS NULL THEN affiliate_id END';

        $rows = $this->paidOrders()->toBase()
            ->where('customer_email', $customerEmail)
            ->selectRaw("wallet_reseller_id, {$affiliateKey} as source_affiliate_id, COUNT(*) as orders_count, SUM(".Order::netSalesSql().') as total_spent')
            ->groupBy('wallet_reseller_id')
            ->groupByRaw($affiliateKey)
            ->get();

        $resellers = Reseller::query()->withTrashed()->whereIn('id', $rows->pluck('wallet_reseller_id')->filter())->pluck('business_name', 'id');
        $affiliates = Affiliate::query()->whereIn('id', $rows->pluck('source_affiliate_id')->filter())->pluck('business_name', 'id');

        return $this->topFive($rows->map(fn (object $r) => [
            'id' => (int) ($r->wallet_reseller_id ?? $r->source_affiliate_id),
            'name' => $r->wallet_reseller_id !== null
                ? 'Reseller: '.($resellers[$r->wallet_reseller_id] ?? 'Unknown Reseller')
                : ($affiliates[$r->source_affiliate_id] ?? 'Unknown Affiliate'),
            'orders_count' => (int) $r->orders_count,
            'total_spent' => (int) $r->total_spent,
        ]), $totalSpent);
    }

    /**
     * @param  Collection<int, array{id: int|null, name: string, orders_count: int, total_spent: int}>  $rows
     * @return list<array{id: int|null, name: string, orders_count: int, total_spent: int, pct_of_spend: float}>
     */
    private function topFive(Collection $rows, int $totalSpent): array
    {
        return $rows
            ->sortBy([['total_spent', 'desc'], ['orders_count', 'desc'], ['name', 'asc']])
            ->take(5)
            ->map(fn (array $r) => $r + ['pct_of_spend' => $totalSpent > 0 ? round($r['total_spent'] / $totalSpent * 100, 2) : 0.0])
            ->values()
            ->all();
    }

    /**
     * ADR-050 decision 5 — paid order history, newest first, paged
     * (addendum decision 11). Profit columns are null outside the realised,
     * ledger-confirmed set.
     */
    private function orderHistory(string $customerEmail, int $page): array
    {
        $paginator = $this->paidOrders()
            ->where('customer_email', $customerEmail)
            ->with(['package:id,name', 'affiliate:id,business_name', 'walletReseller:id,business_name'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(self::HISTORY_PER_PAGE, ['*'], 'history_page', $page);

        $orders = collect($paginator->items());
        $profitByOrder = $this->profitByOwner($orders->filter(fn (Order $o) => $o->isRealised())->pluck('id')->all())->groupBy('reference_id');

        $paginator->setCollection($orders->map(function (Order $order) use ($profitByOrder) {
            $entries = $order->isRealised() ? $profitByOrder->get($order->id, collect()) : null;

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'paid_at' => $order->paid_at->setTimezone(MalaysiaTime::TIMEZONE)->toIso8601String(),
                'package_name' => $order->package?->name ?? 'Unknown Package',
                // Same wording as Admin\OrderController's Source column.
                'source_name' => $order->wallet_reseller_id !== null
                    ? 'Reseller: '.($order->walletReseller?->business_name ?? 'Unknown Reseller')
                    : ($order->affiliate?->business_name ?? 'Unknown Affiliate'),
                'final_amount' => $order->final_amount,
                'affiliate_profit' => $entries !== null ? (int) $entries->where('owner_type', LedgerOwnerType::Affiliate->value)->sum('amount') : null,
                'system_profit' => $entries !== null ? (int) $entries->where('owner_type', LedgerOwnerType::Platform->value)->sum('amount') : null,
                'delivery_status' => $order->delivery_status,
            ];
        }));

        return $paginator->toArray();
    }

    /**
     * `order_profit` ledger rows for the given orders — profit is always
     * ledger-sourced, never the order's own snapshot (ADR-050 decision 2).
     *
     * @param  list<int>  $orderIds
     */
    private function profitByOwner(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return LedgerEntry::query()
            ->where('type', 'order_profit')
            ->where('reference_type', 'order')
            ->whereIn('reference_id', $orderIds)
            ->get(['reference_id', 'owner_type', 'amount']);
    }

    /** Same scope ReportService::scopedOrders() applies (ADR-049 decision 2). */
    private function paidOrders(): Builder
    {
        return Order::query()
            ->where('is_test', false)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereNotNull('paid_at');
    }

    private function segmentLabel(?string $segment): string
    {
        return $segment !== null ? CustomerSegment::from($segment)->label() : '—';
    }

    /** `paid_at` is stored in UTC as `Y-m-d H:i:s`; bind comparisons in the same form. */
    private function sqlTime(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d H:i:s');
    }
}
