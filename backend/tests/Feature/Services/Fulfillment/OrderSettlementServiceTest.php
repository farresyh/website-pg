<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Fulfillment\OrderFulfillmentException;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Membership\MembershipQuotaService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\PricingBasis;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-094 2026-10-04 addendum, decisions 32-37 — one settlement path for
 * a Failed or PartiallyDelivered order, both instruments. The numbers
 * are the ADR's own worked check.
 */
class OrderSettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = AdminUser::factory()->create(['role' => 'admin'])->id;
        config(['services.real_cost_reconciliation.enabled' => true]);
    }

    private function service(): OrderSettlementService
    {
        return app(OrderSettlementService::class);
    }

    /**
     * Two legs priced 3000/2000; leg 1 delivered at real cost 2400, leg 2
     * failed — u = 2000/5000 = 0.4.
     */
    private function partialComboOrder(array $overrides = []): Order
    {
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'MYR']);
        $gameId = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb-'.uniqid()])->id;
        $legA = Package::query()->create(['game_id' => $gameId, 'name' => 'A', 'denomination' => 100, 'cost_price' => 2500, 'standard_selling_price' => 3000, 'markup_percent' => 20, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $legB = Package::query()->create(['game_id' => $gameId, 'name' => 'B', 'denomination' => 100, 'cost_price' => 1700, 'standard_selling_price' => 2000, 'markup_percent' => 20, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B']);
        $combo = Package::query()->create(['game_id' => $gameId, 'name' => 'Combo', 'is_combo' => true, 'denomination' => 0, 'cost_price' => 4200, 'standard_selling_price' => 5000, 'markup_percent' => 0]);

        $order = Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'PG-PARTIAL-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'customer_phone' => '60123456789',
            'game_id' => $gameId,
            'package_id' => $combo->id,
            'player_id' => '123456',
            'cost_price' => 4200,
            'standard_selling_price' => 5000,
            'selling_price' => 5000,
            'voucher_discount' => 0,
            'transaction_fee' => 100,
            'final_amount' => 5100,
            'platform_profit' => 300,
            'affiliate_profit' => 500,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::PartiallyDelivered->value,
        ], $overrides));

        OrderDeliveryLeg::query()->create(['order_id' => $order->id, 'component_package_id' => $legA->id, 'supplier_id' => $supplier->id, 'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'selling_price_sen' => 3000, 'real_cost_price_sen' => 2400]);
        OrderDeliveryLeg::query()->create(['order_id' => $order->id, 'component_package_id' => $legB->id, 'supplier_id' => $supplier->id, 'leg_number' => 2, 'status' => DeliveryStatus::Failed->value, 'selling_price_sen' => 2000]);

        return $order->fresh();
    }

    private function redeemVoucher(Order $order, int $amount): Voucher
    {
        $voucher = Voucher::query()->create([
            'affiliate_id' => $order->affiliate_id, 'code' => 'VC-PAYWITH-'.uniqid(), 'customer_email' => $order->customer_email,
            'amount' => $amount, 'remaining' => $amount, 'status' => 'active', 'reason' => 'test', 'created_by' => $this->adminId,
        ]);
        app(VoucherService::class)->redeem($voucher->id, $order->id, $amount, $order->customer_email, null, $order->affiliate_id);

        return $voucher;
    }

    private function ledgerSum(string $type, string $ownerType): int
    {
        return (int) LedgerEntry::query()->where('type', $type)->where('owner_type', $ownerType)->sum('amount');
    }

    public function test_retail_partial_delivery_compensates_the_undelivered_share_and_credits_the_delivered_profit(): void
    {
        // S = 5000, D = 1000 paid with voucher X, C = 4000 cash, fee 100.
        $order = $this->partialComboOrder(['voucher_discount' => 1000, 'final_amount' => 4100]);
        $x = $this->redeemVoucher($order, 1000);

        $result = $this->service()->settle($order, $this->adminId);

        // Y = round(4000 × 0.4) = 1600; X gets back round(1000 × 0.4) = 400.
        $this->assertSame(1600, $result->voucher?->amount);
        $this->assertSame(400, $x->fresh()->remaining);
        $this->assertSame(400, VoucherRedemption::query()->where('order_id', $order->id)->value('restored_amount'));

        // affiliate = 500 − round(500 × 0.4) = 300; platform = (5000 − 2000) − 2400 − 300 = 300.
        $fresh = $order->fresh();
        $this->assertSame(300, $fresh->platform_profit);
        $this->assertSame(300, $fresh->affiliate_profit);
        $this->assertSame(300, $this->ledgerSum('order_profit', 'platform'));
        $this->assertSame(300, $this->ledgerSum('order_profit', 'affiliate'));
        $this->assertSame(-1600, $this->ledgerSum('voucher_issued', 'platform'));

        // Compensation never changes the status (decision 29).
        $this->assertSame(DeliveryStatus::PartiallyDelivered, $fresh->delivery_status);
        $this->assertTrue($fresh->isAlreadyCompensated());
    }

    public function test_wallet_partial_delivery_refunds_the_undelivered_share_and_credits_the_delivered_profit(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);
        $order = $this->partialComboOrder([
            'wallet_reseller_id' => $reseller->id, 'pricing_basis' => PricingBasis::ResellerWallet->value,
            'transaction_fee' => 0, 'final_amount' => 5000, 'affiliate_profit' => 0,
        ]);

        $result = $this->service()->settle($order, $this->adminId);

        $this->assertNull($result->voucher);
        $this->assertSame(2000, $result->walletRefundSen);
        $this->assertSame(2000, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
        $this->assertSame(600, $order->fresh()->platform_profit); // (5000 − 2000) − 2400
        $this->assertSame(600, $this->ledgerSum('order_profit', 'platform'));
        $this->assertSame(0, Voucher::query()->count());
    }

    /** u = 1 reproduces the pre-addendum Failed path exactly: cash share as Y, X fully restored, no profit. */
    public function test_a_failed_order_is_compensated_in_full_with_no_profit_credit(): void
    {
        $order = Order::factory()->create([
            'payment_status' => PaymentStatus::Paid, 'delivery_status' => DeliveryStatus::Failed,
            'selling_price' => 1500, 'voucher_discount' => 500, 'transaction_fee' => 50, 'final_amount' => 1050,
        ]);
        $x = $this->redeemVoucher($order, 500);

        $result = $this->service()->settle($order, $this->adminId);

        $this->assertSame(1000, $result->voucher?->amount);
        $this->assertSame(500, $x->fresh()->remaining);
        $this->assertSame(0, LedgerEntry::query()->where('type', 'order_profit')->count());
    }

    public function test_member_quota_is_given_back_for_the_undelivered_share(): void
    {
        $plan = MembershipPlan::query()->where('name', 'Tier 2')->firstOrFail();
        $membership = Membership::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id, 'email' => 'buyer@example.com', 'membership_plan_id' => $plan->id,
            'status' => 'active', 'cycle_started_at' => now()->subDay(), 'quota_remaining_sen' => 10000, 'expires_at' => now()->addDays(30),
        ]);
        $order = $this->partialComboOrder(['membership_id' => $membership->id, 'pricing_basis' => PricingBasis::Member->value, 'affiliate_profit' => 0]);
        app(MembershipQuotaService::class)->decrement($membership->id, $order->id, 1000);

        $this->service()->settle($order, $this->adminId);

        $this->assertSame(9000 + 400, $membership->fresh()->quota_remaining_sen);
    }

    public function test_settling_twice_is_refused(): void
    {
        $order = $this->partialComboOrder();
        $this->service()->settle($order, $this->adminId);

        $this->expectException(OrderFulfillmentException::class);
        $this->service()->settle($order->fresh(), $this->adminId);
    }

    public function test_an_order_that_is_not_compensable_is_refused(): void
    {
        $order = Order::factory()->delivered()->create();

        $this->expectException(OrderFulfillmentException::class);
        $this->service()->settle($order, $this->adminId);
    }

    public function test_preview_returns_the_same_amounts_settle_would_use(): void
    {
        $order = $this->partialComboOrder(['voucher_discount' => 1000, 'final_amount' => 4100]);
        $this->redeemVoucher($order, 1000);

        $preview = $this->service()->preview($order);

        $this->assertSame(['instrument' => 'voucher', 'cash_sen' => 1600, 'voucher_restore_sen' => 400, 'total_sen' => 2000], $preview);
    }
}
