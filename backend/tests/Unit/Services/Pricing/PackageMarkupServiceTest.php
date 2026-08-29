<?php

namespace Tests\Unit\Services\Pricing;

use App\Services\Pricing\PackageMarkupService;
use PHPUnit\Framework\TestCase;

class PackageMarkupServiceTest extends TestCase
{
    /**
     * Matches real values observed on the legacy reference system
     * (Mobile Legends Malaysia, 15% markup) — confirms our rounding
     * convention agrees with theirs, not just internally consistent.
     */
    public function test_calculate_matches_legacy_observed_values(): void
    {
        $service = new PackageMarkupService();

        $this->assertSame(94, $service->calculateStandardSellingPrice(82, 15.0)); // RM 0.82 -> RM 0.94
        $this->assertSame(108, $service->calculateStandardSellingPrice(94, 15.0)); // RM 0.94 -> RM 1.08
        $this->assertSame(109, $service->calculateStandardSellingPrice(95, 15.0)); // RM 0.95 -> RM 1.09
    }

    public function test_calculate_with_zero_markup_returns_the_cost_price_unchanged(): void
    {
        $service = new PackageMarkupService();

        $this->assertSame(421, $service->calculateStandardSellingPrice(421, 0.0));
    }
}
