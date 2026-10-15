<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Period-over-period comparison, shared by the Dashboard (today vs
 * yesterday, top games) and Reports Compare period (ADR-104 2026-10-08
 * addendum R12–R14). Extracted from `DashboardService::comparison()`.
 */
final class PeriodComparison
{
    /**
     * @return array{pct: float|null, direction: 'up'|'down'|'flat'|'new'}
     */
    public static function change(int|float $current, int|float $previous): array
    {
        if ($previous == 0) {
            return ['pct' => null, 'direction' => $current == 0 ? 'flat' : 'new'];
        }

        $pct = round((($current - $previous) / $previous) * 100, 2);

        return [
            'pct' => abs($pct),
            'direction' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
        ];
    }

    /**
     * For a figure that is already a percentage (margin): the move in
     * percentage points. `$previousHasData` false (no orders) means there
     * is no previous margin to move from, not a margin of 0.
     *
     * @return array{points: float|null, direction: 'up'|'down'|'flat'|'new'}
     */
    public static function points(float $current, float $previous, bool $previousHasData): array
    {
        if (! $previousHasData) {
            return ['points' => null, 'direction' => 'new'];
        }

        $points = round($current - $previous, 2);

        return [
            'points' => abs($points),
            'direction' => $points > 0 ? 'up' : ($points < 0 ? 'down' : 'flat'),
        ];
    }

    /**
     * R12 — the range to compare a bounded [from, toExclusive) with.
     * Month-to-date compares with the same days of last month, clamped to
     * that month's end; anything else with the same-length range
     * immediately before. Day arithmetic runs in KL, where the bounds are
     * midnights.
     *
     * ADR-104 2026-10-15 (grill Q3): a range still running (it ends after
     * now) is compared up to the same elapsed time, e.g. today to 10:30
     * vs yesterday to 10:30, never vs all of yesterday.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function previousRange(CarbonImmutable $from, CarbonImmutable $toExclusive, bool $monthToDate): array
    {
        $fromKl = $from->setTimezone(MalaysiaTime::TIMEZONE);
        $toKl = $toExclusive->setTimezone(MalaysiaTime::TIMEZONE);
        $days = (int) $fromKl->diffInDays($toKl);

        if ($monthToDate) {
            $start = $fromKl->startOfMonth()->subMonthNoOverflow();
            $end = $start->addDays($days)->min($start->addMonthNoOverflow());
        } else {
            $start = $fromKl->subDays($days);
            $end = $fromKl;
        }

        $now = CarbonImmutable::now();
        if ($now->gt($from) && $now->lt($toExclusive)) {
            $end = $end->min($start->addSeconds((int) $from->diffInSeconds($now)));
        }

        return [$start->setTimezone('UTC'), $end->setTimezone('UTC')];
    }
}
