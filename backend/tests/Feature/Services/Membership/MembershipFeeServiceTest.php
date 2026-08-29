<?php

namespace Tests\Feature\Services\Membership;

use App\Models\AdminUser;
use App\Models\LedgerEntry;
use App\Models\Membership;
use App\Models\MembershipFeeRecord;
use App\Models\MembershipPlan;
use App\Services\Membership\MembershipFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-027 continued addendum decision 15 / Phase 6.5 (grilled
 * 2026-08-29). Covers the full fee-record lifecycle: create, active
 * extend (quota untouched, Q2), lapsed reactivation (Q8), plan change
 * (Q5), idempotency (Q11), and the zero-amount waiver (Q13).
 */
class MembershipFeeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $name = 'Tier 1'): MembershipPlan
    {
        return MembershipPlan::query()->where('name', $name)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        return AdminUser::factory()->create(['role' => 'super_admin']);
    }

    private function service(): MembershipFeeService
    {
        return app(MembershipFeeService::class);
    }

    public function test_creates_a_new_membership_with_full_quota_and_books_a_ledger_credit(): void
    {
        $plan = $this->plan('Tier 1');
        $admin = $this->admin();

        $membership = $this->service()->recordFeePaid(
            'new@example.com',
            $plan->id,
            $plan->fee_sen,
            $admin->id,
            null,
            'fee-key-1',
        );

        $this->assertSame('new@example.com', $membership->email);
        $this->assertSame($plan->id, $membership->membership_plan_id);
        $this->assertSame('active', $membership->status->value);
        $this->assertSame($plan->quota_sen, $membership->quota_remaining_sen);
        $this->assertNotNull($membership->expires_at);

        $this->assertDatabaseHas('ledger_entries', [
            'type' => 'membership_fee',
            'amount' => $plan->fee_sen,
            'owner_type' => 'platform',
            'reference_type' => 'membership',
            'reference_id' => $membership->id,
            'created_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('membership_fee_records', [
            'membership_id' => $membership->id,
            'membership_plan_id' => $plan->id,
            'amount_sen' => $plan->fee_sen,
            'idempotency_key' => 'fee-key-1',
        ]);
    }

    public function test_active_mid_cycle_renewal_extends_expiry_without_touching_quota_or_cycle(): void
    {
        $plan = $this->plan('Tier 1');
        $admin = $this->admin();

        $membership = Membership::query()->create([
            'email' => 'active@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now()->subDays(10),
            'quota_remaining_sen' => 2500,
            'expires_at' => now()->addDays(20),
        ]);
        $originalCycle = $membership->cycle_started_at;

        $result = $this->service()->recordFeePaid(
            'active@example.com',
            $plan->id,
            $plan->fee_sen,
            $admin->id,
            null,
            'fee-key-2',
        );

        $this->assertSame($membership->id, $result->id);
        $this->assertSame(2500, $result->fresh()->quota_remaining_sen); // Q2: quota untouched
        $this->assertTrue($result->fresh()->cycle_started_at->equalTo($originalCycle));
        $this->assertTrue($result->fresh()->expires_at->gt(now()->addDays(49))); // 20 + 30
    }

    public function test_lapsed_membership_reactivates_with_a_fresh_cycle_and_full_quota(): void
    {
        $plan = $this->plan('Tier 2');
        $admin = $this->admin();

        $membership = Membership::query()->create([
            'email' => 'lapsed@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now()->subDays(40),
            'quota_remaining_sen' => 100,
            'expires_at' => now()->subDays(5),
        ]);

        $result = $this->service()->recordFeePaid(
            'lapsed@example.com',
            $plan->id,
            $plan->fee_sen,
            $admin->id,
            null,
            'fee-key-3',
        );

        $this->assertSame($membership->id, $result->id);
        $this->assertSame('active', $result->status->value);
        $this->assertSame($plan->quota_sen, $result->quota_remaining_sen);
        $this->assertTrue($result->expires_at->gt(now()->addDays(29)));
    }

    public function test_plan_change_on_renewal_switches_the_membership_plan(): void
    {
        $tier1 = $this->plan('Tier 1');
        $tier2 = $this->plan('Tier 2');
        $admin = $this->admin();

        $membership = Membership::query()->create([
            'email' => 'upgrade@example.com',
            'membership_plan_id' => $tier1->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => $tier1->quota_sen,
            'expires_at' => now()->addDays(10),
        ]);

        $result = $this->service()->recordFeePaid(
            'upgrade@example.com',
            $tier2->id,
            $tier2->fee_sen,
            $admin->id,
            null,
            'fee-key-4',
        );

        $this->assertSame($tier2->id, $result->fresh()->membership_plan_id);
    }

    public function test_same_idempotency_key_books_exactly_one_fee_and_one_ledger_entry(): void
    {
        $plan = $this->plan('Tier 1');
        $admin = $this->admin();

        $first = $this->service()->recordFeePaid('dup@example.com', $plan->id, $plan->fee_sen, $admin->id, null, 'fee-key-5');
        $second = $this->service()->recordFeePaid('dup@example.com', $plan->id, $plan->fee_sen, $admin->id, null, 'fee-key-5');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MembershipFeeRecord::query()->where('idempotency_key', 'fee-key-5')->count());
        $this->assertSame(1, LedgerEntry::query()->where('type', 'membership_fee')->count());
    }

    public function test_zero_amount_waiver_still_activates_and_books_a_zero_ledger_entry(): void
    {
        $plan = $this->plan('Tier 1');
        $admin = $this->admin();

        $membership = $this->service()->recordFeePaid(
            'waiver@example.com',
            $plan->id,
            0,
            $admin->id,
            'founder waived',
            'fee-key-6',
        );

        $this->assertSame('active', $membership->status->value);
        $this->assertDatabaseHas('ledger_entries', [
            'type' => 'membership_fee',
            'amount' => 0,
            'reason' => 'founder waived',
        ]);
    }
}
