<?php

namespace App\Services\Rebate;

use App\Models\LedgerEntry;
use App\Models\Reseller;
use App\Models\ResellerRebateMilestone;
use App\Models\ResellerRebateMonth;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Reseller\WalletEntryType;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;

/**
 * ADR-139 decision 18 and the accountant addendum, item 6: the admin's view
 * of one rebate month and its CSV for income-tax records. Read only; every
 * amount comes from RebateEntitlement or the stored rows.
 */
final class RebateMonthReport
{
    public function __construct(
        private readonly RebateEntitlement $entitlement,
        private readonly RebateMonthService $months,
    ) {}

    /** @return array<string, mixed> */
    public function forMonth(int $year, int $month): array
    {
        $period = CarbonImmutable::create($year, $month, 1, 0, 0, 0, MalaysiaTime::TIMEZONE)->toDateString();
        $rows = ResellerRebateMilestone::query()->with('reseller')->whereDate('period', $period)
            ->orderBy('reseller_id')->orderBy('kind')->orderBy('step')->get();
        $finalised = ResellerRebateMonth::query()->whereDate('period', $period)->exists();

        $resellers = $this->months->resellerIds($year, $month)->map(function (int $id) use ($year, $month, $rows, $finalised) {
            $reseller = Reseller::withTrashed()->find($id);
            $standing = $this->entitlement->standing($reseller, $year, $month);
            $own = $rows->where('reseller_id', $id);
            $claimed = (int) $own->where('state', ResellerRebateMilestone::CLAIMED)->sum('amount_sen');
            $inProgramme = $reseller->rebate_eligible && ! $reseller->trashed();

            return [
                'reseller_id' => $id,
                'business_name' => $reseller->business_name,
                'tier_id' => $standing->tierId,
                'eligible' => $inProgramme,
                'closed' => $reseller->trashed(),
                'sales_sen' => $standing->salesSen,
                'profit_sen' => $standing->profitSen,
                'reached_step' => $standing->reachedStep,
                'cap_sen' => $standing->capSen,
                'entitlement_sen' => $standing->entitlementSen(),
                'claimed_sen' => $claimed,
                // Waiting to be claimed: the rows finalise wrote, or, while the month is live, what is
                // reached and not claimed yet (the same figure as RebateLiability's payable).
                'available_sen' => $finalised
                    ? (int) $own->where('state', ResellerRebateMilestone::AVAILABLE)->sum('amount_sen')
                    : ($inProgramme && $standing->earnsRebate() ? max(0, $standing->entitlementSen() - $claimed) : 0),
                'expired_sen' => (int) $own->where('state', ResellerRebateMilestone::EXPIRED)->sum('amount_sen'),
                'adjusted' => $own->where('adjusted', true)->isNotEmpty(),
            ];
        })->values()->all();

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'finalised' => $finalised,
            'claim_deadline' => ResellerRebateMilestone::claimDeadline(CarbonImmutable::parse($period))->toIso8601String(),
            'resellers' => $resellers,
            'rows' => $rows->map(fn (ResellerRebateMilestone $row) => self::rowShape($row))->values()->all(),
        ];
    }

    /**
     * Every rebate record that touches the month: rows earned in it, rows
     * claimed or expired in it (whatever month they were earned in), and
     * closure forfeits. Each row carries all its dates and calculation inputs.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $year, int $month): array
    {
        [$from, $to] = MonthlyAccountingSummaryService::monthBounds($year, $month);
        $period = CarbonImmutable::create($year, $month, 1, 0, 0, 0, MalaysiaTime::TIMEZONE)->toDateString();

        $rewards = ResellerRebateMilestone::query()->with('reseller')
            ->where(fn ($q) => $q->whereDate('period', $period)
                ->orWhere(fn ($q) => $q->where('claimed_at', '>=', $from)->where('claimed_at', '<', $to))
                ->orWhere(fn ($q) => $q->where('expired_at', '>=', $from)->where('expired_at', '<', $to)))
            ->orderBy('period')->orderBy('reseller_id')->orderBy('kind')->orderBy('step')->get()
            ->map(fn (ResellerRebateMilestone $row) => ['record' => 'reward', ...self::rowShape($row)]);

        $forfeits = LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('type', WalletEntryType::RebateForfeit->value)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->orderBy('id')->get()
            ->map(fn (LedgerEntry $e) => [
                'record' => 'forfeit',
                'business_name' => Reseller::withTrashed()->find($e->owner_id)?->business_name,
                'amount_sen' => -(int) $e->amount,
                'state' => 'forfeited',
                'forfeited_at' => $e->created_at->toIso8601String(),
            ]);

        return [...$rewards->all(), ...$forfeits->all()];
    }

    /** @return array<string, mixed> */
    private static function rowShape(ResellerRebateMilestone $row): array
    {
        return [
            'id' => $row->id,
            'reseller_id' => $row->reseller_id,
            'business_name' => $row->reseller?->business_name,
            'period' => $row->period->format('Y-m'),
            'kind' => $row->kind,
            'step' => $row->step,
            'amount_sen' => $row->amount_sen,
            'state' => $row->state,
            'adjusted' => $row->adjusted,
            'ladder_id' => $row->ladder_id,
            'tier_id' => $row->tier_id,
            'sales_sen' => $row->sales_sen,
            'profit_sen' => $row->profit_sen,
            'ladder_cumulative_sen' => $row->ladder_cumulative_sen,
            'cap_sen' => $row->cap_sen,
            'computed_at' => $row->computed_at?->toIso8601String(),
            'claimed_at' => $row->claimed_at?->toIso8601String(),
            'expired_at' => $row->expired_at?->toIso8601String(),
        ];
    }
}
