<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * ADR-059 decision 5 + ADR-057's consequence note: the ONE seam for
 * every affiliate-portal money read. `ledger_entries` has no `affiliate_id`
 * column — it keys tenant off polymorphic `owner_type` / `owner_id`
 * ('affiliate', <affiliates.id>), so `BelongsToAffiliate` / `AffiliateScope`
 * cannot protect it. Every read here filters those two columns
 * explicitly, against the `Affiliate` passed in — never raw Eloquent on
 * `LedgerEntry` elsewhere, never `DB::table('ledger_entries')` in a
 * affiliate-guard path.
 *
 * The affiliate runs a SINGLE earnings account (ADR-056 decision 7 — no
 * prepaid deposit wallet this phase), so the withdrawable balance IS the
 * current ledger balance: `order_profit` credits, minus `withdrawal` and
 * `affiliate_tier_fee` debits.
 */
final class AffiliateEarningsService
{
    private const TIMEZONE = 'Asia/Kuala_Lumpur';

    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * Current earnings balance in sen. Also the withdrawable amount —
     * there is only one account.
     */
    public function balance(Affiliate $affiliate): int
    {
        return $this->ledger->balance(LedgerOwnerType::Affiliate, $affiliate->id);
    }

    /**
     * The affiliate's own ledger history, newest first, shaped for the
     * portal (no `created_by` admin id, no other tenant's rows).
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function ledgerEntries(Affiliate $affiliate, int $perPage = 20): LengthAwarePaginator
    {
        return LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::Affiliate->value)
            ->where('owner_id', $affiliate->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (LedgerEntry $entry): array => [
                'id' => $entry->id,
                'type' => $entry->type,
                'amount' => $entry->amount,
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                'reason' => $entry->reason,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]);
    }

    /**
     * The `affiliate_tier_fee` debits for this affiliate, newest first —
     * the Subscription screen's charge history (ADR-059 decision 2).
     * Same explicit `owner_type` / `owner_id` filter as every other read
     * here.
     *
     * @return list<array<string, mixed>>
     */
    public function tierFeeHistory(Affiliate $affiliate, int $limit = 24): array
    {
        return LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::Affiliate->value)
            ->where('owner_id', $affiliate->id)
            ->where('type', 'affiliate_tier_fee')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (LedgerEntry $entry): array => [
                'id' => $entry->id,
                'amount' => $entry->amount,
                'charged_at' => $entry->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Dashboard tiles: earnings balance, today's and this-month's paid
     * sales (count + gross value, KL day boundaries, same `paid_at` /
     * `payment_status=Paid` / `is_test=false` rules as ReportService),
     * and the current wholesale-tier subscription snapshot.
     *
     * @return array<string, mixed>
     */
    public function dashboardStats(Affiliate $affiliate): array
    {
        $nowKl = CarbonImmutable::now(self::TIMEZONE);
        $todayStartUtc = $nowKl->startOfDay()->setTimezone('UTC');
        $monthStartUtc = $nowKl->startOfMonth()->setTimezone('UTC');

        $subscription = $affiliate->subscription()->with('tier')->first();

        return [
            'earnings_balance' => $this->balance($affiliate),
            'today' => $this->salesAggregate($affiliate, $todayStartUtc),
            'this_month' => $this->salesAggregate($affiliate, $monthStartUtc),
            'subscription' => $subscription === null ? null : [
                'tier_name' => $subscription->tier->name,
                'status' => $subscription->status->value,
                'monthly_fee_sen' => (int) $subscription->tier->monthly_fee_sen,
                'next_charge_at' => $subscription->next_charge_at?->toIso8601String(),
                'grace_until' => $subscription->grace_until?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array{orders: int, sales: int}
     */
    private function salesAggregate(Affiliate $affiliate, CarbonImmutable $fromUtc): array
    {
        $row = Order::query()
            ->where('affiliate_id', $affiliate->id)
            ->where('is_test', false)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $fromUtc)
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(final_amount), 0) as sales_total')
            ->first();

        return [
            'orders' => (int) ($row->order_count ?? 0),
            'sales' => (int) ($row->sales_total ?? 0),
        ];
    }
}
