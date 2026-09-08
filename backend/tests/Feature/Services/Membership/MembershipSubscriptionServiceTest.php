<?php

namespace Tests\Feature\Services\Membership;

use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipPlan;
use App\Models\PlatformSettings;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Membership\MembershipSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ADR-080 decision 5: the subscribe -> brand-disabled -> webhook race.
 * `completePaidAttempt()` honours the payment (the member paid in good
 * faith while the brand was still enabled) and does NOT re-check
 * `membershipEnabledEffective()` — it only logs a warning so the rare
 * race is visible rather than silent. The membership is still created.
 */
class MembershipSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryAffiliate();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        // recordFeePaid() dispatches the receipt job.
        Queue::fake();
    }

    private function service(): MembershipSubscriptionService
    {
        return app(MembershipSubscriptionService::class);
    }

    private function pendingAttempt(): MembershipCheckoutAttempt
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();

        return MembershipCheckoutAttempt::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'racer@example.com',
            'membership_plan_id' => $plan->id,
            'fee_sen' => $plan->fee_sen,
            'total_charged_sen' => $plan->fee_sen + 100,
            'channel_code' => 'fpx',
            'subscription_number' => 'MS-RACE0001',
            'idempotency_key' => 'idem-race',
            'status' => MembershipCheckoutAttemptStatus::Pending->value,
        ]);
    }

    public function test_completing_an_attempt_on_a_still_enabled_brand_logs_no_warning(): void
    {
        Log::spy();
        $attempt = $this->pendingAttempt();

        $this->service()->completePaidAttempt($attempt);

        $this->assertDatabaseHas('memberships', ['email' => 'racer@example.com', 'status' => 'active']);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_completing_an_attempt_on_a_now_disabled_brand_still_creates_the_membership_but_logs_a_warning(): void
    {
        Log::spy();
        $attempt = $this->pendingAttempt();

        // The brand is turned off in the seconds between checkout and the
        // CHIP webhook landing.
        $this->primaryAffiliate()->update(['membership_enabled' => false]);

        $this->service()->completePaidAttempt($attempt);

        // Decision 5: the payment is honoured — the membership is created.
        $this->assertDatabaseHas('memberships', ['email' => 'racer@example.com', 'status' => 'active']);
        $this->assertSame(
            MembershipCheckoutAttemptStatus::Paid,
            $attempt->fresh()->status,
        );

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => str_contains($message, 'no longer enabled')
                && $context['subscription_number'] === 'MS-RACE0001',
        );
    }
}
