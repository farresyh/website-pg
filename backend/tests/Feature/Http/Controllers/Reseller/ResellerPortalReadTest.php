<?php

namespace Tests\Feature\Http\Controllers\Reseller;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Reseller;
use App\Models\ResellerMembershipTier;
use App\Models\ResellerSubscription;
use App\Models\ResellerUser;
use App\Services\Ledger\LedgerService;
use App\Services\Reseller\ResellerSubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ADR-059 (59a): the reseller-portal read layer. Every route runs under
 * `auth:reseller` + `reseller.context` — the tenant scope (ADR-057) is
 * derived from the token's `reseller_user`, never a request parameter,
 * so a reseller physically cannot read another reseller's rows.
 */
class ResellerPortalReadTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(string $name): Reseller
    {
        return Reseller::query()->create([
            'business_name' => $name,
            'markup_pct' => 10,
            'max_markup_pct' => 30,
            'status' => 'active',
        ]);
    }

    private function tokenFor(Reseller $reseller): string
    {
        $user = ResellerUser::query()->create([
            'reseller_id' => $reseller->id,
            'name' => 'Staff',
            'email' => 'staff+'.$reseller->id.'@acme.test',
            'password' => Hash::make('secret-password'),
            'is_active' => true,
        ]);

        return $user->createToken('reseller')->plainTextToken;
    }

    public function test_every_portal_route_rejects_an_unauthenticated_request(): void
    {
        foreach (['/api/reseller/dashboard', '/api/reseller/orders', '/api/reseller/earnings', '/api/reseller/subscription'] as $route) {
            $this->getJson($route)->assertUnauthorized();
        }
    }

    public function test_an_admin_token_cannot_reach_the_portal(): void
    {
        $admin = AdminUser::factory()->create();
        $token = $admin->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/reseller/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_returns_the_earnings_and_sales_snapshot(): void
    {
        $reseller = $this->reseller('Acme');
        app(LedgerService::class)->credit('reseller', $reseller->id, 4200, 'order_profit', 'order', 1);
        Order::factory()->forReseller($reseller)->create(['final_amount' => 2500, 'paid_at' => now()]);

        $this->withToken($this->tokenFor($reseller))->getJson('/api/reseller/dashboard')
            ->assertOk()
            ->assertJsonPath('earnings_balance', 4200)
            ->assertJsonPath('this_month.orders', 1)
            ->assertJsonPath('this_month.sales', 2500)
            ->assertJsonPath('subscription', null);
    }

    public function test_orders_index_lists_only_the_callers_own_orders(): void
    {
        $mine = $this->reseller('Mine');
        $other = $this->reseller('Other');

        Order::factory()->forReseller($mine)->count(2)->create(['paid_at' => now()]);
        Order::factory()->forReseller($other)->count(3)->create(['paid_at' => now()]);

        $this->withToken($this->tokenFor($mine))->getJson('/api/reseller/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_orders_show_404s_for_another_resellers_order(): void
    {
        $mine = $this->reseller('Mine');
        $other = $this->reseller('Other');
        $foreign = Order::factory()->forReseller($other)->create(['paid_at' => now()]);

        $this->withToken($this->tokenFor($mine))
            ->getJson('/api/reseller/orders/'.$foreign->order_number)
            ->assertNotFound();
    }

    public function test_order_detail_never_leaks_internal_financial_fields(): void
    {
        $reseller = $this->reseller('Acme');
        $order = Order::factory()->forReseller($reseller)->create([
            'paid_at' => now(),
            'payment_ref' => 'xnd-secret-ref',
            'supplier_response' => ['raw' => 'secret'],
        ]);

        $response = $this->withToken($this->tokenFor($reseller))
            ->getJson('/api/reseller/orders/'.$order->order_number)
            ->assertOk();

        foreach (['cost_price', 'standard_selling_price', 'normal_selling_price', 'platform_profit', 'selling_price', 'supplier_response', 'supplier_ref', 'payment_ref', 'channel_code', 'checkout_idempotency_key'] as $forbidden) {
            $response->assertJsonMissingPath($forbidden);
        }

        // the reseller's own margin IS shown
        $response->assertJsonPath('reseller_profit', $order->reseller_profit);
    }

    public function test_earnings_returns_balance_and_only_this_tenants_entries(): void
    {
        $mine = $this->reseller('Mine');
        $other = $this->reseller('Other');
        $ledger = app(LedgerService::class);
        $ledger->credit('reseller', $mine->id, 6000, 'order_profit', 'order', 1);
        $ledger->credit('reseller', $mine->id, -1000, 'withdrawal', 'withdrawal', 1);
        $ledger->credit('reseller', $other->id, 8888, 'order_profit', 'order', 2);

        $this->withToken($this->tokenFor($mine))->getJson('/api/reseller/earnings')
            ->assertOk()
            ->assertJsonPath('balance', 5000)
            ->assertJsonCount(2, 'entries.data');
    }

    public function test_subscription_returns_the_tier_snapshot_and_charge_history(): void
    {
        $reseller = $this->reseller('Acme');
        $tier = ResellerMembershipTier::query()->create([
            'name' => 'Gold', 'monthly_fee_sen' => 9900, 'markup_percent' => 3, 'is_active' => true,
        ]);
        ResellerSubscription::query()->create([
            'reseller_id' => $reseller->id,
            'reseller_membership_tier_id' => $tier->id,
            'status' => ResellerSubscriptionStatus::Grace,
            'current_period_started_at' => now()->subDays(20),
            'next_charge_at' => now()->subDays(1),
            'grace_until' => now()->addDays(2),
        ]);
        app(LedgerService::class)->credit('reseller', $reseller->id, -9900, 'reseller_tier_fee', 'reseller_subscription', 1);

        $this->withToken($this->tokenFor($reseller))->getJson('/api/reseller/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.tier_name', 'Gold')
            ->assertJsonPath('subscription.status', 'grace')
            ->assertJsonCount(1, 'charge_history');
    }

    public function test_subscription_is_null_when_the_reseller_has_none(): void
    {
        $reseller = $this->reseller('Acme');

        $this->withToken($this->tokenFor($reseller))->getJson('/api/reseller/subscription')
            ->assertOk()
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('charge_history', []);
    }

    public function test_orders_index_respects_the_delivery_status_filter(): void
    {
        $reseller = $this->reseller('Acme');
        Order::factory()->forReseller($reseller)->create(['paid_at' => now()]);
        Order::factory()->forReseller($reseller)->delivered()->create(['paid_at' => now()]);

        $this->withToken($this->tokenFor($reseller))
            ->getJson('/api/reseller/orders?delivery_status=delivered')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
