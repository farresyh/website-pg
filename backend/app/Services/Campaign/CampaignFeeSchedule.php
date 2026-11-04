<?php

namespace App\Services\Campaign;

use App\Models\Affiliate;
use App\Models\BudgetEnvelopePosting;
use App\Models\Campaign;
use App\Models\CampaignFee;
use App\Services\Accounting\AccountingPeriod;
use App\Services\Accounting\EnvelopePostingType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-118 V12 (the accountant's rule): a KOL fee is an advertising expense
 * in its month of service. The schedule is the agreement (accrued per
 * month); campaign-tagged Envelope Ledger postings are the cash. Their
 * difference is the prepaid / (accrued) balance, always computed.
 *
 * Nothing that touches a closed month can change (AccountingPeriod), and
 * none of this feeds operating profit or the month-close allocation: the
 * Envelope payment already reduced its envelope (ADR-083 Q9).
 */
final class CampaignFeeSchedule
{
    /** A campaign never exists without its fee (RM0 allowed). V1: the primary brand only. */
    public function createCampaign(string $name, int $monthlyFeeSen, string $fromMonth, ?string $toMonth, int $adminUserId): Campaign
    {
        $from = self::month($fromMonth);
        $to = $toMonth === null ? null : self::month($toMonth);
        $this->assertRow($monthlyFeeSen, $from, $to);
        AccountingPeriod::assertOpen($from, 'from_month');

        return DB::transaction(function () use ($name, $monthlyFeeSen, $from, $to, $adminUserId) {
            $campaign = Campaign::query()->create([
                'affiliate_id' => Affiliate::primary()->id,
                'name' => $name,
                'created_by' => $adminUserId,
            ]);

            $campaign->fees()->create(['monthly_fee_sen' => $monthlyFeeSen, 'from_month' => $from, 'to_month' => $to, 'created_by' => $adminUserId]);

            return $campaign->load('fees');
        });
    }

    /** A renegotiation: a new row from its month, after every existing row. */
    public function addFee(Campaign $campaign, int $monthlyFeeSen, string $fromMonth, ?string $toMonth, int $adminUserId): CampaignFee
    {
        $from = self::month($fromMonth);
        $to = $toMonth === null ? null : self::month($toMonth);
        $this->assertRow($monthlyFeeSen, $from, $to);
        AccountingPeriod::assertOpen($from, 'from_month');

        return DB::transaction(function () use ($campaign, $monthlyFeeSen, $from, $to, $adminUserId) {
            $latest = CampaignFee::query()->where('campaign_id', $campaign->id)->lockForUpdate()->max('from_month');

            if ($latest !== null && $from->toDateString() <= Carbon::parse($latest)->toDateString()) {
                $this->reject('from_month', 'A new fee must start after '.Carbon::parse($latest)->format('F Y').', the latest one.');
            }

            return CampaignFee::query()->create(['campaign_id' => $campaign->id, 'monthly_fee_sen' => $monthlyFeeSen, 'from_month' => $from, 'to_month' => $to, 'created_by' => $adminUserId]);
        });
    }

    /** The one change allowed on a row, and only where every month it moves is open. */
    public function setEndMonth(CampaignFee $fee, ?string $toMonth): CampaignFee
    {
        $new = $toMonth === null ? null : self::month($toMonth);
        $old = $fee->to_month;

        if ($new !== null && $new->lt($fee->from_month)) {
            $this->reject('to_month', 'The last month cannot be before the first.');
        }

        // The first month whose fee changes: after the earlier of the two ends.
        $firstChanged = match (true) {
            $old === null && $new === null => null,
            $old === null => $new->copy()->addMonthNoOverflow(),
            $new === null => $old->copy()->addMonthNoOverflow(),
            default => $old->min($new)->copy()->addMonthNoOverflow(),
        };

        if ($firstChanged !== null) {
            AccountingPeriod::assertOpen($firstChanged, 'to_month');
        }

        $fee->update(['to_month' => $new]);

        return $fee;
    }

    /** The fee accrued for one month: the latest row started by then, unless it has ended. */
    public function feeForMonth(Campaign $campaign, string|Carbon $month): int
    {
        return $this->feeIn($campaign->fees, self::month($month));
    }

    /** Σ accrued from the campaign's first month through the given month, inclusive. */
    public function accruedThroughSen(Campaign $campaign, string|Carbon $month): int
    {
        $end = self::month($month);
        $fees = $campaign->fees;
        $first = $fees->first()?->from_month;
        $total = 0;

        for ($m = $first?->copy(); $m !== null && $m->lte($end); $m->addMonthNoOverflow()) {
            $total += $this->feeIn($fees, $m);
        }

        return $total;
    }

    /** The cash: Σ the campaign's Envelope payments dated on or before the date (voids net out). */
    public function paidThroughSen(Campaign $campaign, string|Carbon $date): int
    {
        return (int) BudgetEnvelopePosting::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('type', [EnvelopePostingType::Expense->value, EnvelopePostingType::DirectorPaidExpense->value])
            ->whereDate('transaction_date', '<=', Carbon::parse($date)->toDateString())
            ->sum('amount_sen');
    }

    /** Positive = prepaid (paid ahead of the agreement), negative = accrued (owed to the KOL), at the month's end. */
    public function balanceSen(Campaign $campaign, string|Carbon $month): int
    {
        $start = self::month($month);

        return $this->paidThroughSen($campaign, $start->copy()->endOfMonth()) - $this->accruedThroughSen($campaign, $start);
    }

    /**
     * V12: what a payment of $amountSen on $date would leave, and the last
     * month it covers, so a schedule that doesn't match the deal shows
     * before the payment is saved.
     *
     * @return array{balance_before_sen: int, balance_after_sen: int, covers_until: ?string}
     */
    public function paymentPreview(Campaign $campaign, int $amountSen, string $date): array
    {
        $month = self::month($date);
        $before = $this->balanceSen($campaign, $month);
        $after = $before + $amountSen;

        // ponytail: walks month by month, at most 36 ahead; a closed form if schedules ever span years.
        $coversUntil = null;
        $left = $after;
        $m = $month->copy();
        for ($i = 0; $i < 36 && $left > 0; $i++) {
            $m->addMonthNoOverflow();
            $fee = $this->feeIn($campaign->fees, $m);
            if ($fee === 0 || $left < $fee) {
                break;
            }
            $left -= $fee;
            $coversUntil = $m->format('Y-m');
        }

        return ['balance_before_sen' => $before, 'balance_after_sen' => $after, 'covers_until' => $after >= 0 ? ($coversUntil ?? $month->format('Y-m')) : null];
    }

    /** @param Collection<int, CampaignFee> $fees oldest first */
    private function feeIn($fees, Carbon $month): int
    {
        $row = $fees->filter(fn (CampaignFee $f) => $f->from_month->lte($month))->last();

        if ($row === null || ($row->to_month !== null && $row->to_month->lt($month))) {
            return 0;
        }

        return $row->monthly_fee_sen;
    }

    private function assertRow(int $monthlyFeeSen, Carbon $from, ?Carbon $to): void
    {
        if ($monthlyFeeSen < 0) {
            $this->reject('monthly_fee_sen', 'The fee cannot be negative.');
        }

        if ($to !== null && $to->lt($from)) {
            $this->reject('to_month', 'The last month cannot be before the first.');
        }
    }

    /** First day of the month, from `Y-m`, a date, or a Carbon. */
    private static function month(string|Carbon $value): Carbon
    {
        return ($value instanceof Carbon ? $value->copy() : Carbon::parse(strlen($value) === 7 ? "{$value}-01" : $value))->startOfMonth()->startOfDay();
    }

    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
