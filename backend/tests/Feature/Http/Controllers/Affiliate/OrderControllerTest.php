<?php

namespace Tests\Feature\Http\Controllers\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateUser;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_affiliate_sees_issued_and_restored_voucher_compensation_on_own_orders(): void
    {
        $affiliate = $this->primaryAffiliate();
        $other = Affiliate::query()->create([
            'business_name' => 'Other Brand', 'markup_pct' => 0, 'status' => 'active',
        ]);
        $user = AffiliateUser::query()->create([
            'owner_type' => 'affiliate', 'owner_id' => $affiliate->id,
            'name' => 'Owner', 'email' => 'owner@affiliate.test',
            'password' => 'password', 'is_active' => true,
        ]);
        $token = $user->createToken('affiliate')->plainTextToken;

        $issuedOrder = Order::factory()->forAffiliate($affiliate)->create([
            'delivery_status' => DeliveryStatus::Failed,
        ]);
        Voucher::query()->create([
            'order_id' => $issuedOrder->id, 'affiliate_id' => $affiliate->id,
            'code' => 'PG-ISSUED-TEST', 'customer_email' => $issuedOrder->customer_email,
            'amount' => 500, 'remaining' => 500, 'status' => 'active', 'reason' => 'Delivery failed',
        ]);

        $restoredOrder = Order::factory()->forAffiliate($affiliate)->create([
            'delivery_status' => DeliveryStatus::Failed,
        ]);
        $usedVoucher = Voucher::query()->create([
            'affiliate_id' => $affiliate->id, 'code' => 'PG-USED-TEST',
            'customer_email' => $restoredOrder->customer_email,
            'amount' => 500, 'remaining' => 500, 'status' => 'active', 'reason' => 'Original credit',
        ]);
        VoucherRedemption::query()->create([
            'voucher_id' => $usedVoucher->id, 'order_id' => $restoredOrder->id,
            'amount' => 500, 'status' => 'restored',
        ]);
        Order::factory()->forAffiliate($other)->create();

        $list = $this->withToken($token)->getJson('/api/affiliate/orders');
        $list->assertOk()->assertJsonCount(2, 'data');
        $byNumber = collect($list->json('data'))->keyBy('order_number');
        $this->assertTrue($byNumber[$issuedOrder->order_number]['has_compensation_voucher']);
        $this->assertFalse($byNumber[$issuedOrder->order_number]['has_voucher_restored']);
        $this->assertFalse($byNumber[$restoredOrder->order_number]['has_compensation_voucher']);
        $this->assertTrue($byNumber[$restoredOrder->order_number]['has_voucher_restored']);

        $this->withToken($token)->getJson("/api/affiliate/orders/{$restoredOrder->order_number}")
            ->assertOk()
            ->assertJsonPath('delivery_status', 'failed')
            ->assertJsonPath('has_voucher_restored', true);
    }
}
