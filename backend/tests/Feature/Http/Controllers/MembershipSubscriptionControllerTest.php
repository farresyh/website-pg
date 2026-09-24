<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\Game;
use App\Models\Membership;
use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipPlan;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlatformSettings;
use App\Models\Supplier;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-068 decisions 5/6 — the self-serve subscription endpoints:
 * `POST /api/membership/subscribe` (start a CHIP checkout for a tier)
 * and `GET /api/membership/subscribe-options` (the authenticated tier
 * list the /membership subscribe view renders).
 */
class MembershipSubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryAffiliate();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        $this->activeFpx();
        $this->bindGateway();
    }

    private function tokenFor(string $email): string
    {
        return app(MembershipSessionTokenService::class)->issue($this->primaryAffiliate()->id, $email);
    }

    private function activeFpx(): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'channel_code' => 'fpx',
            'label' => 'Online Banking (FPX)',
            'category' => 'fpx',
            'gateway' => 'chip',
            'is_active' => true,
            'percentage_rate' => 0.0,
            'flat_fee_sen' => 100,
        ]);
    }

    private function bindGateway(bool $succeeds = true): void
    {
        $gateway = new class($succeeds) implements PaymentGateway
        {
            public function __construct(private readonly bool $succeeds) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return $this->succeeds
                    ? PaymentResponse::success([
                        'payment_request_id' => 'pr-'.$request->referenceId,
                        'actions' => [['type' => 'REDIRECT', 'value' => 'https://gate.chip-in.asia/p/'.$request->referenceId]],
                    ])
                    : PaymentResponse::failure('API_ERROR', 'gateway said no');
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => $paymentRequestId], status: null);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used');
            }
        };

        $this->app->bind('payment-gateway.chip', fn () => $gateway);
    }

    private function tier(string $name): MembershipPlan
    {
        return MembershipPlan::query()->where('name', $name)->firstOrFail();
    }

    // --- subscribe ---

    public function test_subscribe_requires_a_valid_session_token(): void
    {
        $this->postJson('/api/membership/subscribe', [
            'membership_plan_id' => $this->tier('Tier 2')->id,
            'payment_method' => 'fpx',
        ])->assertUnauthorized();
    }

    public function test_subscribe_is_403_when_membership_is_disabled(): void
    {
        PlatformSettings::current()->update(['membership_enabled' => false]);
        $token = $this->tokenFor('member@example.com');

        $this->postJson('/api/membership/subscribe', [
            'membership_plan_id' => $this->tier('Tier 2')->id,
            'payment_method' => 'fpx',
        ], ['Authorization' => "Bearer {$token}"])->assertForbidden();
    }

    public function test_subscribe_is_403_when_the_brand_membership_toggle_is_off(): void
    {
        // ADR-080 decision 1: per-brand gate, not only the global switch.
        $this->primaryAffiliate()->update(['membership_enabled' => false]);
        $token = $this->tokenFor('member@example.com');

        $this->postJson('/api/membership/subscribe', [
            'membership_plan_id' => $this->tier('Tier 2')->id,
            'payment_method' => 'fpx',
            'idempotency_key' => 'idem-gate',
        ], ['Authorization' => "Bearer {$token}"])->assertForbidden();

        $this->assertDatabaseCount('membership_checkout_attempts', 0);
    }

    public function test_subscribe_creates_an_attempt_and_returns_the_checkout_url_and_server_computed_total(): void
    {
        $token = $this->tokenFor('member@example.com');
        $tier = $this->tier('Tier 2');

        $response = $this->postJson('/api/membership/subscribe', [
            'membership_plan_id' => $tier->id,
            'payment_method' => 'fpx',
            'idempotency_key' => 'idem-1',
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertCreated()
            ->assertJsonPath('fee_sen', $tier->fee_sen)
            ->assertJsonPath('total_charged_sen', $tier->fee_sen + 100); // flat fpx fee added on top

        $this->assertStringContainsString('gate.chip-in.asia', $response->json('checkout_url'));

        $attempt = MembershipCheckoutAttempt::query()->firstOrFail();
        $this->assertSame('member@example.com', $attempt->email);
        $this->assertSame($tier->id, $attempt->membership_plan_id);
        $this->assertSame(MembershipCheckoutAttemptStatus::Pending, $attempt->status);
        $this->assertNotNull($attempt->payment_ref);
        $this->assertSame($response->json('subscription_number'), $attempt->subscription_number);
    }

    /**
     * Bug fix, 2026-09-24: same class of bug as
     * AffiliateStorefrontCheckoutTest::test_checkout_return_url_lands_on_the_affiliates_own_domain
     * — a member subscribing on an affiliate's own custom domain must be
     * sent back there after paying, not to the platform default.
     */
    public function test_subscribe_return_url_lands_on_the_affiliates_own_domain(): void
    {
        $affiliate = Affiliate::query()->create([
            'business_name' => 'Acme Resell', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active',
        ]);
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id,
            'hostname' => 'shop.acme.com',
            'status' => AffiliateDomainStatus::Active,
            'is_primary' => true,
        ]);

        $gateway = new class implements PaymentGateway
        {
            public ?PaymentRequest $received = null;

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                $this->received = $request;

                return PaymentResponse::success([
                    'payment_request_id' => 'pr-'.$request->referenceId,
                    'actions' => [['type' => 'REDIRECT', 'value' => 'https://gate.chip-in.asia/p/'.$request->referenceId]],
                ]);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => $paymentRequestId], status: null);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used');
            }
        };
        $this->app->instance('payment-gateway.chip', $gateway);

        $token = app(MembershipSessionTokenService::class)->issue($affiliate->id, 'member@example.com');

        $this->postJson('/api/membership/subscribe', [
            'membership_plan_id' => $this->tier('Tier 2')->id,
            'payment_method' => 'fpx',
        ], ['Authorization' => "Bearer {$token}", 'X-Storefront-Host' => 'shop.acme.com'])->assertCreated();

        $this->assertNotNull($gateway->received);
        $this->assertSame(
            'https://shop.acme.com/membership?checkout=success',
            $gateway->received->channelProperties['success_return_url'],
        );
        $this->assertSame(
            'https://shop.acme.com/membership?checkout=failed',
            $gateway->received->channelProperties['failure_return_url'],
        );
    }

    public function test_subscribe_never_trusts_a_client_amount(): void
    {
        $token = $this->tokenFor('member@example.com');
        $tier = $this->tier('Tier 1');

        $response = $this->postJson('/api/membership/subscribe', [
            'membership_plan_id' => $tier->id,
            'payment_method' => 'fpx',
            'fee_sen' => 1, // ignored
            'total_charged_sen' => 1, // ignored
        ], ['Authorization' => "Bearer {$token}"]);

        $response->assertCreated()->assertJsonPath('fee_sen', $tier->fee_sen);
        $this->assertSame($tier->fee_sen, MembershipCheckoutAttempt::query()->firstOrFail()->fee_sen);
    }

    public function test_subscribe_dedups_a_replayed_request_on_the_idempotency_key(): void
    {
        $token = $this->tokenFor('member@example.com');
        $payload = [
            'membership_plan_id' => $this->tier('Tier 2')->id,
            'payment_method' => 'fpx',
            'idempotency_key' => 'idem-same',
        ];

        $first = $this->postJson('/api/membership/subscribe', $payload, ['Authorization' => "Bearer {$token}"]);
        $second = $this->postJson('/api/membership/subscribe', $payload, ['Authorization' => "Bearer {$token}"]);

        $this->assertSame($first->json('subscription_number'), $second->json('subscription_number'));
        $this->assertSame(1, MembershipCheckoutAttempt::query()->count());
    }

    public function test_subscribe_reuses_a_recent_pending_attempt_for_the_same_member(): void
    {
        $token = $this->tokenFor('member@example.com');

        $first = $this->postJson('/api/membership/subscribe', [
            'membership_plan_id' => $this->tier('Tier 2')->id, 'payment_method' => 'fpx', 'idempotency_key' => 'k1',
        ], ['Authorization' => "Bearer {$token}"]);

        $second = $this->postJson('/api/membership/subscribe', [
            'membership_plan_id' => $this->tier('Tier 2')->id, 'payment_method' => 'fpx', 'idempotency_key' => 'k2',
        ], ['Authorization' => "Bearer {$token}"]);

        $this->assertSame($first->json('subscription_number'), $second->json('subscription_number'));
        $this->assertSame(1, MembershipCheckoutAttempt::query()->count());
    }

    public function test_subscribe_marks_the_attempt_failed_and_returns_502_when_the_gateway_rejects_it(): void
    {
        $this->bindGateway(succeeds: false);
        $token = $this->tokenFor('member@example.com');

        $this->postJson('/api/membership/subscribe', [
            'membership_plan_id' => $this->tier('Tier 2')->id, 'payment_method' => 'fpx',
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(502);

        $this->assertSame(MembershipCheckoutAttemptStatus::Failed, MembershipCheckoutAttempt::query()->firstOrFail()->status);
    }

    // --- subscribe-options ---

    public function test_subscribe_options_is_403_when_membership_is_disabled_for_the_brand(): void
    {
        $this->primaryAffiliate()->update(['membership_enabled' => false]);
        $token = $this->tokenFor('member@example.com');

        $this->getJson('/api/membership/subscribe-options', ['Authorization' => "Bearer {$token}"])
            ->assertForbidden()
            ->assertJson(['message' => 'Membership is not available.']);
    }

    public function test_subscribe_options_lists_every_tier_as_subscribe_for_a_member_with_no_membership(): void
    {
        $token = $this->tokenFor('newbie@example.com');

        $response = $this->getJson('/api/membership/subscribe-options', ['Authorization' => "Bearer {$token}"])->assertOk();

        $response->assertJsonPath('current_plan_id', null);
        foreach ($response->json('plans') as $plan) {
            $this->assertSame('subscribe', $plan['relation']);
            $this->assertArrayHasKey('quota_sen', $plan);
            $this->assertArrayHasKey('id', $plan);
        }
    }

    /**
     * 2026-09-20 addendum: `discount_percent` is a "% cut off markup"
     * config value, not the real price-level savings the subscribe
     * screen's badge must show — `real_savings_percent` is the field
     * that number now comes from, computed against a real package the
     * same way the admin preview and the real guest checkout do.
     */
    public function test_subscribe_options_includes_real_savings_percent_against_a_real_package(): void
    {
        $game = Game::query()->create(['name' => 'Free Fire', 'slug' => 'free-fire', 'is_active' => true]);
        $supplier = Supplier::query()->create(['slug' => 'gamevion', 'name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR']);
        Package::query()->create([
            'game_id' => $game->id,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'GV1',
            'name' => '100 Diamonds',
            'cost_price' => 1000,
            'standard_selling_price' => 1150,
            'markup_percent' => 15,
            'is_active' => true,
        ]);
        $tier1 = $this->tier('Tier 1');
        $tier1->update(['discount_percent' => 80]);
        $token = $this->tokenFor('newbie2@example.com');

        $response = $this->getJson('/api/membership/subscribe-options', ['Authorization' => "Bearer {$token}"])->assertOk();

        $byName = collect($response->json('plans'))->keyBy('name');
        // 15% markup * (1 - 0.8) = 3% effective; (1150 - 1030) / 1150 * 100 = 10.4%.
        $this->assertSame(10.4, $byName['Tier 1']['real_savings_percent']);
    }

    public function test_subscribe_options_real_savings_percent_is_null_with_no_active_packages(): void
    {
        $token = $this->tokenFor('newbie3@example.com');

        $response = $this->getJson('/api/membership/subscribe-options', ['Authorization' => "Bearer {$token}"])->assertOk();

        foreach ($response->json('plans') as $plan) {
            $this->assertNull($plan['real_savings_percent']);
        }
    }

    public function test_subscribe_options_labels_renew_and_upgrade_for_an_active_tier_1_member(): void
    {
        $tier1 = $this->tier('Tier 1');
        Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 't1@example.com',
            'membership_plan_id' => $tier1->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => $tier1->quota_sen,
            'expires_at' => now()->addDays(10),
        ]);
        $token = $this->tokenFor('t1@example.com');

        $response = $this->getJson('/api/membership/subscribe-options', ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertSame($tier1->id, $response->json('current_plan_id'));

        $byName = collect($response->json('plans'))->keyBy('name');
        $this->assertSame('renew', $byName['Tier 1']['relation']);
        $this->assertSame('upgrade', $byName['Tier 2']['relation']);
    }

    public function test_subscribe_options_treats_an_expired_member_as_having_no_current_plan(): void
    {
        $tier2 = $this->tier('Tier 2');
        Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'lapsed@example.com',
            'membership_plan_id' => $tier2->id,
            'status' => 'expired',
            'cycle_started_at' => now()->subDays(40),
            'quota_remaining_sen' => 0,
            'expires_at' => now()->subDays(5),
        ]);
        $token = $this->tokenFor('lapsed@example.com');

        $response = $this->getJson('/api/membership/subscribe-options', ['Authorization' => "Bearer {$token}"])->assertOk();
        $response->assertJsonPath('current_plan_id', null);
        foreach ($response->json('plans') as $plan) {
            $this->assertSame('subscribe', $plan['relation']);
        }
    }
}
