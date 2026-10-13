<?php

namespace Tests\Feature\Console;

use App\Jobs\SendMembershipReminderJob;
use App\Models\Affiliate;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipReminder;
use App\Models\PlatformSettings;
use App\Services\Membership\PlunkSendException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-068 2026-10-13 addendum, decisions 1-11: an email before (H-3) and
 * after (H+1) each paid-through period, counted in Malaysia dates.
 */
class SendMembershipRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $plunkStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.storefront.url' => 'https://pekangame.com']);
        PlatformSettings::current()->update(['membership_enabled' => true]);
        Http::fake(['*/v1/send' => fn () => Http::response(['success' => $this->plunkStatus === 200], $this->plunkStatus)]);
    }

    /** "Now" as a Malaysia wall-clock time. */
    private function travelToMyt(string $myt): void
    {
        $this->travelTo(Carbon::parse($myt, 'Asia/Kuala_Lumpur'));
    }

    private function member(string $expiresAtUtc, ?Affiliate $brand = null, string $email = 'member@example.com'): Membership
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();

        return Membership::query()->create([
            'affiliate_id' => ($brand ?? $this->primaryAffiliate())->id,
            'email' => $email,
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => $plan->quota_sen,
            'expires_at' => Carbon::parse($expiresAtUtc, 'UTC'),
        ]);
    }

    private function sentBodies(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]['subject'].' | '.$pair[0]['body'])->all();
    }

    public function test_the_before_reminder_goes_out_three_malaysia_days_ahead_not_three_utc_days(): void
    {
        // Ends 01:00 on 24 Oct in Malaysia (17:00 UTC on the 23rd). H-3 is 21 Oct, not 20 Oct.
        $membership = $this->member('2026-10-23 17:00:00');

        $this->travelToMyt('2026-10-20 10:00');
        $this->artisan('app:send-membership-reminders')->assertSuccessful();
        Http::assertNothingSent();

        $this->travelToMyt('2026-10-21 10:00');
        $this->artisan('app:send-membership-reminders')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['to'] === 'member@example.com'
            && $request['subject'] === 'Your Tier 1 membership ends on Sat, Oct 24, 2026'
            && str_contains($request['body'], 'https://pekangame.com/membership')
            && str_contains($request['body'], 'RM'.number_format($membership->membershipPlan->fee_sen / 100, 2)));

        $reminder = MembershipReminder::query()->sole();
        $this->assertSame(MembershipReminder::KIND_BEFORE, $reminder->kind);
        $this->assertSame(MembershipReminder::STATUS_SENT, $reminder->status);
        $this->assertNotNull($reminder->sent_at);
    }

    public function test_a_missed_day_is_caught_the_next_day(): void
    {
        $this->member('2026-10-23 17:00:00');

        $this->travelToMyt('2026-10-23 10:00');
        $this->artisan('app:send-membership-reminders')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_running_twice_sends_once(): void
    {
        $this->member('2026-10-23 17:00:00');
        $this->travelToMyt('2026-10-21 10:00');

        $this->artisan('app:send-membership-reminders')->assertSuccessful();
        $this->artisan('app:send-membership-reminders')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, MembershipReminder::query()->count());
    }

    public function test_the_after_reminder_goes_out_from_the_day_after_and_stops_after_seven_days(): void
    {
        $this->member('2026-10-23 17:00:00', email: 'late@example.com');

        $this->travelToMyt('2026-10-24 10:00');
        $this->artisan('app:send-membership-reminders')->assertSuccessful();
        $this->assertSame(0, MembershipReminder::query()->where('kind', MembershipReminder::KIND_AFTER)->count());

        $this->travelToMyt('2026-10-25 10:00');
        $this->artisan('app:send-membership-reminders')->assertSuccessful();

        Http::assertSent(fn ($request) => $request['subject'] === 'Your Tier 1 membership has ended');
        $this->assertSame(1, MembershipReminder::query()->where('kind', MembershipReminder::KIND_AFTER)->count());
    }

    public function test_a_member_who_lapsed_more_than_seven_days_ago_gets_nothing(): void
    {
        $this->member('2026-10-01 17:00:00');

        $this->travelToMyt('2026-10-13 10:00');
        $this->artisan('app:send-membership-reminders')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, MembershipReminder::query()->count());
    }

    public function test_renewing_starts_a_new_period_and_cancels_the_old_after_reminder(): void
    {
        $membership = $this->member('2026-10-23 17:00:00');
        $this->travelToMyt('2026-10-21 10:00');
        $this->artisan('app:send-membership-reminders')->assertSuccessful();

        $membership->update(['expires_at' => $membership->expires_at->addDays(30)]);

        $this->travelToMyt('2026-10-25 10:00');
        $this->artisan('app:send-membership-reminders')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(0, MembershipReminder::query()->where('kind', MembershipReminder::KIND_AFTER)->count());
    }

    public function test_members_of_a_brand_that_cannot_take_a_renewal_are_not_reminded(): void
    {
        $off = Affiliate::query()->create(['business_name' => 'Off', 'markup_pct' => 0, 'status' => 'active', 'is_owned' => true, 'membership_enabled' => false]);
        $suspended = Affiliate::query()->create(['business_name' => 'Suspended', 'markup_pct' => 0, 'status' => 'suspended', 'is_owned' => true, 'membership_enabled' => true]);
        $deleted = Affiliate::query()->create(['business_name' => 'Gone', 'markup_pct' => 0, 'status' => 'active', 'is_owned' => true, 'membership_enabled' => true]);
        $this->member('2026-10-23 17:00:00', $off);
        $this->member('2026-10-23 17:00:00', $suspended);
        $this->member('2026-10-23 17:00:00', $deleted);
        $deleted->delete();

        $this->travelToMyt('2026-10-21 10:00');
        $this->artisan('app:send-membership-reminders')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_global_membership_switch_stops_every_reminder(): void
    {
        PlatformSettings::current()->update(['membership_enabled' => false]);
        $this->member('2026-10-23 17:00:00');

        $this->travelToMyt('2026-10-21 10:00');
        $this->artisan('app:send-membership-reminders')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_dry_run_lists_without_sending_or_recording(): void
    {
        $this->member('2026-10-23 17:00:00');
        $this->travelToMyt('2026-10-21 10:00');

        $this->artisan('app:send-membership-reminders', ['--dry-run' => true])
            ->expectsOutputToContain('member@example.com')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, MembershipReminder::query()->count());
    }

    public function test_a_queued_reminder_is_skipped_if_the_member_renewed_before_it_was_sent(): void
    {
        $membership = $this->member('2026-10-23 17:00:00');
        $this->travelToMyt('2026-10-21 10:00');
        $reminder = MembershipReminder::query()->create([
            'membership_id' => $membership->id,
            'kind' => MembershipReminder::KIND_BEFORE,
            'expires_at' => $membership->expires_at,
            'status' => MembershipReminder::STATUS_QUEUED,
        ]);
        $membership->update(['expires_at' => $membership->expires_at->addDays(30)]);

        SendMembershipReminderJob::dispatchSync($reminder->id);

        Http::assertNothingSent();
        $this->assertSame(MembershipReminder::STATUS_SKIPPED, $reminder->fresh()->status);
    }

    public function test_a_plunk_failure_is_retried_then_recorded_as_failed(): void
    {
        $this->plunkStatus = 500;
        $membership = $this->member('2026-10-23 17:00:00');
        $this->travelToMyt('2026-10-21 10:00');
        $reminder = MembershipReminder::query()->create([
            'membership_id' => $membership->id,
            'kind' => MembershipReminder::KIND_BEFORE,
            'expires_at' => $membership->expires_at,
            'status' => MembershipReminder::STATUS_QUEUED,
        ]);
        $job = new SendMembershipReminderJob($reminder->id);

        try {
            app()->call([$job, 'handle']);
            $this->fail('expected the send to throw so the queue retries it');
        } catch (PlunkSendException) {
        }
        $this->assertSame(MembershipReminder::STATUS_QUEUED, $reminder->fresh()->status);
        $this->assertSame(1, $reminder->fresh()->attempts);

        $job->failed(new PlunkSendException('down'));

        $this->assertSame(MembershipReminder::STATUS_FAILED, $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->error);
    }
}
