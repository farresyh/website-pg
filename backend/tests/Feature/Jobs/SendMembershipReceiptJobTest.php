<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendMembershipReceiptJob;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendMembershipReceiptJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_receipt_shows_the_malaysia_end_date_and_links_to_the_brand_membership_page(): void
    {
        config(['services.storefront.url' => 'https://pekangame.com']);
        Http::fake(['*/v1/send' => Http::response(['success' => true], 200)]);
        $plan = MembershipPlan::query()->where('name', 'Tier 1')->firstOrFail();

        // 17:00 UTC on the 23rd is 01:00 on the 24th in Malaysia (ADR-068 2026-10-13 addendum, decision 12).
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => $plan->quota_sen,
            'expires_at' => Carbon::parse('2026-10-23 17:00:00', 'UTC'),
        ]);

        SendMembershipReceiptJob::dispatchSync($membership->id, 'renewal', 1990);

        Http::assertSent(fn ($request) => str_contains($request['body'], 'Sat, Oct 24, 2026')
            && str_contains($request['body'], 'https://pekangame.com/membership'));
    }
}
