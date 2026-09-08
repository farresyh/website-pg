<?php

namespace Tests\Feature\Http\Controllers\Affiliate;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Models\AffiliateUser;
use App\Models\Order;
use App\Services\Affiliate\AffiliateSubscriptionStatus;
use App\Services\Ledger\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-059 (59a): the affiliate-portal read layer. Every route runs under
 * `auth:affiliate` + `affiliate.context` — the tenant scope (ADR-057) is
 * derived from the token's `affiliate_user`, never a request parameter,
 * so an affiliate physically cannot read another affiliate's rows.
 */
class AffiliatePortalReadTest extends TestCase
{
    use RefreshDatabase;

    private function affiliate(string $name): Affiliate
    {
        return Affiliate::query()->create([
            'business_name' => $name,
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    private function tokenFor(Affiliate $affiliate): string
    {
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate',
            'owner_id' => $affiliate->id,
            'name' => 'Staff',
            'email' => 'staff+'.$affiliate->id.'@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return $user->createToken('affiliate')->plainTextToken;
    }

    public function test_every_portal_route_rejects_an_unauthenticated_request(): void
    {
        foreach (['/api/affiliate/dashboard', '/api/affiliate/orders', '/api/affiliate/earnings', '/api/affiliate/subscription'] as $route) {
            $this->getJson($route)->assertUnauthorized();
        }
    }

    public function test_an_admin_token_cannot_reach_the_portal(): void
    {
        $admin = AdminUser::factory()->create();
        $token = $admin->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/affiliate/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_returns_the_earnings_and_sales_snapshot(): void
    {
        $affiliate = $this->affiliate('Acme');
        app(LedgerService::class)->credit('affiliate', $affiliate->id, 4200, 'order_profit', 'order', 1);
        Order::factory()->forAffiliate($affiliate)->create(['final_amount' => 2500, 'paid_at' => now()]);

        $this->withToken($this->tokenFor($affiliate))->getJson('/api/affiliate/dashboard')
            ->assertOk()
            ->assertJsonPath('earnings_balance', 4200)
            ->assertJsonPath('this_month.orders', 1)
            ->assertJsonPath('this_month.sales', 2500)
            ->assertJsonPath('subscription', null);
    }

    public function test_orders_index_lists_only_the_callers_own_orders(): void
    {
        $mine = $this->affiliate('Mine');
        $other = $this->affiliate('Other');

        Order::factory()->forAffiliate($mine)->count(2)->create(['paid_at' => now()]);
        Order::factory()->forAffiliate($other)->count(3)->create(['paid_at' => now()]);

        $this->withToken($this->tokenFor($mine))->getJson('/api/affiliate/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_orders_show_404s_for_another_affiliates_order(): void
    {
        $mine = $this->affiliate('Mine');
        $other = $this->affiliate('Other');
        $foreign = Order::factory()->forAffiliate($other)->create(['paid_at' => now()]);

        $this->withToken($this->tokenFor($mine))
            ->getJson('/api/affiliate/orders/'.$foreign->order_number)
            ->assertNotFound();
    }

    public function test_order_detail_never_leaks_internal_financial_fields(): void
    {
        $affiliate = $this->affiliate('Acme');
        $order = Order::factory()->forAffiliate($affiliate)->create([
            'paid_at' => now(),
            'payment_ref' => 'xnd-secret-ref',
            'supplier_response' => ['raw' => 'secret'],
        ]);

        $response = $this->withToken($this->tokenFor($affiliate))
            ->getJson('/api/affiliate/orders/'.$order->order_number)
            ->assertOk();

        foreach (['cost_price', 'standard_selling_price', 'normal_selling_price', 'platform_profit', 'selling_price', 'supplier_response', 'supplier_ref', 'payment_ref', 'channel_code', 'checkout_idempotency_key'] as $forbidden) {
            $response->assertJsonMissingPath($forbidden);
        }

        // the affiliate's own margin IS shown
        $response->assertJsonPath('affiliate_profit', $order->affiliate_profit);
    }

    public function test_earnings_returns_balance_and_only_this_tenants_entries(): void
    {
        $mine = $this->affiliate('Mine');
        $other = $this->affiliate('Other');
        $ledger = app(LedgerService::class);
        $ledger->credit('affiliate', $mine->id, 6000, 'order_profit', 'order', 1);
        $ledger->credit('affiliate', $mine->id, -1000, 'withdrawal', 'withdrawal', 1);
        $ledger->credit('affiliate', $other->id, 8888, 'order_profit', 'order', 2);

        $this->withToken($this->tokenFor($mine))->getJson('/api/affiliate/earnings')
            ->assertOk()
            ->assertJsonPath('balance', 5000)
            ->assertJsonCount(2, 'entries.data');
    }

    public function test_subscription_returns_the_tier_snapshot_and_charge_history(): void
    {
        $affiliate = $this->affiliate('Acme');
        $tier = AffiliateMembershipTier::query()->create([
            'name' => 'Gold', 'monthly_fee_sen' => 9900, 'markup_percent' => 3, 'is_active' => true,
        ]);
        AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_membership_tier_id' => $tier->id,
            'status' => AffiliateSubscriptionStatus::Grace,
            'current_period_started_at' => now()->subDays(20),
            'next_charge_at' => now()->subDays(1),
            'grace_until' => now()->addDays(2),
        ]);
        app(LedgerService::class)->credit('affiliate', $affiliate->id, -9900, 'affiliate_tier_fee', 'affiliate_subscription', 1);

        $this->withToken($this->tokenFor($affiliate))->getJson('/api/affiliate/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.tier_name', 'Gold')
            ->assertJsonPath('subscription.status', 'grace')
            // The tier's markup_percent is the platform's wholesale
            // margin over cost — private, never exposed to the affiliate.
            ->assertJsonMissingPath('subscription.wholesale_markup_percent')
            ->assertJsonCount(1, 'charge_history');
    }

    public function test_subscription_is_null_when_the_affiliate_has_none(): void
    {
        $affiliate = $this->affiliate('Acme');

        $this->withToken($this->tokenFor($affiliate))->getJson('/api/affiliate/subscription')
            ->assertOk()
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('charge_history', []);
    }

    public function test_orders_index_respects_the_delivery_status_filter(): void
    {
        $affiliate = $this->affiliate('Acme');
        Order::factory()->forAffiliate($affiliate)->create(['paid_at' => now()]);
        Order::factory()->forAffiliate($affiliate)->delivered()->create(['paid_at' => now()]);

        $this->withToken($this->tokenFor($affiliate))
            ->getJson('/api/affiliate/orders?delivery_status=delivered')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
