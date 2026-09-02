<?php

namespace Tests\Feature\Http\Controllers;

use App\Http\Controllers\CatalogController;
use App\Models\Game;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Package;
use App\Models\PlatformSettings;
use App\Models\Supplier;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-027 decision 13 / its 2026-08-29 addendum decisions 24/25: the
 * /membership dashboard's backend — status/expiry/quota plus order
 * history, matched on email (Order.customer_email), free of any new
 * linkage table. Guest-callable like the OTP endpoints, but requires
 * the session token (Authorization: Bearer) issued by verify().
 */
class MembershipControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ADR-061: these endpoints resolve the platform storefront via
        // Reseller::primary(), which fails loud when it is absent.
        $this->primaryReseller();
    }

    private function tokenFor(string $email): string
    {
        return app(MembershipSessionTokenService::class)->issue($this->primaryReseller()->id, $email);
    }

    public function test_me_requires_a_valid_session_token(): void
    {
        $this->getJson('/api/membership/me')->assertUnauthorized();
        $this->getJson('/api/membership/me', ['Authorization' => 'Bearer garbage'])->assertUnauthorized();
    }

    /** ADR-061 decision 5: a token minted on another brand's storefront is not honoured here. */
    public function test_me_rejects_a_session_token_issued_for_another_brand(): void
    {
        $otherBrandToken = app(MembershipSessionTokenService::class)->issue(999, 'member@example.com');

        $this->getJson('/api/membership/me', ['Authorization' => "Bearer {$otherBrandToken}"])
            ->assertUnauthorized();
    }

    public function test_me_returns_null_membership_when_the_email_has_no_active_membership(): void
    {
        $token = $this->tokenFor('nobody@example.com');

        $this->getJson('/api/membership/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJson(['membership' => null]);
    }

    public function test_me_returns_the_active_membership_status_and_quota(): void
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = Membership::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 15000,
            'expires_at' => now()->addDays(20),
        ]);
        $token = $this->tokenFor('member@example.com');

        $response = $this->getJson('/api/membership/me', ['Authorization' => "Bearer {$token}"])->assertOk();

        $response->assertJsonPath('membership.tier_name', 'Tier 2');
        $response->assertJsonPath('membership.status', 'active');
        $response->assertJsonPath('membership.quota_remaining_sen', 15000);
        $this->assertNotNull($response->json('membership.expires_at'));
        $this->assertSame($membership->id, $membership->id);
    }

    public function test_me_includes_order_history_matched_by_email(): void
    {
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire', 'is_active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 250, 'standard_selling_price' => 300,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Order::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'order_number' => 'KRS-TEST1', 'reference_number' => 'REF-TEST1',
            'game_id' => $game->id, 'package_id' => $package->id,
            'customer_name' => 'Member', 'customer_email' => 'member@example.com', 'customer_phone' => '+60123456789',
            'player_id' => '12345', 'cost_price' => 250, 'standard_selling_price' => 300, 'selling_price' => 300,
            'transaction_fee' => 0, 'platform_profit' => 50, 'reseller_profit' => 0, 'final_amount' => 300, 'payment_status' => PaymentStatus::Paid, 'delivery_status' => DeliveryStatus::Delivered,
        ]);
        Order::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'order_number' => 'KRS-OTHER', 'reference_number' => 'REF-OTHER',
            'game_id' => $game->id, 'package_id' => $package->id,
            'customer_name' => 'Someone Else', 'customer_email' => 'someone-else@example.com', 'customer_phone' => '+60111111111',
            'player_id' => '99999', 'cost_price' => 250, 'standard_selling_price' => 300, 'selling_price' => 300,
            'transaction_fee' => 0, 'platform_profit' => 50, 'reseller_profit' => 0, 'final_amount' => 300, 'payment_status' => PaymentStatus::Paid, 'delivery_status' => DeliveryStatus::Delivered,
        ]);
        $token = $this->tokenFor('member@example.com');

        $response = $this->getJson('/api/membership/me', ['Authorization' => "Bearer {$token}"])->assertOk();

        $orders = $response->json('order_history');
        $this->assertCount(1, $orders);
        $this->assertSame('KRS-TEST1', $orders[0]['order_number']);
        foreach (['cost_price', 'standard_selling_price', 'supplier_response', 'payment_ref'] as $secretField) {
            $this->assertArrayNotHasKey($secretField, $orders[0]);
        }
    }

    /** ADR-068 decision 16 — the storefront binds the checkout email to this. */
    public function test_me_returns_the_verified_session_email(): void
    {
        $token = $this->tokenFor('member@example.com');

        $this->getJson('/api/membership/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('email', 'member@example.com');
    }

    /** ADR-068 decision 17 — an order bought as this member surfaces even when its contact email differs. */
    public function test_me_order_history_also_matches_by_membership_id(): void
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = Membership::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'email' => 'member@example.com',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 15000,
            'expires_at' => now()->addDays(20),
        ]);

        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire', 'is_active' => true]);
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '50 Diamonds', 'cost_price' => 250, 'standard_selling_price' => 300,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A', 'is_active' => true,
        ]);
        Order::query()->create([
            'reseller_id' => $this->primaryReseller()->id,
            'order_number' => 'KRS-LEGACY', 'reference_number' => 'REF-LEGACY',
            'game_id' => $game->id, 'package_id' => $package->id, 'membership_id' => $membership->id,
            'customer_name' => 'Member', 'customer_email' => 'typo@example.com', 'customer_phone' => '+60123456789',
            'player_id' => '12345', 'cost_price' => 250, 'standard_selling_price' => 300, 'selling_price' => 300,
            'transaction_fee' => 0, 'platform_profit' => 50, 'reseller_profit' => 0, 'final_amount' => 300, 'payment_status' => PaymentStatus::Paid, 'delivery_status' => DeliveryStatus::Delivered,
        ]);
        $token = $this->tokenFor('member@example.com');

        $orders = $this->getJson('/api/membership/me', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->json('order_history');

        $this->assertCount(1, $orders);
        $this->assertSame('KRS-LEGACY', $orders[0]['order_number']);
    }

    public function test_plans_returns_empty_array_when_membership_disabled(): void
    {
        PlatformSettings::current()->update(['membership_enabled' => false]);

        $this->getJson('/api/membership/plans')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_plans_returns_narrow_tier_shape_when_enabled(): void
    {
        PlatformSettings::current()->update(['membership_enabled' => true]);

        $response = $this->getJson('/api/membership/plans')->assertOk();

        $plans = $response->json();
        $this->assertCount(2, $plans);
        $this->assertSame(['Tier 1', 'Tier 2'], array_column($plans, 'name'));
        foreach ($plans as $plan) {
            $this->assertArrayHasKey('name', $plan);
            $this->assertArrayHasKey('fee_sen', $plan);
            $this->assertArrayHasKey('discount_percent', $plan);
            $this->assertArrayNotHasKey('quota_sen', $plan);
            $this->assertArrayNotHasKey('id', $plan);
            $this->assertArrayNotHasKey('created_at', $plan);
            $this->assertArrayNotHasKey('updated_at', $plan);
        }
        $this->assertGreaterThan($plans[0]['discount_percent'], $plans[1]['discount_percent']);
    }

    public function test_plans_cache_is_invalidated_by_forget_packages_cache_for_membership(): void
    {
        PlatformSettings::current()->update(['membership_enabled' => true]);

        $this->getJson('/api/membership/plans')->assertOk();
        $tier2 = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $tier2->update(['discount_percent' => 99]);

        // The same single flush point a membership_plans edit triggers —
        // plans() tags itself `catalog.packages`, so this must clear it.
        CatalogController::forgetPackagesCacheForMembership();

        $this->getJson('/api/membership/plans')
            ->assertOk()
            ->assertJsonPath('1.discount_percent', 99);
    }
}
