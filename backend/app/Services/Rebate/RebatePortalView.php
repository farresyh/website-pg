<?php

namespace App\Services\Rebate;

use App\Models\Reseller;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerRebateMilestone;
use App\Models\ResellerRebateMonth;
use App\Models\ResellerRebateTermsAcceptance;
use App\Services\Reseller\ResellerTierChangeService;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;

/**
 * ADR-139 decisions 8, 41 and the founder's PR 4 answers (2026-11-09): what
 * the portal's Rebate page and dashboard card show a reseller. Only "reach
 * RM X → get RM Y" for the reseller's own tier, progress, claim states,
 * unspent rebate and the terms. Never a share, the cap, profit, markup or
 * another tier's column: those would let a reseller back-compute our margin.
 */
final class RebatePortalView
{
    public function __construct(
        private readonly RebateEntitlement $entitlement,
        private readonly RebateLadderService $ladders,
        private readonly ResellerTierChangeService $tiers,
        private readonly UnspentRebate $unspent,
    ) {}

    /** @return array<string, mixed> */
    public function forReseller(Reseller $reseller): array
    {
        $current = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->startOfMonth();
        $months = [];
        foreach ([$current, $current->subMonthNoOverflow()] as $month) {
            if (($view = $this->month($reseller, $month)) !== null) {
                $months[] = $view;
            }
        }

        return [
            'eligible' => $reseller->rebate_eligible,
            'tier_name' => $reseller->tier?->name,
            'terms' => [
                'version' => RebateTerms::VERSION,
                'title' => RebateTerms::TITLE,
                'clauses' => RebateTerms::CLAUSES,
                'accepted' => ResellerRebateTermsAcceptance::query()->where('reseller_id', $reseller->id)->where('terms_version', RebateTerms::VERSION)->exists(),
            ],
            'unspent_rebate_sen' => $this->unspent->forReseller($reseller->id),
            'months' => $months,
            'upcoming_ladder' => $this->upcoming($reseller, $current),
            'history' => ResellerRebateMilestone::query()->where('reseller_id', $reseller->id)
                ->where('state', '!=', ResellerRebateMilestone::AVAILABLE)
                ->with('ladder')->orderByDesc('period')->orderByDesc('step')->limit(24)->get()
                ->map(fn (ResellerRebateMilestone $r) => [
                    'period' => $r->period->format('Y-m'),
                    'kind' => $r->kind,
                    'step' => $r->step,
                    // The step's target under the version it was reached on, for "RM5,000.00 step" (decision 43).
                    'threshold_sen' => $r->kind === ResellerRebateMilestone::KIND_STEP ? (int) ($r->ladder?->steps[$r->step - 1]['threshold_sen'] ?? 0) : null,
                    'amount_sen' => $r->amount_sen,
                    'adjusted' => $r->adjusted,
                    'state' => $r->state,
                    'claimed_at' => $r->claimed_at?->toIso8601String(),
                    'expired_at' => $r->expired_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * One month's ladder for the reseller's tier, with each step's state:
     * locked (not reached), claimable, claimed or expired. Null when the
     * month has no ladder for this tier, or its claim window has closed.
     *
     * @return array<string, mixed>|null
     */
    private function month(Reseller $reseller, CarbonImmutable $month): ?array
    {
        $deadline = ResellerRebateMilestone::claimDeadline($month);
        if (CarbonImmutable::now(MalaysiaTime::TIMEZONE)->greaterThanOrEqualTo($deadline)) {
            return null;
        }

        $standing = $this->entitlement->standing($reseller, (int) $month->format('Y'), (int) $month->format('n'));
        if (! $standing->earnsRebate()) {
            return null;
        }

        $finalised = ResellerRebateMonth::query()->whereDate('period', $month->toDateString())->exists();
        $rows = ResellerRebateMilestone::query()->where('reseller_id', $reseller->id)->whereDate('period', $month->toDateString())->get();
        $stepRows = $rows->where('kind', ResellerRebateMilestone::KIND_STEP)->keyBy('step');
        $adjustment = $rows->firstWhere('kind', ResellerRebateMilestone::KIND_ADJUSTMENT);

        $steps = [];
        foreach ($standing->ladder->steps as $i => $s) {
            $step = $i + 1;
            $row = $stepRows[$step] ?? null;
            $steps[] = [
                'step' => $step,
                'threshold_sen' => (int) $s['threshold_sen'],
                'reward_sen' => $standing->ladderCumulative($step) - $standing->ladderCumulative($step - 1),
                'state' => match (true) {
                    $row !== null => $row->state === ResellerRebateMilestone::AVAILABLE ? 'claimable' : $row->state,
                    ! $finalised && $step <= $standing->reachedStep => 'claimable',
                    default => 'locked',
                },
                'paid_sen' => $row?->state === ResellerRebateMilestone::CLAIMED ? $row->amount_sen : null,
                'adjusted' => (bool) $row?->adjusted,
            ];
        }

        $next = collect($steps)->firstWhere('step', $standing->reachedStep + 1);

        return [
            'period' => $month->format('Y-m'),
            'finalised' => $finalised,
            'claim_deadline' => $deadline->toIso8601String(),
            'sales_sen' => $standing->salesSen,
            'steps' => $steps,
            'adjustment' => $adjustment === null ? null : ['amount_sen' => $adjustment->amount_sen, 'state' => $adjustment->state === ResellerRebateMilestone::AVAILABLE ? 'claimable' : $adjustment->state],
            'next_threshold_sen' => $next['threshold_sen'] ?? null,
            'remaining_sen' => $next === null ? null : max(0, $next['threshold_sen'] - $standing->salesSen),
        ];
    }

    /**
     * The terms addendum, decision 2: a saved version not in force yet,
     * shown as a notice with this reseller's own column only.
     *
     * @return array<string, mixed>|null
     */
    private function upcoming(Reseller $reseller, CarbonImmutable $current): ?array
    {
        $ladder = ResellerRebateLadder::query()->whereDate('effective_from', '>', $current->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        if ($ladder === null) {
            return null;
        }

        // The tier that month will be rewarded on, a scheduled change included (decision 7).
        $tierId = $this->tiers->tierForMonth($reseller, CarbonImmutable::parse($ladder->effective_from->toDateString(), MalaysiaTime::TIMEZONE));
        $steps = [];
        foreach ($tierId === null ? [] : $ladder->steps as $i => $s) {
            $cumulative = $ladder->cumulativeFor((int) $tierId, $i + 1);
            if ($cumulative === null) {
                break; // this tier has no column in that version: it earns nothing (decision 44)
            }
            $steps[] = ['step' => $i + 1, 'threshold_sen' => (int) $s['threshold_sen'], 'reward_sen' => $cumulative - ($ladder->cumulativeFor((int) $tierId, $i) ?? 0)];
        }

        return ['effective_from' => $ladder->effective_from->toDateString(), 'steps' => $steps];
    }
}
