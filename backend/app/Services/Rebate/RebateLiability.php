<?php

namespace App\Services\Rebate;

use App\Models\LedgerEntry;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerRebateMilestone;
use App\Models\ResellerRebateMonth;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Reseller\WalletEntryType;
use App\Support\MalaysiaTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ADR-139 decisions 20–22 and the accountant addendum (items 2 and 4): the
 * rebate's three accounting figures, read by the Monthly Summary, Reports'
 * profit Bridge and Cash Position. The cash equation balances at every
 * state (decision 21's table):
 *
 *   reached    −x reduction   +x payable
 *   claimed                   −x payable   +x Purchase Balance
 *   expired    +x (reversal)  −x payable
 *   forfeited  +x breakage                 −x Purchase Balance
 *
 * A month is dated by its period (the month it was earned in). A finalised
 * month reads its milestone rows; a month not finalised yet is live, from
 * RebateMonthService::liveTotalSen(), the same function finalise allocates
 * by, so finalising moves nothing.
 */
final class RebateLiability
{
    public function __construct(private readonly RebateMonthService $months) {}

    /**
     * `reseller_rebates_sen`: the revenue reduction of every rebate month
     * whose 1st (KL) falls in the range, less the rows that expired in the
     * range (a reversal in the expiry month; the earned month never moves).
     */
    public function reductionSen(CarbonInterface $from, CarbonInterface $toExclusive): int
    {
        $total = 0;
        foreach ($this->months() as [$start, $finalised]) {
            if ($start->lessThan($from) || $start->greaterThanOrEqualTo($toExclusive)) {
                continue;
            }
            $total += $finalised
                ? (int) ResellerRebateMilestone::query()->whereDate('period', self::period($start))->sum('amount_sen')
                : $this->months->liveTotalSen((int) $start->format('Y'), (int) $start->format('n'));
        }

        return $total - (int) ResellerRebateMilestone::query()
            ->where('state', ResellerRebateMilestone::EXPIRED)
            ->where('expired_at', '>=', $from)->where('expired_at', '<', $toExclusive)
            ->sum('amount_sen');
    }

    /** `reseller_rebate_breakage_sen`: rebate credit already in Purchase Balance, forfeited at closure (the only breakage). */
    public function breakageSen(CarbonInterface $from, CarbonInterface $toExclusive): int
    {
        return -(int) LedgerEntry::query()
            ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
            ->where('type', WalletEntryType::RebateForfeit->value)
            ->where('created_at', '>=', $from)->where('created_at', '<', $toExclusive)
            ->sum('amount');
    }

    /**
     * `reseller_rebates_payable_sen` at an instant: earned (its month has
     * started) and neither claimed nor expired by then. An open month's
     * part is its live total less what was claimed before the instant.
     */
    public function payableSen(CarbonInterface $asOf): int
    {
        $total = 0;
        foreach ($this->months() as [$start, $finalised]) {
            if ($start->greaterThanOrEqualTo($asOf)) {
                continue;
            }
            $rows = ResellerRebateMilestone::query()->whereDate('period', self::period($start));
            $total += $finalised
                ? (int) $rows
                    ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '>=', $asOf))
                    ->where(fn ($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>=', $asOf))
                    ->sum('amount_sen')
                : $this->months->liveTotalSen((int) $start->format('Y'), (int) $start->format('n'))
                    - (int) $rows->where('state', ResellerRebateMilestone::CLAIMED)->where('claimed_at', '<', $asOf)->sum('amount_sen');
        }

        return $total;
    }

    /**
     * Every month from the first ladder to the current one, as its first KL
     * instant and whether it is finalised.
     *
     * @return list<array{0: CarbonImmutable, 1: bool}>
     */
    private function months(): array
    {
        $first = ResellerRebateLadder::query()->min('effective_from');
        if ($first === null) {
            return [];
        }

        $finalised = ResellerRebateMonth::query()->pluck('period')->map(fn ($p) => self::period(CarbonImmutable::parse($p)))->flip();
        $current = CarbonImmutable::now(MalaysiaTime::TIMEZONE)->startOfMonth();
        $months = [];
        for ($m = CarbonImmutable::parse($first, MalaysiaTime::TIMEZONE)->startOfMonth(); $m->lessThanOrEqualTo($current); $m = $m->addMonthNoOverflow()) {
            $months[] = [$m, $finalised->has(self::period($m))];
        }

        return $months;
    }

    private static function period(CarbonInterface $monthStart): string
    {
        return $monthStart->format('Y-m-01');
    }
}
