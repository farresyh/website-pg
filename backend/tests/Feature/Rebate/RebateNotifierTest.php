<?php

namespace Tests\Feature\Rebate;

use App\Models\AdminUser;
use App\Models\CustomerNotification;
use App\Services\Rebate\RebateClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** ADR-139 decisions 33, 40, 47: the step and reminder messages, sent by a scheduled task, never from a money path. */
class RebateNotifierTest extends TestCase
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

    /** @return list<string> */
    private function messages(string $event): array
    {
        return CustomerNotification::query()->where('event', $event)->orderBy('id')->pluck('message')->all();
    }

    public function test_each_reached_step_is_announced_once_with_a_portal_link(): void
    {
        $this->delivered(60000, 1740);
        $this->artisan('app:notify-rebate-progress')->assertSuccessful();
        $this->artisan('app:notify-rebate-progress')->assertSuccessful();

        $this->delivered(100000, 2900, '2026-12-14 04:00:00');
        $this->artisan('app:notify-rebate-progress')->assertSuccessful();

        $sent = $this->messages('rebate_step_reached');
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('RM500.00', $sent[0]);
        $this->assertStringContainsString('RM1.00', $sent[0]);
        $this->assertStringContainsString('http://localhost:3002/rebate', $sent[0]);
        $this->assertStringContainsString('RM1,500.00', $sent[1]);
        $this->assertStringContainsString('RM5.00', $sent[1]);
    }

    public function test_a_claimed_step_is_not_announced_and_ineligible_or_deactivated_accounts_hear_nothing(): void
    {
        $this->delivered(60000, 1740);
        app(RebateClaimService::class)->claim($this->reseller, $this->user, 2026, 12, 1, acceptTerms: true);
        $quiet = $this->rebateReseller('Quiet', $this->ss, 'quiet@g.us');
        $quiet->update(['rebate_eligible' => false]);
        $this->delivered(60000, 1740, reseller: $quiet);
        $off = $this->rebateReseller('Off', $this->ss, 'off@g.us');
        $off->update(['is_active' => false]);
        $this->delivered(60000, 1740, reseller: $off);

        $this->artisan('app:notify-rebate-progress')->assertSuccessful();

        $this->assertSame([], $this->messages('rebate_step_reached'));
    }

    public function test_an_unclaimed_reward_gets_one_reminder_three_days_before_it_lapses(): void
    {
        $this->delivered(60000, 1740);
        Carbon::setTestNow(Carbon::parse('2026-12-31 16:10:00', 'UTC'));
        $this->artisan('app:finalize-rebate-months')->assertSuccessful();
        CustomerNotification::query()->delete();

        Carbon::setTestNow(Carbon::parse('2027-01-27 16:00:00', 'UTC')); // 28 Jan 00:00 KL: 4 days left
        $this->artisan('app:notify-rebate-progress')->assertSuccessful();
        $this->assertSame([], $this->messages('rebate_expiry_reminder'));

        Carbon::setTestNow(Carbon::parse('2027-01-28 16:10:00', 'UTC')); // 29 Jan: under 3 days left
        $this->artisan('app:notify-rebate-progress')->assertSuccessful();
        $this->artisan('app:notify-rebate-progress')->assertSuccessful();

        $sent = $this->messages('rebate_expiry_reminder');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('RM1.00', $sent[0]);
        $this->assertStringContainsString('31 Januari 2027', $sent[0]);
    }
}
