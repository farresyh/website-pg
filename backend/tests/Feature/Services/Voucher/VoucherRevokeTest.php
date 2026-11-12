<?php

namespace Tests\Feature\Services\Voucher;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Voucher;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-004 2026-11-12 addendum, decision 8: revoke is for gift credit only,
 * with a reason, by a stepped-up Super Admin. A failed order's refund is
 * the customer's own money: revoking it would book cash we owe as income.
 */
class VoucherRevokeTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::factory()->superAdmin()->create();
        $this->actingAsSteppedUp($this->admin);
    }

    private function gift(): Voucher
    {
        return app(VoucherService::class)->issue('buyer@example.com', 1000, 'Goodwill', null, $this->admin->id, null, $this->primaryAffiliate()->id);
    }

    private function customerCash(): Voucher
    {
        $order = Order::query()->create([
            'placed_via' => 'storefront', 'affiliate_id' => $this->primaryAffiliate()->id, 'order_number' => 'PG-'.strtoupper(uniqid()),
            'customer_email' => 'buyer@example.com', 'player_id' => '123456', 'cost_price' => 900, 'standard_selling_price' => 1000,
            'selling_price' => 1000, 'transaction_fee' => 100, 'final_amount' => 1100, 'platform_profit' => 100, 'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        return app(OrderSettlementService::class)->settle($order, $this->admin->id)->voucher;
    }

    public function test_a_gift_voucher_is_revoked_with_its_reason_and_admin(): void
    {
        $gift = $this->gift();

        $this->patchJson("/api/vouchers/{$gift->id}/revoke", ['reason' => 'Issued to the wrong customer'])->assertOk();

        $gift->refresh();
        $this->assertSame('revoked', $gift->status);
        $this->assertNotNull($gift->revoked_at);
        $this->assertSame('Issued to the wrong customer', $gift->revoked_reason);
        $this->assertSame($this->admin->id, $gift->revoked_by);
    }

    public function test_a_failed_orders_voucher_cannot_be_revoked(): void
    {
        $voucher = $this->customerCash();

        $this->patchJson("/api/vouchers/{$voucher->id}/revoke", ['reason' => 'Paid back by bank'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('active', $voucher->fresh()->status);
        $this->assertNull($voucher->fresh()->revoked_at);
    }

    public function test_a_reason_is_required(): void
    {
        $this->patchJson("/api/vouchers/{$this->gift()->id}/revoke", ['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_a_regular_admin_or_a_super_admin_without_the_step_up_cannot_revoke(): void
    {
        $gift = $this->gift();

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $this->patchJson("/api/vouchers/{$gift->id}/revoke", ['reason' => 'test'])->assertForbidden();

        $this->flushSession();
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create(['mfa_required' => true, 'mfa_enabled' => true]));
        $this->patchJson("/api/vouchers/{$gift->id}/revoke", ['reason' => 'test'])->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');

        $this->assertSame('active', $gift->fresh()->status);
    }
}
