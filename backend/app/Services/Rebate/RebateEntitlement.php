<?php

namespace App\Services\Rebate;

use App\Models\Reseller;
use App\Models\ResellerRebateMilestone;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Accounting\RecognisedRevenue;
use App\Services\Reseller\ResellerTierChangeService;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ADR-139 decision 12 (stress test F1): the one function every rebate amount
 * comes from — claims, the month finalise, the portal and the admin views.
 *
 *   entitlement = min(ladder cumulative at the highest step reached,
 *                     intdiv(maxShare × recognised platform_profit to date))
 *
 * Reach and profit come from RecognisedRevenue (decision 5); the month's
 * tier from the tier history (decision 7); the ladder from the version in
 * force (decision 6). maxShare never exceeds the 50% hard ceiling.
 */
final class RebateEntitlement
{
    public function __construct(
        private readonly RecognisedRevenue $revenue,
        private readonly RebateLadderService $ladders,
        private readonly ResellerTierChangeService $tiers,
    ) {}

    public function standing(Reseller $reseller, int $year, int $month, ?CarbonInterface $asOf = null): RebateStanding
    {
        [$from, $toExclusive] = MonthlyAccountingSummaryService::monthBounds($year, $month);
        $monthStart = CarbonImmutable::create($year, $month, 1, 0, 0, 0, MalaysiaTime::TIMEZONE);
        $until = $asOf === null || $asOf->greaterThan($toExclusive) ? $toExclusive : $asOf;

        $ladder = $this->ladders->forMonth($monthStart);
        $totals = $this->revenue->walletResellerTotals($reseller->id, $from, $until);
        $shareBp = $ladder === null ? 0 : min($ladder->max_share_bp, RebateLadderService::HARD_CEILING_BP);

        return new RebateStanding(
            ladder: $ladder,
            tierId: $this->tiers->tierForMonth($reseller, $monthStart),
            salesSen: $totals['sales_sen'],
            profitSen: $totals['profit_sen'],
            reachedStep: $ladder?->reachedStep($totals['sales_sen']) ?? 0,
            capSen: max(0, intdiv($totals['profit_sen'] * $shareBp, 10000)),
            paidSen: (int) ResellerRebateMilestone::query()
                ->where('reseller_id', $reseller->id)
                ->whereDate('period', $monthStart->toDateString())
                ->where('state', ResellerRebateMilestone::CLAIMED)
                ->sum('amount_sen'),
        );
    }
}
