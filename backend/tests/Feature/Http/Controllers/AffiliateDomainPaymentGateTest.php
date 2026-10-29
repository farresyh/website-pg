<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\AffiliateDomain;
use App\Models\AffiliateMembershipTier;
use App\Models\AffiliateSubscription;
use App\Models\AffiliateUser;
use App\Models\Game;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlatformSettings;
use App\Models\Supplier;
use App\Services\Affiliate\AffiliateDomainStatus;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-131 decisions 19-21 (CHIP's condition: no branded store uses CHIP without CHIP's written
 * approval): a custom domain browses as before but takes no CHIP payment until an admin approves
 * it. One check (`StorefrontBrand::assertPaymentsEnabled()`) at the top of checkout, the
 * no-link replay branch and membership; the primary host and every non-storefront caller are exempt.
 */
class AffiliateDomainPaymentGateTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'shop.ohaha.test';

    private bool $gatewaySucceeds = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryAffiliate();
        PlatformSettings::current()->update(['membership_enabled' => true]);
        PaymentMethod::query()->create([
            'channel_code' => 'FPX_ABMB', 'label' => 'Test Channel', 'category' => 'fpx', 'gateway' => 'chip',
            'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 210,
        ]);
        $this->app->bind('payment-gateway.chip', fn () => new class($this) implements PaymentGateway
        {
            public function __construct(private readonly AffiliateDomainPaymentGateTest $test) {}

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return $this->test->gatewayWorks()
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
        });
    }

    public function gatewayWorks(): bool
    {
        return $this->gatewaySucceeds;
    }

    /** @return array{0: Affiliate, 1: AffiliateDomain} */
    private function brand(bool $approved, string $host = self::HOST): array
    {
        $affiliate = Affiliate::query()->create(['business_name' => 'Ohaha Resell', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active', 'membership_enabled' => true]);
        $tier = AffiliateMembershipTier::query()->create(['name' => 'Silver', 'monthly_fee_sen' => 5000, 'markup_percent' => 20, 'is_active' => true, 'sort_order' => 1]);
        AffiliateSubscription::query()->create([
            'affiliate_id' => $affiliate->id, 'affiliate_membership_tier_id' => $tier->id, 'status' => 'active',
            'current_period_started_at' => now(), 'next_charge_at' => now()->addDays(30),
        ]);
        $domain = AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id, 'hostname' => $host, 'status' => AffiliateDomainStatus::Active, 'is_primary' => true,
            'payments_approved_at' => $approved ? now() : null,
        ]);

        return [$affiliate, $domain];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $supplier = Supplier::query()->firstOrCreate(['slug' => 'gamevion'], ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->firstOrCreate(['slug' => 'free-fire-global'], ['name' => 'Free Fire Global', 'is_active' => true]);
        $package = Package::query()->firstOrCreate(['game_id' => $game->id, 'name' => '100 Diamonds'], [
            'cost_price' => 1000, 'standard_selling_price' => 1200, 'is_active' => true, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);

        return [
            'game_id' => $game->id, 'package_id' => $package->id, 'customer_email' => 'buyer@example.com', 'customer_name' => 'Buyer One',
            'customer_phone' => '0123456789', 'player_id' => '123456789', 'channel_code' => 'FPX_ABMB', 'idempotency_key' => 'idem-fixed-key-1',
        ];
    }

    /** @return array<string, string> */
    private function host(?string $host = self::HOST): array
    {
        return $host === null ? [] : ['X-Storefront-Host' => $host];
    }

    public function test_an_unapproved_domain_cannot_start_a_payment_and_no_order_is_created(): void
    {
        $this->brand(approved: false);

        $this->postJson('/api/checkout', $this->payload(), $this->host())
            ->assertForbidden()
            ->assertJsonPath('code', 'payments_not_enabled');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_approved_domain_takes_payments_as_before(): void
    {
        $this->brand(approved: true);

        $this->postJson('/api/checkout', $this->payload(), $this->host())->assertCreated();

        $this->assertSame(1, Order::query()->count());
    }

    public function test_the_primary_host_and_a_header_less_request_are_never_gated(): void
    {
        $this->brand(approved: false);
        // Set here, not read from .env: CI has no STOREFRONT_PRIMARY_HOSTS, so the list is empty there.
        config(['services.storefront.primary_hosts' => ['primary.ohaha.test']]);
        $primary = 'primary.ohaha.test';

        $this->postJson('/api/checkout', $this->payload(), $this->host($primary))->assertCreated();
        $second = $this->payload();
        $second['idempotency_key'] = 'idem-fixed-key-2';
        $this->postJson('/api/checkout', $second, $this->host(null))->assertCreated();
    }

    public function test_revoking_approval_stops_the_next_payment_and_a_no_link_replay_waits_for_approval(): void
    {
        [, $domain] = $this->brand(approved: true);
        $payload = $this->payload();

        // The first attempt reaches the gateway, which fails: an order with no payment link yet.
        $this->gatewaySucceeds = false;
        $this->postJson('/api/checkout', $payload, $this->host())->assertStatus(422);
        $this->assertSame(1, Order::query()->count());
        $this->assertNull(Order::query()->sole()->payment_ref);

        $domain->update(['payments_approved_at' => null]);
        $this->gatewaySucceeds = true;
        $this->postJson('/api/checkout', $payload, $this->host())->assertForbidden()->assertJsonPath('code', 'payments_not_enabled');
        $this->assertNull(Order::query()->sole()->payment_ref, 'the replay did not reach the gateway');

        $domain->update(['payments_approved_at' => now()]);
        $this->postJson('/api/checkout', $payload, $this->host())->assertSuccessful();
        $this->assertNotNull(Order::query()->sole()->fresh()->payment_ref);
    }

    public function test_a_replay_of_an_order_that_already_has_a_link_still_returns_it(): void
    {
        [, $domain] = $this->brand(approved: true);
        $payload = $this->payload();
        $this->postJson('/api/checkout', $payload, $this->host())->assertCreated();

        // ADR-131 decision 21: a link made while approved stays payable until CHIP's own expiry.
        $domain->update(['payments_approved_at' => null]);

        $this->postJson('/api/checkout', $payload, $this->host())->assertSuccessful();
    }

    public function test_a_membership_payment_on_an_unapproved_domain_is_refused_and_creates_no_attempt(): void
    {
        PaymentMethod::query()->create(['channel_code' => 'fpx', 'label' => 'FPX', 'category' => 'fpx', 'gateway' => 'chip', 'is_active' => true, 'percentage_rate' => 0.0, 'flat_fee_sen' => 100]);
        [$affiliate] = $this->brand(approved: false);
        $token = app(MembershipSessionTokenService::class)->issue($affiliate->id, 'member@example.com');

        $this->postJson('/api/membership/subscribe', [
            'membership_plan_id' => MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail()->id,
            'payment_method' => 'fpx',
            'idempotency_key' => 'idem-membership-gate',
        ], ['Authorization' => "Bearer {$token}"] + $this->host())
            ->assertForbidden()
            ->assertJsonPath('code', 'payments_not_enabled');

        $this->assertDatabaseCount('membership_checkout_attempts', 0);
    }

    public function test_branding_says_whether_this_host_may_take_payments_and_the_cache_does_not_mix_two_domains_of_one_brand(): void
    {
        [$affiliate] = $this->brand(approved: true);
        AffiliateDomain::query()->create([
            'affiliate_id' => $affiliate->id, 'hostname' => 'second.ohaha.test', 'status' => AffiliateDomainStatus::Active, 'is_primary' => false,
        ]);

        $this->getJson('/api/catalog/branding', $this->host())->assertOk()->assertJsonPath('payments_enabled', true);
        $this->getJson('/api/catalog/branding', $this->host('second.ohaha.test'))->assertOk()->assertJsonPath('payments_enabled', false);
        $this->getJson('/api/catalog/branding', $this->host())->assertOk()->assertJsonPath('payments_enabled', true);
        $this->getJson('/api/catalog/branding')->assertOk()->assertJsonPath('payments_enabled', true);
    }

    public function test_an_admin_approves_and_revokes_a_domain_and_the_affiliate_detail_shows_it(): void
    {
        [$affiliate, $domain] = $this->brand(approved: false);
        $admin = AdminUser::factory()->superAdmin()->create();
        $this->actingAsSteppedUp($admin);

        $this->putJson("/api/affiliates/{$affiliate->id}/domains/{$domain->id}/payments-approval", ['approved' => true])
            ->assertOk()
            ->assertJsonPath('domains.0.payments_approved', true);

        $domain->refresh();
        $this->assertNotNull($domain->payments_approved_at);
        $this->assertSame($admin->id, $domain->payments_approved_by);
        $this->postJson('/api/checkout', $this->payload(), $this->host())->assertCreated();

        $this->putJson("/api/affiliates/{$affiliate->id}/domains/{$domain->id}/payments-approval", ['approved' => false])
            ->assertOk()->assertJsonPath('domains.0.payments_approved', false);
        $this->assertNull($domain->fresh()->payments_approved_at);
        $this->assertNull($domain->fresh()->payments_approved_by);
    }

    public function test_approving_a_domain_of_another_affiliate_is_a_404(): void
    {
        [, $domain] = $this->brand(approved: false);
        $other = Affiliate::query()->create(['business_name' => 'Other', 'markup_pct' => 10, 'max_markup_pct' => 30, 'status' => 'active']);
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());

        $this->putJson("/api/affiliates/{$other->id}/domains/{$domain->id}/payments-approval", ['approved' => true])->assertNotFound();

        $this->assertNull($domain->fresh()->payments_approved_at);
    }

    public function test_the_approval_flag_is_required(): void
    {
        [$affiliate, $domain] = $this->brand(approved: false);
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());

        $this->putJson("/api/affiliates/{$affiliate->id}/domains/{$domain->id}/payments-approval", [])->assertUnprocessable();
    }

    public function test_the_affiliates_own_portal_domain_list_says_payments_are_awaiting_approval(): void
    {
        [$affiliate] = $this->brand(approved: false);
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $affiliate->id, 'name' => 'Staff', 'email' => 'staff@ohaha.test',
            'password' => Hash::make('secret-password'), 'is_active' => true,
        ]);

        $domains = $this->withToken($user->createToken('affiliate')->plainTextToken)->getJson('/api/affiliate/domains')->assertOk()->json('domains');

        $this->assertFalse($domains[0]['payments_approved']);
    }
}
