<?php

namespace Tests\Feature\Rebate;

use App\Models\AdminUser;
use App\Models\CustomerNotification;
use App\Models\ResellerRebateEligibilityChange;
use App\Models\ResellerRebateLadder;
use App\Models\ResellerRebateMilestone;
use App\Models\ResellerTier;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Rebate\RebateClaimService;
use App\Services\Reseller\ResellerClosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** ADR-139 PR 4a: the admin and portal endpoints. */
class RebateApiTest extends TestCase
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
    }

    private function admin(): AdminUser
    {
        return $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());
    }

    // --- Admin ---

    public function test_the_ladder_screen_lists_versions_and_warns_about_a_tier_with_no_column(): void
    {
        $orphan = ResellerTier::query()->create(['name' => 'Tier A', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 5]);
        $this->admin();

        $this->getJson('/api/rebate/ladders')
            ->assertOk()
            ->assertJsonPath('ladders.0.status', 'in_force')
            ->assertJsonPath('next_effective_from', '2027-01-01')
            ->assertJsonPath('hard_ceiling_bp', 5000)
            ->assertJsonPath('tiers_without_column', [$orphan->id]);
    }

    public function test_saving_a_ladder_notifies_each_eligible_resellers_group_once(): void
    {
        $other = $this->rebateReseller('Ineligible Co', $this->ss, 'ineligible@g.us');
        $other->update(['rebate_eligible' => false]);
        $this->admin();
        $steps = [['threshold_sen' => 50000, 'rewards' => [$this->ss->id => 100]]];

        $this->postJson('/api/rebate/ladders', ['steps' => $steps, 'max_share_bp' => 3500])
            ->assertCreated()
            ->assertJsonPath('effective_from', '2027-01-01');

        $notices = CustomerNotification::query()->where('event', 'rebate_ladder_notice')->get();
        $this->assertSame(['acme@g.us'], $notices->pluck('chat_id')->all());
        $this->assertStringContainsString('1 Januari 2027', $notices->first()->message);
        $this->assertStringNotContainsString('%', $notices->first()->message);
    }

    public function test_a_ladder_with_a_bad_shape_is_refused_at_the_boundary(): void
    {
        $this->admin();

        $this->postJson('/api/rebate/ladders', ['steps' => [['threshold_sen' => 'x']], 'max_share_bp' => 3500])->assertUnprocessable();
        $this->postJson('/api/rebate/ladders', ['steps' => [], 'max_share_bp' => 9000])->assertUnprocessable();
        $this->assertSame(1, ResellerRebateLadder::query()->count());
    }

    public function test_the_month_view_shows_each_resellers_standing_and_rows(): void
    {
        $this->delivered(160000, 4640);
        $this->claim(1);
        $this->admin();

        $this->getJson('/api/rebate/months/2026/12')
            ->assertOk()
            ->assertJsonPath('finalised', false)
            ->assertJsonPath('resellers.0.business_name', 'Acme')
            ->assertJsonPath('resellers.0.reached_step', 2)
            ->assertJsonPath('resellers.0.entitlement_sen', 600)
            ->assertJsonPath('resellers.0.claimed_sen', 100)
            ->assertJsonPath('resellers.0.available_sen', 500)
            ->assertJsonPath('rows.0.step', 1)
            ->assertJsonPath('rows.0.cap_sen', 1624);
    }

    public function test_the_month_export_lists_rows_claims_expiries_and_forfeits(): void
    {
        app(LedgerService::class)->credit(LedgerOwnerType::ResellerWallet, $this->reseller->id, 5000, 'wallet_topup');
        $this->delivered(160000, 4640);
        $this->claim(1);
        app(ResellerClosureService::class)->close($this->reseller, AdminUser::query()->first()->id, '2026-12-15', 'BANK-1');
        $this->admin();

        $csv = $this->get('/api/rebate/months/2026/12/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('Record,Reseller,Period', $csv);
        $this->assertStringContainsString('reward,Acme,2026-12,step,1,1.00,claimed', $csv);
        $this->assertStringContainsString('forfeit,Acme,,,,1.00', $csv);
    }

    public function test_eligibility_changes_need_a_reason_and_are_recorded(): void
    {
        $admin = $this->admin();

        $this->patchJson("/api/resellers/{$this->reseller->id}/rebate-eligibility", ['eligible' => false])->assertUnprocessable();
        $this->patchJson("/api/resellers/{$this->reseller->id}/rebate-eligibility", ['eligible' => false, 'reason' => 'Internal test account'])
            ->assertOk()
            ->assertJsonPath('rebate_eligible', false);
        $this->patchJson("/api/resellers/{$this->reseller->id}/rebate-eligibility", ['eligible' => false, 'reason' => 'Again'])->assertUnprocessable();

        $change = ResellerRebateEligibilityChange::query()->sole();
        $this->assertSame([true, false, $admin->id, 'Internal test account'], [$change->from_eligible, $change->to_eligible, $change->admin_user_id, $change->reason]);
    }

    // --- Portal ---

    public function test_the_portal_shows_its_own_tier_ladder_and_progress_and_never_the_platform_share(): void
    {
        $other = ResellerTier::query()->create(['name' => 'Tier A', 'markup_percent' => 5, 'is_active' => true, 'sort_order' => 5]);
        ResellerRebateLadder::query()->create(['effective_from' => '2027-01-01', 'max_share_bp' => 3500, 'steps' => [
            ['threshold_sen' => 50000, 'rewards' => [(string) $this->ss->id => 200, (string) $other->id => 900]],
        ]]);
        $this->delivered(60000, 1740);

        $response = $this->withToken($this->portalToken())->getJson('/api/reseller-portal/rebate')
            ->assertOk()
            ->assertJsonPath('tier_name', 'Tier SS')
            ->assertJsonPath('terms.accepted', false)
            ->assertJsonPath('months.0.period', '2026-12')
            ->assertJsonPath('months.0.sales_sen', 60000)
            ->assertJsonPath('months.0.steps.0.state', 'claimable')
            ->assertJsonPath('months.0.steps.0.reward_sen', 100)
            ->assertJsonPath('months.0.steps.1.state', 'locked')
            ->assertJsonPath('months.0.steps.1.reward_sen', 500)
            ->assertJsonPath('months.0.remaining_sen', 90000)
            ->assertJsonPath('upcoming_ladder.effective_from', '2027-01-01')
            ->assertJsonPath('upcoming_ladder.steps.0.reward_sen', 200)
            ->assertJsonMissingPath('upcoming_ladder.steps.0.rewards');

        $body = $response->getContent();
        foreach (['max_share', 'profit', 'cap_sen', 'markup'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
    }

    public function test_a_portal_claim_needs_the_terms_then_credits_the_balance_and_labels_the_statement(): void
    {
        $this->delivered(60000, 1740);
        $token = $this->portalToken();

        $this->withToken($token)->postJson('/api/reseller-portal/rebate/claim', ['year' => 2026, 'month' => 12, 'step' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('terms');

        $this->withToken($token)->postJson('/api/reseller-portal/rebate/claim', ['year' => 2026, 'month' => 12, 'step' => 1, 'accept_terms' => true])
            ->assertOk()
            ->assertJsonPath('amount_sen', 100)
            ->assertJsonPath('balance_sen', 100);

        $this->withToken($token)->getJson('/api/reseller-portal/wallet')
            ->assertOk()
            ->assertJsonPath('entries.data.0.type', 'wallet_rebate')
            ->assertJsonPath('entries.data.0.rebate.period', '2026-12')
            ->assertJsonPath('entries.data.0.rebate.threshold_sen', 50000);
        // PR 4c: the page's history names the step by its target, like the statement line.
        $this->withToken($token)->getJson('/api/reseller-portal/rebate')
            ->assertOk()
            ->assertJsonPath('history.0.state', ResellerRebateMilestone::CLAIMED)
            ->assertJsonPath('history.0.threshold_sen', 50000);
        $this->assertSame(ResellerRebateMilestone::CLAIMED, ResellerRebateMilestone::query()->sole()->state);
    }

    private function claim(int $step): void
    {
        app(RebateClaimService::class)->claim($this->reseller, $this->user, 2026, 12, $step, acceptTerms: true);
    }
}
