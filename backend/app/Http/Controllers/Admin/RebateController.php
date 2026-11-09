<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRebateLadderRequest;
use App\Http\Requests\Admin\UpdateRebateEligibilityRequest;
use App\Models\Reseller;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerTier;
use App\Services\Rebate\RebateEligibilityService;
use App\Services\Rebate\RebateLadderService;
use App\Services\Rebate\RebateMonthReport;
use App\Services\Rebate\RebateNotifier;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADR-139 decisions 17, 18, 36 (PR 4a): the admin side of the reseller
 * rebate. Saving a ladder and changing eligibility sit behind the MFA
 * step-up (routes). A ladder version is never edited, so the version list is
 * its own audit log: who saved what, when, and what it replaced.
 */
class RebateController extends Controller
{
    public function __construct(
        private readonly RebateLadderService $ladders,
        private readonly RebateMonthReport $report,
    ) {}

    public function ladders(): JsonResponse
    {
        $monthStart = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->startOfMonth();
        $inForce = $this->ladders->forMonth($monthStart);
        $latest = ResellerRebateLadder::query()->orderByDesc('effective_from')->orderByDesc('id')->first();
        $withColumn = collect($latest?->steps[0]['rewards'] ?? [])->keys()->map(fn ($k) => (int) $k);

        return response()->json([
            'ladders' => ResellerRebateLadder::query()->with('admin:id,name')->orderByDesc('effective_from')->orderByDesc('id')->get()
                ->map(fn (ResellerRebateLadder $l) => [
                    'id' => $l->id,
                    'effective_from' => $l->effective_from->toDateString(),
                    'max_share_bp' => $l->max_share_bp,
                    'steps' => $l->steps,
                    'saved_by' => $l->admin?->name,
                    'saved_at' => $l->created_at->toIso8601String(),
                    'status' => match (true) {
                        $l->id === $inForce?->id => 'in_force',
                        $l->effective_from->greaterThan($monthStart) => 'upcoming',
                        default => 'past',
                    },
                ]),
            'tiers' => ResellerTier::query()->orderBy('sort_order')->get(['id', 'name', 'markup_percent', 'is_active']),
            // Decision 44: a tier with no column in the newest version earns nothing.
            'tiers_without_column' => $latest === null ? [] : ResellerTier::query()->where('is_active', true)
                ->whereNotIn('id', $withColumn)->orderBy('sort_order')->pluck('id'),
            'next_effective_from' => RebateLadderService::nextEffectiveFrom()->toDateString(),
            'hard_ceiling_bp' => RebateLadderService::HARD_CEILING_BP,
        ]);
    }

    public function storeLadder(StoreRebateLadderRequest $request, RebateNotifier $notifier): JsonResponse
    {
        $ladder = $this->ladders->saveVersion($request->validated('steps'), (int) $request->validated('max_share_bp'), $request->user()->id);
        $notifier->ladderSaved($ladder);

        return response()->json(['id' => $ladder->id, 'effective_from' => $ladder->effective_from->toDateString()], 201);
    }

    public function month(int $year, int $month): JsonResponse
    {
        return response()->json($this->report->forMonth($year, $month));
    }

    public function export(int $year, int $month): StreamedResponse
    {
        $rows = $this->report->exportRows($year, $month);
        $rm = fn (?int $sen) => $sen === null ? '' : number_format($sen / 100, 2, '.', '');

        return response()->streamDownload(function () use ($rows, $rm) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Record', 'Reseller', 'Period', 'Kind', 'Step', 'Amount (RM)', 'State', 'Adjusted', 'Recognised sales (RM)', 'Recognised profit (RM)', 'Ladder cumulative (RM)', 'Cap (RM)', 'Ladder version', 'Tier', 'Computed at', 'Claimed at', 'Expired at', 'Forfeited at']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['record'], $r['business_name'] ?? '', $r['period'] ?? '', $r['kind'] ?? '', $r['step'] ?? '',
                    $rm($r['amount_sen']), $r['state'], isset($r['adjusted']) ? ($r['adjusted'] ? 'yes' : 'no') : '',
                    $rm($r['sales_sen'] ?? null), $rm($r['profit_sen'] ?? null), $rm($r['ladder_cumulative_sen'] ?? null), $rm($r['cap_sen'] ?? null),
                    $r['ladder_id'] ?? '', $r['tier_id'] ?? '', $r['computed_at'] ?? '', $r['claimed_at'] ?? '', $r['expired_at'] ?? '', $r['forfeited_at'] ?? '',
                ]);
            }
            fclose($out);
        }, sprintf('rebate-%04d-%02d.csv', $year, $month), ['Content-Type' => 'text/csv']);
    }

    public function updateEligibility(UpdateRebateEligibilityRequest $request, Reseller $reseller, RebateEligibilityService $eligibility): JsonResponse
    {
        $updated = $eligibility->set($reseller, $request->boolean('eligible'), $request->user()->id, $request->validated('reason'));

        return response()->json(['id' => $updated->id, 'rebate_eligible' => $updated->rebate_eligible]);
    }
}
