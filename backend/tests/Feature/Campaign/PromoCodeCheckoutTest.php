<?php

namespace Tests\Feature\Campaign;

use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\PlatformSettings;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\Reseller;
use App\Models\VoucherRedemption;
use App\Services\Campaign\CampaignService;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\OrderPaymentOutcomeService;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentRequest;
use App\Services\Payment\PaymentResponse;
use App\Services\Payment\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\Support\CheckoutControllerFixtures;
use Tests\Support\PromoCodeFixtures;
use Tests\TestCase;

/**
 * ADR-118 2026-11-04 addendum (V1–V20), PR 1: a KOL promo code at
 * storefront checkout. Package fixture: cost 421, price 500, flat fee 210.
 * 5% off 500 = 25, so the order is priced 475, platform profit 54.
 */
class PromoCodeCheckoutTest extends TestCase
{
    use CheckoutControllerFixtures;
    use PromoCodeFixtures;
    use RefreshDatabase;

    public function test_a_promo_lowers_the_price_and_the_platform_profit_and_reserves_the_code(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $code = $this->promoCode();

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5']))->assertCreated();

        $order = Order::query()->firstOrFail();
        // R1: selling_price is after the promo, so every profit writer stays right.
        $this->assertSame(475, $order->selling_price);
        $this->assertSame(25, $order->promo_discount_sen);
        $this->assertSame(54, $order->platform_profit);
        $this->assertSame(475 + 210, $order->final_amount);
        $this->assertSame($code->id, $order->promo_code_id);
        $this->assertSame($code->id, $order->attributed_promo_code_id);

        $redemption = PromoCodeRedemption::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(25, $redemption->amount_sen);
        $this->assertSame('reserved', $redemption->status);
    }

    public function test_the_preview_shows_the_rm_saved_and_the_total_checkout_charges(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode();
        $voucher = $this->voucher(['remaining' => 100]);
        $payload = $this->payload($game, $package, ['promo_code' => 'KOL5', 'voucher_code' => $voucher->code]);

        $preview = $this->postJson('/api/checkout/preview-totals', $payload)->assertOk();

        $preview->assertJsonPath('selling_price_sen', 500);
        $preview->assertJsonPath('promo_discount_sen', 25);
        $preview->assertJsonPath('voucher_discount_sen', 100);

        $this->postJson('/api/checkout', $payload)->assertCreated();
        $this->assertSame($preview->json('final_amount_sen'), Order::query()->firstOrFail()->final_amount);
    }

    public function test_the_voucher_preview_caps_the_voucher_at_the_price_after_the_promo(): void
    {
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode();
        $voucher = $this->voucher(['remaining' => 1000]);

        $this->postJson('/api/vouchers/preview', [
            'game_id' => $game->id, 'package_id' => $package->id, 'voucher_code' => $voucher->code,
            'customer_email' => 'buyer@example.com', 'customer_phone' => '0123456789',
            'player_id' => '123456789', 'promo_code' => 'KOL5',
        ])->assertOk()->assertJsonPath('selling_price', 475)->assertJsonPath('discount', 475);
    }

    public function test_a_code_is_matched_case_insensitively_with_spaces_trimmed(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode();

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => '  kol5 ']))->assertCreated();

        $this->assertSame(25, Order::query()->firstOrFail()->promo_discount_sen);
    }

    public function test_a_customer_who_bought_before_is_refused_on_any_one_detail(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode();

        $byEmail = $this->earlierOrder($game, ['customer_email' => 'BUYER@example.com']);
        $this->assertRefusedAsNotNew($game, $package);
        $byEmail->delete();

        // Stored as typed: `+60 12-345 6789` is the checkout's `0123456789`.
        $byPhone = $this->earlierOrder($game, ['customer_phone' => '+60 12-345 6789']);
        $this->assertRefusedAsNotNew($game, $package);
        $byPhone->delete();

        $this->earlierOrder($game, ['player_id' => '123456789']);
        $this->assertRefusedAsNotNew($game, $package);
    }

    public function test_one_player_account_across_a_game_family_is_one_customer(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage(['family' => 'Mobile Legends']);
        $sibling = Game::query()->create(['name' => 'Mobile Legends Indonesia', 'slug' => 'mlbb-id', 'is_active' => true, 'family' => 'Mobile Legends']);
        $this->promoCode();
        $this->earlierOrder($sibling, ['player_id' => '123456789']);

        $this->assertRefusedAsNotNew($game, $package);
    }

    public function test_a_failed_order_or_a_reseller_wallet_order_does_not_make_a_customer(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode();
        $reseller = Reseller::query()->create(['business_name' => 'Agent', 'email' => 'agent@example.com', 'phone' => '0177777777', 'is_active' => true]);
        // Wallet orders carry the primary brand but are not customers (V5).
        $this->earlierOrder($game, ['player_id' => '123456789', 'wallet_reseller_id' => $reseller->id]);
        // A failed, compensated first order does not use the slot up.
        $this->earlierOrder($game, ['customer_email' => 'buyer@example.com', 'delivery_status' => 'failed']);

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5']))->assertCreated();
    }

    public function test_a_resellers_registered_phone_is_refused(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode();
        Reseller::query()->create(['business_name' => 'Agent', 'email' => 'agent@example.com', 'phone' => '+60123456789', 'is_active' => true]);

        $this->assertRefusedAsNotNew($game, $package);
    }

    public function test_one_kol_discount_per_customer_across_every_code_of_the_brand(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode('KOLA');
        $this->promoCode('KOLB');

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOLA']))->assertCreated();

        // Same email, other phone and account: still the same customer, and A is not delivered yet.
        $this->postJson('/api/checkout', $this->payload($game, $package, [
            'promo_code' => 'KOLB', 'customer_phone' => '0188888888', 'player_id' => '555555555',
        ]))->assertUnprocessable()->assertJsonValidationErrors('promo_code');

        $this->assertSame(1, PromoCodeRedemption::query()->count());
    }

    public function test_an_identical_abandoned_checkout_is_cancelled_and_replaced(): void
    {
        $this->bindGateway();
        $gateway = $this->cancellingGateway();
        $this->app->bind('payment-gateway.chip', fn () => $gateway);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode();

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5']))->assertCreated();
        $first = Order::query()->firstOrFail();

        // A reload: same email, phone and player, a fresh idempotency key.
        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5']))->assertCreated();

        $this->assertSame('failed', $first->fresh()->payment_status->value);
        $this->assertSame('restored', PromoCodeRedemption::query()->where('order_id', $first->id)->value('status'));
        $second = Order::query()->whereKeyNot($first->id)->firstOrFail();
        $this->assertSame('reserved', PromoCodeRedemption::query()->where('order_id', $second->id)->value('status'));
    }

    public function test_an_abandoned_checkout_with_other_details_keeps_the_code(): void
    {
        $this->bindGateway();
        $gateway = $this->cancellingGateway();
        $this->app->bind('payment-gateway.chip', fn () => $gateway);
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode();

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5']))->assertCreated();

        // Knowing the phone alone must never cancel someone's checkout.
        $this->postJson('/api/checkout', $this->payload($game, $package, [
            'promo_code' => 'KOL5', 'customer_email' => 'stranger@example.com', 'player_id' => '222222222',
        ]))->assertUnprocessable()->assertJsonValidationErrors('promo_code');

        $this->assertSame('pending', Order::query()->firstOrFail()->payment_status->value);
    }

    public function test_a_code_that_would_leave_less_than_the_margin_floor_is_refused(): void
    {
        $this->bindGateway();
        // 500 on a cost of 480: 4% margin; 5% off leaves a loss.
        ['game' => $game, 'package' => $package] = $this->gameAndPackage([], ['cost_price' => 480]);
        $this->promoCode();

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.promo_code.0', 'This promo code cannot be used for this item.');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_budget_refuses_an_order_it_cannot_cover_and_the_max_caps_the_discount(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode('SMALL', ['budget_sen' => 20]);
        $this->promoCode('CAPPED', ['max_discount_sen' => 10]);

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'SMALL']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.promo_code.0', 'This promo code has reached its limit.');

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'capped']))->assertCreated();
        $this->assertSame(10, Order::query()->firstOrFail()->promo_discount_sen);
    }

    public function test_an_ended_code_and_another_brands_code_are_refused(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode('PAUSED', ['is_active' => false]);
        $this->promoCode('OVER', ['ends_at' => now()->subMinute()]);
        $otherBrand = Affiliate::query()->create(['business_name' => 'Other', 'markup_pct' => 0, 'status' => 'active', 'is_owned' => true]);
        $this->promoCode('ELSEWHERE', [], $otherBrand->id);

        foreach (['PAUSED' => 'This promo code has ended.', 'OVER' => 'This promo code has ended.', 'ELSEWHERE' => 'This promo code is not valid.', 'NOPE' => 'This promo code is not valid.'] as $code => $message) {
            $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => $code]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.promo_code.0', $message);
        }
    }

    public function test_a_member_using_a_promo_pays_the_standard_price_less_the_promo(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->memberPackage(); // standard 1200, member 1040
        PlatformSettings::current()->update(['membership_enabled' => true]);
        Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'buyer@example.com',
            'membership_plan_id' => MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail()->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 2000,
            'expires_at' => now()->addDays(20),
        ]);
        $this->promoCode();

        $this->withHeader('Authorization', 'Bearer '.$this->membershipToken('buyer@example.com'))
            ->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5']))
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $this->assertSame('standard', $order->pricing_basis->value);
        $this->assertNull($order->membership_id);
        $this->assertSame(1200 - 60, $order->selling_price);
    }

    public function test_a_replay_with_another_promo_is_an_idempotency_mismatch(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $this->promoCode();
        $payload = $this->payload($game, $package, ['promo_code' => 'KOL5']);

        $this->postJson('/api/checkout', $payload)->assertCreated();

        $this->postJson('/api/checkout', [...$payload, 'promo_code' => null])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'idempotency_mismatch');
    }

    public function test_a_failed_payment_restores_the_code_and_frees_the_customer(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $code = $this->promoCode('KOL5', ['budget_sen' => 25]);

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5']))->assertCreated();
        app(OrderPaymentOutcomeService::class)->applyFailed(Order::query()->firstOrFail());

        $this->assertSame(0, app(CampaignService::class)->usedSen($code));
        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5']))->assertCreated();
    }

    public function test_a_lost_reservation_fails_closed_and_gives_back_the_voucher(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $code = $this->promoCode();
        $voucher = $this->voucher(['remaining' => 100]);
        // A concurrent order drains the budget after the unlocked quote.
        Order::created(fn () => PromoCode::query()->whereKey($code->id)->update(['budget_sen' => 0]));

        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5', 'voucher_code' => $voucher->code]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'checkout_closed');

        $this->assertSame('failed', Order::query()->firstOrFail()->payment_status->value);
        $this->assertSame(0, PromoCodeRedemption::query()->count());
        $this->assertSame(100, $voucher->fresh()->remaining);
        $this->assertSame('restored', VoucherRedemption::query()->value('status'));
    }

    public function test_a_later_order_of_a_kol_customer_counts_for_the_code(): void
    {
        $this->bindGateway();
        ['game' => $game, 'package' => $package] = $this->gameAndPackage();
        $code = $this->promoCode();
        $first = $this->earlierOrder($game, ['customer_email' => 'buyer@example.com', 'promo_code_id' => $code->id]);
        PromoCodeRedemption::query()->create(['promo_code_id' => $code->id, 'order_id' => $first->id, 'amount_sen' => 25, 'status' => 'committed']);

        $this->postJson('/api/checkout', $this->payload($game, $package, ['customer_phone' => '0111111111']))->assertCreated();
        $this->postJson('/api/checkout', $this->payload($game, $package, [
            'customer_email' => 'stranger@example.com', 'customer_phone' => '0122222222', 'player_id' => '333333333',
        ]))->assertCreated();

        $orders = Order::query()->whereKeyNot($first->id)->orderBy('id')->get();
        $this->assertSame($code->id, $orders[0]->attributed_promo_code_id);
        $this->assertNull($orders[0]->promo_code_id);
        $this->assertSame(500, $orders[0]->selling_price);
        $this->assertNull($orders[1]->attributed_promo_code_id);
    }

    private function assertRefusedAsNotNew(Game $game, $package): void
    {
        $this->postJson('/api/checkout', $this->payload($game, $package, ['promo_code' => 'KOL5']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.promo_code.0', 'This promo code is for new customers only, once per customer. If you have an unfinished payment, please try again in 30 minutes.');
    }

    /** A CHIP that answers a cancelled purchase as finally failed. */
    private function cancellingGateway(): PaymentGateway
    {
        return new class implements PaymentGateway
        {
            private array $cancelled = [];

            public function createPayment(PaymentRequest $request): PaymentResponse
            {
                return PaymentResponse::success(['payment_request_id' => 'pr-'.$request->referenceId]);
            }

            public function getPayment(string $paymentRequestId): PaymentResponse
            {
                return in_array($paymentRequestId, $this->cancelled, true)
                    ? PaymentResponse::success(['payment_request_id' => $paymentRequestId, 'failure_is_final' => true], PaymentStatus::Failed)
                    : PaymentResponse::success(['payment_request_id' => $paymentRequestId, 'actions' => []]);
            }

            public function cancelPayment(string $paymentRequestId): PaymentResponse
            {
                $this->cancelled[] = $paymentRequestId;

                return PaymentResponse::success([]);
            }

            public function verifyWebhookSignature(Request $request): bool
            {
                throw new RuntimeException('not used in this test');
            }

            public function parseWebhookEvent(array $payload): PaymentWebhookEvent
            {
                throw new RuntimeException('not used in this test');
            }
        };
    }
}
