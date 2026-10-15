<?php

namespace Tests\Unit\Support;

use App\Support\PeriodComparison;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** ADR-104 2026-10-08 addendum R12–R14. */
class PeriodComparisonTest extends TestCase
{
    /** KL calendar dates (inclusive) → the [from, toExclusive) UTC pair ReportService uses. */
    private function range(string $from, string $to): array
    {
        return [
            CarbonImmutable::parse($from, 'Asia/Kuala_Lumpur')->setTimezone('UTC'),
            CarbonImmutable::parse($to, 'Asia/Kuala_Lumpur')->addDay()->setTimezone('UTC'),
        ];
    }

    private function klDates(array $range): array
    {
        return [
            $range[0]->setTimezone('Asia/Kuala_Lumpur')->toDateString(),
            $range[1]->setTimezone('Asia/Kuala_Lumpur')->subDay()->toDateString(),
        ];
    }

    public function test_last_n_days_compares_with_the_n_days_immediately_before(): void
    {
        $previous = PeriodComparison::previousRange(...[...$this->range('2026-10-02', '2026-10-08'), false]);

        $this->assertSame(['2026-09-25', '2026-10-01'], $this->klDates($previous));
    }

    public function test_month_to_date_compares_with_the_same_days_of_last_month(): void
    {
        $previous = PeriodComparison::previousRange(...[...$this->range('2026-10-01', '2026-10-08'), true]);

        $this->assertSame(['2026-09-01', '2026-09-08'], $this->klDates($previous));
    }

    public function test_month_to_date_clamps_to_the_end_of_a_shorter_previous_month(): void
    {
        $previous = PeriodComparison::previousRange(...[...$this->range('2026-03-01', '2026-03-30'), true]);

        $this->assertSame(['2026-02-01', '2026-02-28'], $this->klDates($previous));
    }

    /**
     * ADR-104 2026-10-15 grill Q3: a range that ends after now is compared
     * with the previous range up to the same elapsed time, so a morning
     * never looks like a bad day.
     */
    public function test_a_range_still_running_is_compared_up_to_the_same_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 10:30', 'Asia/Kuala_Lumpur'));

        [$start, $end] = PeriodComparison::previousRange(...[...$this->range('2026-10-15', '2026-10-15'), false]);

        $this->assertSame('2026-10-14 00:00', $start->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-14 10:30', $end->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
    }

    public function test_last_7_days_still_running_ends_the_previous_week_at_the_same_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 18:40', 'Asia/Kuala_Lumpur'));

        [$start, $end] = PeriodComparison::previousRange(...[...$this->range('2026-10-09', '2026-10-15'), false]);

        $this->assertSame('2026-10-02 00:00', $start->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-08 18:40', $end->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
    }

    public function test_month_to_date_still_running_ends_last_month_at_the_same_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 09:00', 'Asia/Kuala_Lumpur'));

        [$start, $end] = PeriodComparison::previousRange(...[...$this->range('2026-10-01', '2026-10-08'), true]);

        $this->assertSame('2026-09-01 00:00', $start->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
        $this->assertSame('2026-09-08 09:00', $end->setTimezone('Asia/Kuala_Lumpur')->format('Y-m-d H:i'));
    }

    public function test_a_finished_range_is_not_clipped(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 10:30', 'Asia/Kuala_Lumpur'));

        $previous = PeriodComparison::previousRange(...[...$this->range('2026-10-02', '2026-10-08'), false]);

        $this->assertSame(['2026-09-25', '2026-10-01'], $this->klDates($previous));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_change_is_a_percentage_with_a_direction(): void
    {
        $this->assertSame(['pct' => 50.0, 'direction' => 'up'], PeriodComparison::change(150, 100));
        $this->assertSame(['pct' => 25.0, 'direction' => 'down'], PeriodComparison::change(75, 100));
    }

    /** R13 — an empty previous period is "No data", never +∞%. */
    public function test_change_from_nothing_has_no_percentage(): void
    {
        $this->assertSame(['pct' => null, 'direction' => 'new'], PeriodComparison::change(100, 0));
        $this->assertSame(['pct' => null, 'direction' => 'flat'], PeriodComparison::change(0, 0));
    }

    /** R13 — margin moves in percentage points. */
    public function test_points_is_the_difference_in_percentage_points(): void
    {
        $this->assertSame(['points' => 2.5, 'direction' => 'up'], PeriodComparison::points(12.5, 10.0, true));
        $this->assertSame(['points' => 1.25, 'direction' => 'down'], PeriodComparison::points(8.75, 10.0, true));
        $this->assertSame(['points' => null, 'direction' => 'new'], PeriodComparison::points(12.5, 0.0, false));
    }
}
