<?php

namespace Tests\Feature\Rebate;

use App\Models\AdminUser;
use App\Services\Rebate\RebateClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-139 decision 24 (PR 4b): Reports shows the reseller rebate as its own
 * line beside profit (summary, the reseller-wallet channel and the export).
 * Per-order profit stays as delivered. The rebate is reseller-level, so an
 * affiliate filter shows no rebate line.
 */
class RebateReportsTest extends TestCase
{
    use RebateFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        AdminUser::factory()->create(['email' => 'founder@example.com', 'is_active' => true]);
        Carbon::setTestNow(Carbon::parse('2026-12-15 02:00:00', 'UTC'));
        $this->seedRebateWorld();
        $this->delivered(160000, 4640);
        app(RebateClaimService::class)->claim($this->reseller, $this->user, 2026, 12, 1, acceptTerms: true);
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());
    }

    public function test_the_summary_and_the_wallet_channel_carry_the_rebate_line(): void
    {
        $range = '?from=2026-12-01&to=2026-12-31';

        $this->getJson("/api/reports/summary{$range}")->assertOk()->assertJsonPath('reseller_rebates', 600);
        $this->getJson("/api/reports/summary{$range}&affiliate_id={$this->primaryAffiliate()->id}")->assertOk()->assertJsonPath('reseller_rebates', null);
        $this->getJson('/api/reports/summary?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('reseller_rebates', 0);

        $channels = collect($this->getJson("/api/reports/breakdown/channels{$range}")->assertOk()->json('channels'))->keyBy('channel');
        $this->assertSame(600, $channels['reseller_wallet']['reseller_rebates']);
        $this->assertNull($channels['own_brand']['reseller_rebates']);
    }

    public function test_the_csv_export_ends_with_the_rebate_line(): void
    {
        $csv = $this->get('/api/reports/export?from=2026-12-01&to=2026-12-31')->assertOk()->streamedContent();

        $last = collect(explode("\n", trim($csv)))->last();
        $this->assertSame('"Reseller rebates (month-level revenue reduction; not in the rows above)",,,,,,,,,,,,,-6.00,', $last);
    }
}
