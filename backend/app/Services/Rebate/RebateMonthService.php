<?php

namespace App\Services\Rebate;

use App\Models\LedgerAccount;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerRebateMilestone;
use App\Models\ResellerRebateMonth;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Ledger\LedgerOwnerType;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * ADR-139 decisions 9, 10, 12, 19: the month's end, run by scheduled tasks.
 *
 * finalize(): once per ended Malaysia month. For every reseller with
 * recognised wallet sales or rebate rows that month, it writes each reached
 * but unclaimed step as an `available` row (amounts allocated in order from
 * the final entitlement, so claiming early never pays less than late), then
 * an `adjustment` row if the final entitlement still exceeds what is
 * allocated. A cut, an adjustment or an overpayment emails every admin.
 * Takes the same per-reseller wallet mutex as a claim.
 *
 * expireDue(): an `available` row past its deadline (00:00 KL on the 1st of
 * M+2) becomes `expired`, dated at the deadline, not at the run.
 */
final class RebateMonthService
{
    public function __construct(
        private readonly RebateEntitlement $entitlement,
        private readonly BackupFailureAlerter $alerter,
    ) {}

    /** Finalises every ended month since the first ladder that has not been finalised. @return int months finalised */
    public function finalizeDue(): int
    {
        $first = ResellerRebateLadder::query()->min('effective_from');
        if ($first === null) {
            return 0;
        }

        $month = CarbonImmutable::parse($first, MalaysiaTime::TIMEZONE)->startOfMonth();
        $current = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->startOfMonth();
        $done = 0;

        for (; $month->lessThan($current); $month = $month->addMonthNoOverflow()) {
            if (! ResellerRebateMonth::query()->whereDate('period', $month->toDateString())->exists()) {
                $this->finalize((int) $month->format('Y'), (int) $month->format('n'));
                $done++;
            }
        }

        return $done;
    }

    public function finalize(int $year, int $month): void
    {
        $period = CarbonImmutable::create($year, $month, 1, 0, 0, 0, MalaysiaTime::TIMEZONE);
        [$from, $toExclusive] = MonthlyAccountingSummaryService::monthBounds($year, $month);

        $resellerIds = Order::query()
            ->where('is_test', false)->whereNotNull('wallet_reseller_id')
            ->where('recognised_at', '>=', $from)->where('recognised_at', '<', $toExclusive)
            ->distinct()->pluck('wallet_reseller_id')
            ->merge(ResellerRebateMilestone::query()->whereDate('period', $period->toDateString())->distinct()->pluck('reseller_id'))
            ->unique()->sort()->values();

        $notes = [];
        foreach ($resellerIds as $id) {
            $reseller = Reseller::query()->find($id);
            if ($reseller === null || ! $reseller->rebate_eligible) {
                continue; // closed (its rows were expired at closure) or out of the programme
            }

            $note = DB::transaction(fn () => $this->finalizeReseller($reseller, $year, $month, $period));
            if ($note !== null) {
                $notes[] = $note;
            }
        }

        ResellerRebateMonth::query()->create(['period' => $period->toDateString(), 'finalized_at' => now()]);

        if ($notes !== []) {
            $this->alerter->alert(
                "Rebate {$period->format('M Y')}: rewards were capped, adjusted or overpaid",
                implode("\n", $notes)."\nCheck the ladder against actual profit for the next version.",
                'Rebate',
            );
        }
    }

    /** @return int rows expired */
    public function expireDue(): int
    {
        $now = CarbonImmutable::now(MalaysiaTime::TIMEZONE);
        $expired = 0;

        ResellerRebateMilestone::query()
            ->where('state', ResellerRebateMilestone::AVAILABLE)
            ->whereDate('period', '<=', $now->subMonthsNoOverflow(2)->startOfMonth()->toDateString())
            ->orderBy('id')
            ->get()
            ->each(function (ResellerRebateMilestone $row) use (&$expired) {
                $deadline = CarbonImmutable::parse($row->period->toDateString(), MalaysiaTime::TIMEZONE)->addMonthsNoOverflow(2)->utc();
                $expired += ResellerRebateMilestone::query()->whereKey($row->id)->where('state', ResellerRebateMilestone::AVAILABLE)
                    ->update(['state' => ResellerRebateMilestone::EXPIRED, 'expired_at' => $deadline]);
            });

        return $expired;
    }

    private function finalizeReseller(Reseller $reseller, int $year, int $month, CarbonImmutable $period): ?string
    {
        LedgerAccount::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('owner_id', $reseller->id)
            ->lockForUpdate()
            ->first();

        $standing = $this->entitlement->standing($reseller, $year, $month);
        if (! $standing->earnsRebate()) {
            return null;
        }

        $rows = ResellerRebateMilestone::query()->where('reseller_id', $reseller->id)
            ->whereDate('period', $period->toDateString())->lockForUpdate()->get();
        $allocated = (int) $rows->sum('amount_sen');
        $have = $rows->where('kind', ResellerRebateMilestone::KIND_STEP)->pluck('step')->all();
        $cut = $rows->where('adjusted', true)->isNotEmpty();
        $inputs = [
            'reseller_id' => $reseller->id,
            'period' => $period->toDateString(),
            'ladder_id' => $standing->ladder->id,
            'tier_id' => $standing->tierId,
            'state' => ResellerRebateMilestone::AVAILABLE,
            'sales_sen' => $standing->salesSen,
            'profit_sen' => $standing->profitSen,
            'cap_sen' => $standing->capSen,
            'computed_at' => now(),
        ];

        for ($step = 1; $step <= $standing->reachedStep; $step++) {
            if (in_array($step, $have, true)) {
                continue;
            }
            $amount = max(0, $standing->entitlementThrough($step) - $allocated);
            $adjusted = $amount < $standing->ladderCumulative($step) - $standing->ladderCumulative($step - 1);
            ResellerRebateMilestone::query()->create([...$inputs, 'kind' => ResellerRebateMilestone::KIND_STEP, 'step' => $step,
                'amount_sen' => $amount, 'adjusted' => $adjusted, 'ladder_cumulative_sen' => $standing->ladderCumulative($step)]);
            $allocated += $amount;
            $cut = $cut || $adjusted;
        }

        $final = $standing->entitlementSen();
        if ($final > $allocated) {
            ResellerRebateMilestone::query()->create([...$inputs, 'kind' => ResellerRebateMilestone::KIND_ADJUSTMENT, 'step' => 0,
                'amount_sen' => $final - $allocated, 'adjusted' => true, 'ladder_cumulative_sen' => $standing->ladderCumulative($standing->reachedStep)]);

            return "{$reseller->business_name}: an earlier capped claim is made up by an adjustment of RM".number_format(($final - $allocated) / 100, 2).'.';
        }
        if ($allocated > $final) {
            return "{$reseller->business_name}: paid RM".number_format($allocated / 100, 2).' against a final entitlement of RM'.number_format($final / 100, 2).' (a negative-profit order after a claim). Not clawed back.';
        }

        return $cut ? "{$reseller->business_name}: a reward was capped below its ladder amount." : null;
    }
}
