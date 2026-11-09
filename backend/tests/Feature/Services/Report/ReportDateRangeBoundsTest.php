<?php

namespace Tests\Feature\Services\Report;

use App\Models\AdminUser;
use App\Services\Report\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 2026-11-10: `GET /api/reports/trend?from=0002-10-01` (a year typed half-way)
 * built one zero-filled row per day since year 2 and ran PHP out of memory
 * four times on production. Every admin date filter goes through
 * `dateRangeFromDates()`, so the bound lives there.
 */
class ReportDateRangeBoundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_year_outside_the_business_window_is_refused_not_rendered(): void
    {
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());

        $this->getJson('/api/reports/trend?from=0002-10-01')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/reports/summary?from=2026-10-01&to=3026-10-31')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/reports/trend?from=2026-13-45')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/reports/trend?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonCount(31, 'days');
    }

    public function test_the_shared_parser_bounds_every_caller(): void
    {
        $reports = new ReportService;

        $this->assertCount(2, $reports->dateRangeFromDates('2020-01-01', '2100-12-31'));
        foreach ([['2019-12-31', null], [null, '2101-01-01'], ['26-10-01', null]] as [$from, $to]) {
            try {
                $reports->dateRangeFromDates($from, $to);
                $this->fail("accepted {$from} / {$to}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
