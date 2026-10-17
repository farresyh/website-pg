<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Affiliate;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\MembershipQuotaDebit;
use App\Models\Order;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Services\Membership\MembershipSessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\CheckoutControllerFixtures;
use Tests\TestCase;

/** This file: member pricing and quota at checkout. */
class CheckoutControllerMembershipTest extends TestCase
{
    use CheckoutControllerFixtures;
    use RefreshDatabase;

    /**
     * ADR-061 decision 5: a session token minted on another brand's
     * storefront never applies member pricing on this one — the checkout
     * proceeds as a plain guest.
     */
    public function test_a_session_token_from_another_brand_does_not_apply_member_pricing(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 2000,
            'expires_at' => now()->addDays(20),
        ]);
        $foreignToken = app(MembershipSessionTokenService::class)->issue(999, 'member@example.com');

        $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'member@example.com']),
            ['Authorization' => "Bearer {$foreignToken}"],
        )->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertNull($order->membership_id);
    }

    public function test_a_member_with_sufficient_quota_gets_the_member_price_and_decrements_quota(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 2000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('member@example.com');

        $response = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'member@example.com']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('member', $order->pricing_basis->value);
        $this->assertSame($membership->id, $order->membership_id);
        $this->assertSame('80.00', $order->member_discount_percent);
        $this->assertSame(1200, $order->normal_selling_price);
        $this->assertSame(1040, $order->selling_price);
        $this->assertSame(40, $order->platform_profit); // 1040 - 1000
        $this->assertSame(0, $order->affiliate_profit);

        $this->assertSame(960, $membership->fresh()->quota_remaining_sen); // 2000 - 1040
        $debit = MembershipQuotaDebit::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(1040, $debit->amount_sen);
    }

    /**
     * ADR-068 decision 16: a signed-in member's order is always
     * attributed to their OTP-verified membership email, even when a
     * different address is typed into the checkout contact field — so
     * the order can never go missing from their /membership history.
     */
    public function test_a_member_order_is_stored_under_the_membership_email_not_the_typed_one(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'real-member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 5000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('real-member@example.com');

        $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'typo@example.com', 'customer_name' => 'Real Member']),
            ['Authorization' => "Bearer {$token}"],
        )->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('real-member@example.com', $order->customer_email);
        $this->assertSame('Real Member', $order->customer_name); // name is left as typed
    }

    /** ADR-068 decision 16: the bind is keyed on the session, not on member pricing — a quota-exhausted member still gets it. */
    public function test_an_out_of_quota_member_order_is_still_stored_under_the_membership_email(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'broke-member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 1, // nowhere near the member price — standard fallback
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('broke-member@example.com');

        $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'typo@example.com']),
            ['Authorization' => "Bearer {$token}"],
        )->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertSame('broke-member@example.com', $order->customer_email);
    }

    /**
     * ADR-027's 2026-08-29 continued addendum, decision 4: a member
     * order's `affiliate_profit` is always 0 — the platform absorbs the
     * entire member discount itself, never the affiliate's own margin —
     * regardless of what `Affiliate.markup_pct` is actually configured
     * to. Proven here against a genuinely nonzero markup (10%), with a
     * standard order under the identical markup as the contrasting
     * control case: same affiliate, same package, same 10% — member
     * gets 0, standard gets a real cut.
     */
    public function test_affiliate_profit_is_always_zero_for_a_member_order_even_when_affiliate_markup_is_nonzero(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $this->primaryAffiliate()->update(['markup_pct' => 10]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'markup-member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 2000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('markup-member@example.com');

        $memberResponse = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'markup-member@example.com']),
            ['Authorization' => "Bearer {$token}"],
        );
        $memberResponse->assertCreated();
        $memberOrder = Order::query()->where('customer_email', 'markup-member@example.com')->firstOrFail();

        $this->assertSame('member', $memberOrder->pricing_basis->value);
        $this->assertSame('10.00', $memberOrder->affiliate_markup_pct); // snapshotted, but unused for profit
        $this->assertSame(1040, $memberOrder->selling_price); // unaffected by affiliate markup
        $this->assertSame(0, $memberOrder->affiliate_profit);

        // Control case: same affiliate markup, no membership token — affiliate must earn a real cut.
        $standardResponse = $this->postJson('/api/checkout', $this->payload($game, $package, [
            'customer_email' => 'no-member@example.com',
            'idempotency_key' => (string) Str::uuid(),
        ]));
        $standardResponse->assertCreated();
        $standardOrder = Order::query()->where('customer_email', 'no-member@example.com')->firstOrFail();

        $this->assertSame('standard', $standardOrder->pricing_basis->value);
        $this->assertSame(1320, $standardOrder->selling_price); // 1200 + 10% affiliate markup
        $this->assertSame(120, $standardOrder->affiliate_profit); // round(1200 * 10%)
    }

    /**
     * ADR-027 Phase 6's confirmed fallback: insufficient quota never
     * blocks checkout — this order simply charges the standard price,
     * exactly as if no membership token had been sent, and the
     * member's quota is left untouched for their other orders this
     * cycle.
     */
    public function test_a_member_with_insufficient_quota_falls_back_to_the_standard_price(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'poor-member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 500, // < the 1040 member price
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('poor-member@example.com');

        $response = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'poor-member@example.com']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertNull($order->membership_id);
        $this->assertNull($order->member_discount_percent);
        $this->assertNull($order->normal_selling_price);
        $this->assertSame(1200, $order->selling_price);
        $this->assertSame(200, $order->platform_profit); // 1200 - 1000

        $this->assertSame(500, $membership->fresh()->quota_remaining_sen); // untouched
        $this->assertSame(0, MembershipQuotaDebit::query()->count());
    }

    /**
     * No membership feature is a *gate* on checkout (base ADR decision
     * 10) — a garbage/expired token must degrade to exactly the same
     * standard-price behavior as sending no token at all, never a
     * validation error.
     */
    /**
     * ADR-027 decision 20: the kill switch (seeded off pre-launch)
     * gates checkout-time member pricing exactly like it already gates
     * CatalogController's member_price_sen — a still-valid session
     * token from earlier testing must never silently apply member
     * pricing while the feature is meant to be fully invisible.
     */
    public function test_a_member_checks_out_at_the_standard_price_when_the_membership_feature_is_disabled(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();
        PlatformSettings::current()->update(['membership_enabled' => false]);
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 2000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->membershipToken('member@example.com');

        $response = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package, ['customer_email' => 'member@example.com']),
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertNull($order->membership_id);
        $this->assertSame(1200, $order->selling_price);
    }

    public function test_an_invalid_membership_token_checks_out_at_the_standard_price_like_a_guest(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage();

        $response = $this->postJson(
            '/api/checkout',
            $this->payload($game, $package),
            ['Authorization' => 'Bearer garbage'],
        );

        $response->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertNull($order->membership_id);
        $this->assertSame(1200, $order->selling_price);
    }
}
