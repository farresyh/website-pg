<?php

namespace App\Services\Campaign;

use App\Models\AccountingPeriodClose;
use App\Models\Affiliate;
use App\Models\BudgetEnvelopePosting;
use App\Models\Campaign;
use App\Models\CampaignFee;
use App\Models\CampaignMonthStatement;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Services\Accounting\AccountingPeriod;
use App\Services\Accounting\EnvelopePostingType;
use App\Services\Accounting\MonthlyAccountingSummaryService;
use App\Services\Accounting\RecognisedRevenue;
use App\Support\ContactMask;
use App\Support\MalaysiaTime;
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
 *
 * ADR-118 2026-11-19 addendum (V26–V34): a row may also take a share of
 * the month's recognised sales of the campaign's attributed orders,
 * limited by that set's profit and an optional cap. termsFor() is the
 * deal; feeForMonth() is the amount — frozen by the close for a closed
 * month (freezeMonth(), the only writer of CampaignMonthStatement), a
 * live estimate for an open one.
 */
final class CampaignFeeSchedule
{
    /** @var array<int, array<string, int>>|null campaign_id => 'Y-m' => frozen payable */
    private ?array $frozen = null;

    /** @var array<string, Collection<int, array{campaign_id: int, order: Order, sales_sen: int, profit_sen: int}>> 'Y-m' => attributed sales */
    private array $sales = [];

    public function __construct(private readonly RecognisedRevenue $revenue) {}

    /** A campaign never exists without its fee (RM0 allowed). V1: the primary brand only. */
    public function createCampaign(string $name, int $monthlyFeeSen, string $fromMonth, ?string $toMonth, int $adminUserId, int $revenueShareBp = 0, ?int $revenueShareCapSen = null): Campaign
    {
        $from = self::month($fromMonth);
        $to = $toMonth === null ? null : self::month($toMonth);
        $this->assertRow($monthlyFeeSen, $revenueShareBp, $revenueShareCapSen, $from, $to);
        AccountingPeriod::assertOpen($from, 'from_month');

        return DB::transaction(function () use ($name, $monthlyFeeSen, $revenueShareBp, $revenueShareCapSen, $from, $to, $adminUserId) {
            $campaign = Campaign::query()->create([
                'affiliate_id' => Affiliate::primary()->id,
                'name' => $name,
                'created_by' => $adminUserId,
            ]);

            $campaign->fees()->create([
                'monthly_fee_sen' => $monthlyFeeSen, 'revenue_share_bp' => $revenueShareBp, 'revenue_share_cap_sen' => $revenueShareCapSen,
                'from_month' => $from, 'to_month' => $to, 'created_by' => $adminUserId,
            ]);

            return $campaign->load('fees');
        });
    }

    /** A renegotiation: a new row from its month, after every existing row. */
    public function addFee(Campaign $campaign, int $monthlyFeeSen, string $fromMonth, ?string $toMonth, int $adminUserId, int $revenueShareBp = 0, ?int $revenueShareCapSen = null): CampaignFee
    {
        $from = self::month($fromMonth);
        $to = $toMonth === null ? null : self::month($toMonth);
        $this->assertRow($monthlyFeeSen, $revenueShareBp, $revenueShareCapSen, $from, $to);
        AccountingPeriod::assertOpen($from, 'from_month');

        return DB::transaction(function () use ($campaign, $monthlyFeeSen, $revenueShareBp, $revenueShareCapSen, $from, $to, $adminUserId) {
            $latest = CampaignFee::query()->where('campaign_id', $campaign->id)->lockForUpdate()->max('from_month');

            if ($latest !== null && $from->toDateString() <= Carbon::parse($latest)->toDateString()) {
                $this->reject('from_month', 'A new fee must start after '.Carbon::parse($latest)->format('F Y').', the latest one.');
            }

            return CampaignFee::query()->create([
                'campaign_id' => $campaign->id, 'monthly_fee_sen' => $monthlyFeeSen, 'revenue_share_bp' => $revenueShareBp, 'revenue_share_cap_sen' => $revenueShareCapSen,
                'from_month' => $from, 'to_month' => $to, 'created_by' => $adminUserId,
            ]);
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

    /**
     * V31: the agreed terms for a month (for labels and forms, never an
     * amount): the latest row started by then, unless it has ended.
     *
     * @return array{monthly_fee_sen: int, revenue_share_bp: int, revenue_share_cap_sen: ?int}|null
     */
    public function termsFor(Campaign $campaign, string|Carbon $month): ?array
    {
        $row = $this->rowFor($campaign->fees, self::month($month));

        return $row === null ? null : ['monthly_fee_sen' => $row->monthly_fee_sen, 'revenue_share_bp' => $row->revenue_share_bp, 'revenue_share_cap_sen' => $row->revenue_share_cap_sen];
    }

    /** The fee accrued for one month: the frozen statement once closed (V30), else fixed + share, live. */
    public function feeForMonth(Campaign $campaign, string|Carbon $month): int
    {
        $m = self::month($month);
        $frozen = $this->frozen()[$campaign->id][$m->format('Y-m')] ?? null;

        if ($frozen !== null) {
            return $frozen;
        }

        $row = $this->rowFor($campaign->fees, $m);

        return match (true) {
            $row === null => 0,
            $row->revenue_share_bp === 0 => $row->monthly_fee_sen,
            default => $this->monthFigures($campaign, $m)['payable_sen'],
        };
    }

    /**
     * V27 + V28, computed live: payable = fixed + max(0, min(⌊sales × bp ÷
     * 10 000⌋, profit, cap)). The limit is on the share only; the fixed fee
     * is the agreed deal.
     *
     * @return array{monthly_fee_sen: int, revenue_share_bp: int, revenue_share_cap_sen: ?int, sales_sen: int, profit_sen: int, share_before_limits_sen: int, share_sen: int, limited_by: ?string, payable_sen: int, lines: Collection<int, array{campaign_id: int, order: Order, sales_sen: int, profit_sen: int}>}
     */
    public function monthFigures(Campaign $campaign, string|Carbon $month): array
    {
        $m = self::month($month);
        $row = $this->rowFor($campaign->fees, $m);
        $lines = $row === null ? collect() : $this->salesIn($m)->where('campaign_id', $campaign->id)->sortBy(fn (array $l) => $l['order']->recognised_at)->values();
        $sales = (int) $lines->sum('sales_sen');
        $profit = (int) $lines->sum('profit_sen');
        $bp = $row?->revenue_share_bp ?? 0;
        $cap = $row?->revenue_share_cap_sen;

        $before = intdiv(max(0, $sales) * $bp, 10_000);
        $share = max(0, min($before, $profit, $cap ?? PHP_INT_MAX));
        $limitedBy = match (true) {
            $share >= $before => null,
            $cap !== null && $cap <= $profit => 'cap',
            default => 'profit',
        };

        return [
            'monthly_fee_sen' => $row?->monthly_fee_sen ?? 0, 'revenue_share_bp' => $bp, 'revenue_share_cap_sen' => $cap,
            'sales_sen' => $sales, 'profit_sen' => $profit, 'share_before_limits_sen' => $before, 'share_sen' => $share,
            'limited_by' => $limitedBy, 'payable_sen' => ($row?->monthly_fee_sen ?? 0) + $share, 'lines' => $lines,
        ];
    }

    /**
     * V30: called by MonthCloseService::close() inside its transaction. One
     * statement per campaign whose rows cover the month (fixed-only too), so
     * every closed month from here on reads a frozen figure.
     */
    public function freezeMonth(AccountingPeriodClose $close): void
    {
        $month = self::month($close->period_month);

        Campaign::query()->with('fees')->orderBy('id')->get()
            ->filter(fn (Campaign $c) => $this->rowFor($c->fees, $month) !== null)
            ->each(function (Campaign $campaign) use ($close, $month) {
                $figures = $this->monthFigures($campaign, $month);

                CampaignMonthStatement::query()->create([
                    ...collect($figures)->except('lines')->all(),
                    'campaign_id' => $campaign->id,
                    'accounting_period_close_id' => $close->id,
                    'period_month' => $month->toDateString(),
                    'lines' => $figures['lines']->map(fn (array $l) => [
                        // Q14: a shortened order number and a masked player ID; sales only, never cost or profit.
                        'order' => '…'.substr($l['order']->order_number, -6),
                        'date' => $l['order']->recognised_at->copy()->setTimezone(MalaysiaTime::TIMEZONE)->toDateString(),
                        'game' => $l['order']->game?->name,
                        'package' => $l['order']->package?->name,
                        'player' => ContactMask::identifier($l['order']->player_id),
                        'sales_sen' => $l['sales_sen'],
                    ])->all(),
                ]);
            });

        $this->frozen = null;
    }

    /** V30 (Q17): a reopen voids the month's statements with its close; the re-close freezes new ones. */
    public function voidMonth(AccountingPeriodClose $close): void
    {
        CampaignMonthStatement::query()->active()->where('accounting_period_close_id', $close->id)->get()
            ->each(fn (CampaignMonthStatement $s) => $s->update(['voided_at' => now()]));

        $this->frozen = null;
    }

    /**
     * V29: the promo margin floor can't drop below a share still running
     * (a row covering this month or later), or its no-loss guarantee lapses.
     */
    public function assertFloorAllows(float $percent): void
    {
        $now = self::month(Carbon::now(MalaysiaTime::TIMEZONE));
        $running = Campaign::query()->with('fees')->get()->flatMap(function (Campaign $c) use ($now) {
            $rows = $c->fees->values();

            return $rows->filter(function (CampaignFee $row, int $i) use ($rows, $now) {
                $next = $rows[$i + 1] ?? null;
                $end = $next === null ? $row->to_month : ($row->to_month === null ? $next->from_month->copy()->subMonthNoOverflow() : $row->to_month->min($next->from_month->copy()->subMonthNoOverflow()));

                return $end === null || $end->gte($now);
            });
        });
        $highest = (int) $running->max('revenue_share_bp');

        if ((int) round($percent * 100) < $highest) {
            $this->reject('campaign_min_margin_percent', sprintf('A KOL share of %s%% is still running; the floor cannot be lower.', rtrim(rtrim(number_format($highest / 100, 2), '0'), '.')));
        }
    }

    /** Σ accrued from the campaign's first month through the given month, inclusive. */
    public function accruedThroughSen(Campaign $campaign, string|Carbon $month): int
    {
        $end = self::month($month);
        $first = $campaign->fees->first()?->from_month;
        $total = 0;

        for ($m = $first?->copy(); $m !== null && $m->lte($end); $m->addMonthNoOverflow()) {
            $total += $this->feeForMonth($campaign, $m);
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
     * before the payment is saved. A future month's share is unknown, so
     * "covers until" counts the fixed fee only (V31).
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
            $fee = $this->rowFor($campaign->fees, $m)?->monthly_fee_sen ?? 0;
            if ($fee === 0 || $left < $fee) {
                break;
            }
            $left -= $fee;
            $coversUntil = $m->format('Y-m');
        }

        return ['balance_before_sen' => $before, 'balance_after_sen' => $after, 'covers_until' => $after >= 0 ? ($coversUntil ?? $month->format('Y-m')) : null];
    }

    /** @param Collection<int, CampaignFee> $fees oldest first */
    private function rowFor($fees, Carbon $month): ?CampaignFee
    {
        $row = $fees->filter(fn (CampaignFee $f) => $f->from_month->lte($month))->last();

        return $row === null || ($row->to_month !== null && $row->to_month->lt($month)) ? null : $row;
    }

    /** Every frozen payable, read once per instance (one query, however many campaigns and months). */
    private function frozen(): array
    {
        return $this->frozen ??= CampaignMonthStatement::query()->active()->get(['campaign_id', 'period_month', 'payable_sen'])
            ->groupBy('campaign_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn (CampaignMonthStatement $s) => [$s->period_month->format('Y-m') => $s->payable_sen])->all())
            ->all();
    }

    /** The month's attributed sales for every campaign, one read per month per instance. */
    private function salesIn(Carbon $month): Collection
    {
        return $this->sales[$month->format('Y-m')] ??= $this->revenue->attributedSales(...MonthlyAccountingSummaryService::monthBounds($month->year, $month->month));
    }

    private function assertRow(int $monthlyFeeSen, int $revenueShareBp, ?int $revenueShareCapSen, Carbon $from, ?Carbon $to): void
    {
        if ($monthlyFeeSen < 0) {
            $this->reject('monthly_fee_sen', 'The fee cannot be negative.');
        }

        // V29: never above the promo margin floor, which every promo order keeps (V10).
        $floorBp = (int) round((float) PlatformSettings::current()->campaign_min_margin_percent * 100);
        if ($revenueShareBp < 0 || $revenueShareBp > $floorBp) {
            $this->reject('revenue_share_bp', sprintf('The share can be 0%% to %s%%, the promo margin floor in Campaign settings.', rtrim(rtrim(number_format($floorBp / 100, 2), '0'), '.')));
        }

        if ($revenueShareCapSen !== null && $revenueShareCapSen < 0) {
            $this->reject('revenue_share_cap_sen', 'The cap cannot be negative.');
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
