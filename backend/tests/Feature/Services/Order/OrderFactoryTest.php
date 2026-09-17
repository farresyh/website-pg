<?php

namespace Tests\Feature\Services\Order;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Services\Order\DuplicateOrderException;
use App\Services\Order\OrderDraft;
use App\Services\Order\OrderFactory;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\PricingBasis;
use App\Services\Pricing\PricingResolution;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-060 PR-4a: the single order-creation seam CheckoutService and
 * ResellerOrderPlacementService both route through.
 */
class OrderFactoryTest extends TestCase
{
    use RefreshDatabase;

    private function factory(): OrderFactory
    {
        return app(OrderFactory::class);
    }

    private function standardResolution(): PricingResolution
    {
        return new PricingResolution(
            costPriceSen: 900,
            standardSellingPriceSen: 1000,
            sellingPriceSen: 1000,
            platformProfitSen: 100,
            affiliateProfitSen: 0,
            basis: PricingBasis::Standard,
        );
    }

    private function draft(array $overrides = []): OrderDraft
    {
        return new OrderDraft(...array_merge([
            'pricing' => $this->standardResolution(),
            'idempotencyKey' => 'idem-1',
            'customerEmail' => 'buyer@example.test',
            'customerName' => 'Buyer',
            'customerPhone' => '0100000000',
            'playerId' => '123456',
            'serverId' => null,
            'affiliateId' => $this->primaryAffiliate()->id,
            'paymentStatus' => PaymentStatus::Pending,
            'paidAt' => null,
            'paymentMethod' => 'fpx',
        ], $overrides));
    }

    public function test_creates_an_order_mapping_the_resolution_and_the_draft_fields(): void
    {
        $order = $this->factory()->create($this->draft([
            'paymentGateway' => 'chip',
            'channelCode' => 'fpx',
            'affiliateMarkupPct' => 2.5,
            'transactionFeeSen' => 50,
            'finalAmountSen' => 1050,
        ]));

        $this->assertNotNull($order->order_number);
        $this->assertSame('idem-1', $order->checkout_idempotency_key);
        $this->assertFalse($order->is_test);
        $this->assertSame('buyer@example.test', $order->customer_email);
        $this->assertSame(900, $order->cost_price);
        $this->assertSame(1000, $order->standard_selling_price);
        $this->assertSame(1000, $order->selling_price);
        $this->assertSame(100, $order->platform_profit);
        $this->assertSame(0, $order->affiliate_profit);
        $this->assertSame('2.50', (string) $order->affiliate_markup_pct);
        $this->assertSame(50, $order->transaction_fee);
        $this->assertSame(1050, $order->final_amount);
        $this->assertSame(PricingBasis::Standard, $order->pricing_basis);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame('not_started', $order->delivery_status->value);
        $this->assertSame('chip', $order->payment_gateway);
        $this->assertSame('fpx', $order->channel_code);
    }

    public function test_defaults_final_amount_to_the_selling_price_when_the_draft_gives_none(): void
    {
        $order = $this->factory()->create($this->draft());

        $this->assertSame(1000, $order->final_amount);
        $this->assertSame(0, $order->transaction_fee);
        $this->assertSame(0, $order->voucher_discount);
    }

    public function test_carries_the_member_fields_from_the_resolution(): void
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'email' => 'member@example.test',
            'membership_plan_id' => $plan->id,
            'status' => 'active',
            'cycle_started_at' => now(),
            'quota_remaining_sen' => 100000,
            'expires_at' => now()->addDays(20),
        ]);

        $order = $this->factory()->create($this->draft([
            'pricing' => new PricingResolution(
                costPriceSen: 900,
                standardSellingPriceSen: 1000,
                sellingPriceSen: 930,
                platformProfitSen: 30,
                affiliateProfitSen: 0,
                basis: PricingBasis::Member,
                normalSellingPriceSen: 1000,
                membershipId: $membership->id,
                memberDiscountPercent: 80.0,
                markupPercent: 15.0,
            ),
        ]));

        $this->assertSame(PricingBasis::Member, $order->pricing_basis);
        $this->assertSame($membership->id, $order->membership_id);
        $this->assertSame('80.00', (string) $order->member_discount_percent);
        $this->assertSame(1000, $order->normal_selling_price);
        $this->assertSame(930, $order->selling_price);
        // ADR-105 decision 3 — the package's own markup_percent, frozen
        // for OrderResendService to reconcile a later resend against.
        $this->assertSame('15.00', (string) $order->markup_percent);
    }

    public function test_snapshots_wholesale_markup_pct_from_the_resolution(): void
    {
        $order = $this->factory()->create($this->draft([
            'pricing' => new PricingResolution(
                costPriceSen: 1000,
                standardSellingPriceSen: 1500,
                sellingPriceSen: 1320,
                platformProfitSen: 200,
                affiliateProfitSen: 120,
                basis: PricingBasis::Affiliate,
                wholesaleMarkupPct: 20.0,
            ),
        ]));

        $this->assertSame('20.00', (string) $order->wholesale_markup_pct);
    }

    public function test_leaves_wholesale_markup_pct_null_for_a_standard_order(): void
    {
        $order = $this->factory()->create($this->draft());

        $this->assertNull($order->wholesale_markup_pct);
    }

    public function test_throws_a_duplicate_order_exception_on_a_repeated_idempotency_key(): void
    {
        $this->factory()->create($this->draft(['idempotencyKey' => 'dupe']));

        $this->expectException(DuplicateOrderException::class);
        $this->factory()->create($this->draft(['idempotencyKey' => 'dupe']));
    }

    public function test_a_non_duplicate_database_error_still_surfaces_as_a_query_exception(): void
    {
        // A missing affiliate FK is an integrity violation, not a
        // duplicate — it must NOT be swallowed as DuplicateOrderException
        // (the SQLSTATE-23000 string check the callers used before this
        // seam would have).
        $this->expectException(QueryException::class);
        $this->factory()->create($this->draft(['affiliateId' => 999999]));
    }
}
