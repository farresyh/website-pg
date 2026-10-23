<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\OrderResendAttempt;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OrderCredentialFixtures;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\TestCase;

/**
 * ADR-125 decisions 8/9/28/36/42/44: a manual supplier's order goes
 * Pending at fulfilment, and every human confirmation runs through
 * finalizePendingDelivery(): real cost, profit, voucher commit, drawdown,
 * and an attempt row naming who confirmed it.
 */
class Adr125ManualQueueTest extends TestCase
{
    use OrderCredentialFixtures;
    use OrderFulfillmentFixtures;
    use RefreshDatabase;

    private Supplier $manual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manual = Supplier::query()->create([
            'name' => 'Roblox A', 'slug' => 'roblox-a', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR',
        ]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin', 'name' => 'Aina Admin']));
    }

    /** cost 900, selling 1000, platform profit 100 (OrderFulfillmentFixtures). */
    private function pendingManualOrder(array $overrides = []): Order
    {
        $order = $this->paidOrder(['supplier_id' => $this->manual->id, 'server_id' => null, 'supplier_product_ref' => 'MANUAL-1'] + $overrides);

        return app(OrderFulfillmentService::class)->fulfill($order);
    }

    private function drawdowns(Order $order): array
    {
        return SupplierLedgerEntry::query()
            ->where('reference_type', 'order')->where('reference_id', $order->id)
            ->where('type', SupplierLedgerEntryType::OrderDrawdown->value)
            ->pluck('amount')->map(fn ($a) => (float) $a)->all();
    }

    public function test_fulfilment_puts_a_manual_order_in_the_queue(): void
    {
        $order = $this->pendingManualOrder();
        $this->paidOrder(['order_number' => 'KRS-TEST-API', 'delivery_status' => DeliveryStatus::Pending->value]);
        $this->paidOrder(['order_number' => 'KRS-TEST-SANDBOX', 'supplier_id' => $this->manual->id, 'is_test' => true, 'delivery_status' => DeliveryStatus::Pending->value]);

        $this->assertSame(DeliveryStatus::Pending, $order->delivery_status);
        $this->assertSame('pending', OrderResendAttempt::query()->where('order_id', $order->id)->sole()->outcome);

        $this->getJson('/api/manual-queue')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.order_number', $order->order_number)
            ->assertJsonPath('0.login_wiped', false);
    }

    public function test_delivered_at_the_agreed_cost_credits_profit_and_records_the_drawdown(): void
    {
        $order = $this->pendingManualOrder();

        $this->postJson("/api/orders/{$order->id}/manual/delivered", ['supplier_id' => $order->supplier_id, 'supplier_id' => $order->supplier_id])->assertOk()->assertJsonPath('delivery_status', 'delivered');

        $order->refresh();
        $this->assertSame(900, $order->real_cost_price_sen);
        $this->assertSame(100, $order->platform_profit);
        $this->assertSame(100, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame([-9.0], $this->drawdowns($order));

        $attempts = OrderResendAttempt::query()->where('order_id', $order->id)->orderBy('id')->get();
        $this->assertSame(['success', 'success'], $attempts->pluck('outcome')->all());
        $this->assertSame('manual_confirm', $attempts[1]->attempt_type);
        $this->assertSame('Aina Admin', $attempts[1]->triggered_by);
    }

    public function test_a_different_real_cost_needs_a_reason_and_reconciles_profit(): void
    {
        config(['services.real_cost_reconciliation.enabled' => true]);
        $order = $this->pendingManualOrder();

        $this->postJson("/api/orders/{$order->id}/manual/delivered", ['supplier_id' => $order->supplier_id, 'real_cost_sen' => 950])->assertJsonValidationErrors('reason');
        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);

        $this->postJson("/api/orders/{$order->id}/manual/delivered", ['supplier_id' => $order->supplier_id, 'real_cost_sen' => 950, 'reason' => 'Supplier raised the price'])->assertOk();

        $order->refresh();
        $this->assertSame(950, $order->real_cost_price_sen);
        $this->assertSame(50, $order->platform_profit);
        $this->assertSame([-9.5], $this->drawdowns($order));
    }

    public function test_a_second_confirmation_is_already_finished(): void
    {
        $order = $this->pendingManualOrder();
        $this->postJson("/api/orders/{$order->id}/manual/delivered", ['supplier_id' => $order->supplier_id, 'supplier_id' => $order->supplier_id])->assertOk();

        $this->postJson("/api/orders/{$order->id}/manual/delivered", ['supplier_id' => $order->supplier_id, 'supplier_id' => $order->supplier_id])->assertJsonValidationErrors('order');
        $this->postJson("/api/orders/{$order->id}/manual/failed", ['supplier_id' => $order->supplier_id, 'reason' => 'x'])->assertJsonValidationErrors('order');

        $this->assertSame(100, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertCount(1, $this->drawdowns($order));
    }

    public function test_failed_moves_no_money_and_a_wrong_failed_can_be_corrected_once(): void
    {
        $order = $this->pendingManualOrder();

        $this->postJson("/api/orders/{$order->id}/manual/failed", ['supplier_id' => $order->supplier_id, 'reason' => 'Wrong password'])->assertOk()->assertJsonPath('delivery_status', 'failed');
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame([], $this->drawdowns($order));

        $this->postJson("/api/orders/{$order->id}/manual/correct-to-delivered", ['supplier_id' => $order->supplier_id, 'supplier_id' => $order->supplier_id])->assertJsonValidationErrors('reason');

        $this->postJson("/api/orders/{$order->id}/manual/correct-to-delivered", ['supplier_id' => $order->supplier_id, 'reason' => 'Supplier did top up'])
            ->assertOk()->assertJsonPath('delivery_status', 'delivered');

        $this->assertSame(100, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame([-9.0], $this->drawdowns($order));
        $this->assertSame('failed', $order->fresh()->supplier_response['corrected_from']);

        $this->postJson("/api/orders/{$order->id}/manual/correct-to-delivered", ['supplier_id' => $order->supplier_id, 'reason' => 'again'])->assertJsonValidationErrors('order');
        $this->assertCount(1, $this->drawdowns($order));
    }

    public function test_a_compensated_failed_order_cannot_be_corrected(): void
    {
        $order = $this->pendingManualOrder();
        $this->postJson("/api/orders/{$order->id}/manual/failed", ['supplier_id' => $order->supplier_id, 'reason' => 'Wrong password'])->assertOk();
        $this->postJson("/api/orders/{$order->id}/voucher", ['reason' => 'Could not top up'])->assertCreated();

        $this->postJson("/api/orders/{$order->id}/manual/correct-to-delivered", ['supplier_id' => $order->supplier_id, 'reason' => 'Supplier did top up'])
            ->assertJsonValidationErrors('order');

        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
        $this->assertSame([], $this->drawdowns($order));
    }

    public function test_a_needs_review_manual_order_is_confirmed_in_the_queue_not_by_mark_delivered(): void
    {
        $order = $this->paidOrder(['supplier_id' => $this->manual->id, 'delivery_status' => DeliveryStatus::NeedsReview->value]);

        $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'X'])->assertJsonValidationErrors('delivery_status');

        $this->postJson("/api/orders/{$order->id}/manual/delivered", ['supplier_id' => $order->supplier_id, 'supplier_id' => $order->supplier_id])->assertOk();
        $this->assertSame([-9.0], $this->drawdowns($order));
        $this->assertSame('Aina Admin', OrderResendAttempt::query()->where('order_id', $order->id)->sole()->triggered_by);
    }

    public function test_an_api_suppliers_order_is_refused_by_the_manual_actions(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Pending->value]);

        $this->postJson("/api/orders/{$order->id}/manual/delivered", ['supplier_id' => $order->supplier_id, 'supplier_id' => $order->supplier_id])->assertJsonValidationErrors('order');
        $this->postJson("/api/orders/{$order->id}/manual/failed", ['supplier_id' => $order->supplier_id, 'reason' => 'x'])->assertJsonValidationErrors('order');
        $this->assertSame(DeliveryStatus::Pending, $order->fresh()->delivery_status);
    }

    public function test_a_wiped_login_blocks_retry_until_it_is_replaced(): void
    {
        $order = $this->pendingManualOrder();
        $this->postJson("/api/orders/{$order->id}/manual/failed", ['supplier_id' => $order->supplier_id, 'reason' => 'Wrong password'])->assertOk();
        $this->credentialFor($order);
        OrderCredential::query()->where('order_id', $order->id)->update(['secrets' => null, 'purged_at' => now()]);

        $this->postJson("/api/orders/{$order->id}/retry-delivery")->assertJsonValidationErrors('delivery_status');

        OrderCredential::query()->where('order_id', $order->id)->update(['purged_at' => null]);
        $this->postJson("/api/orders/{$order->id}/retry-delivery")->assertOk();
    }

    public function test_an_admin_lists_only_active_manual_suppliers(): void
    {
        Supplier::query()->create(['name' => 'Off', 'slug' => 'off', 'driver' => Supplier::DRIVER_MANUAL, 'api_config' => [], 'currency' => 'MYR', 'is_active' => false]);
        Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);

        $this->getJson('/api/manual-suppliers')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Roblox A');
    }

    public function test_a_replaced_password_is_stored_exactly_as_typed(): void
    {
        $order = $this->pendingManualOrder();
        $this->credentialFor($order);
        $this->actingAsSteppedUp(AdminUser::factory()->superAdmin()->create());

        $this->putJson("/api/orders/{$order->id}/credentials", ['field' => 'password', 'value' => '  spaced pass  '])->assertOk();

        $this->assertSame('  spaced pass  ', OrderCredential::query()->where('order_id', $order->id)->sole()->secrets['password']);
    }
}
