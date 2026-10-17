<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Jobs\Reseller\DeliverResellerWebhook;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerBotOrderNotification;
use App\Models\ResellerWebhookDelivery;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Reseller\Webhook\ResellerWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/** This file: refund a failed reseller-wallet order to its wallet. */
class OrderControllerRefundToWalletTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

    public function test_refund_to_wallet_credits_the_reseller_and_returns_the_order(): void
    {
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        $order = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/refund-to-wallet");

        $response->assertOk()
            ->assertJsonPath('wallet_reseller.id', $reseller->id)
            ->assertJsonPath('wallet_refunded', true);
        $this->assertSame(945, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
        $this->assertDatabaseHas('ledger_entries', [
            'owner_type' => 'reseller_wallet', 'owner_id' => $reseller->id,
            'type' => 'wallet_refund', 'amount' => 945, 'reference_type' => 'order', 'reference_id' => $order->id,
        ]);
    }

    /**
     * The 2026-10-03 audit's P1: a wallet combo with one leg delivered and
     * one failed had no compensation path at all. It now refunds the
     * undelivered share (ADR-094 decisions 30-33) and credits the
     * delivered leg's profit; a repeat is refused.
     */
    public function test_refund_to_wallet_refunds_the_undelivered_share_of_a_partially_delivered_combo(): void
    {
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        $supplier = Supplier::query()->create(['name' => 'Digiflazz', 'slug' => 'digiflazz', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia-partial-wallet']);
        $legA = Package::query()->create(['game_id' => $game->id, 'name' => 'A', 'denomination' => 1, 'cost_price' => 2400, 'standard_selling_price' => 3000, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $legB = Package::query()->create(['game_id' => $game->id, 'name' => 'B', 'denomination' => 2, 'cost_price' => 1700, 'standard_selling_price' => 2000, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B']);
        $combo = Package::query()->create(['game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true, 'denomination' => 3, 'cost_price' => 4100, 'standard_selling_price' => 5000]);
        $order = $this->order([
            'package_id' => $combo->id, 'wallet_reseller_id' => $reseller->id,
            'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::PartiallyDelivered->value,
            'selling_price' => 5000, 'transaction_fee' => 0, 'final_amount' => 5000, 'affiliate_profit' => 0,
        ]);
        OrderDeliveryLeg::query()->create(['order_id' => $order->id, 'component_package_id' => $legA->id, 'supplier_id' => $supplier->id, 'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'selling_price_sen' => 3000]);
        OrderDeliveryLeg::query()->create(['order_id' => $order->id, 'component_package_id' => $legB->id, 'supplier_id' => $supplier->id, 'leg_number' => 2, 'status' => DeliveryStatus::Failed->value, 'selling_price_sen' => 2000]);

        $this->getJson("/api/orders/{$order->id}")->assertJsonPath('compensation_preview.total_sen', 2000);
        $response = $this->postJson("/api/orders/{$order->id}/refund-to-wallet");

        $response->assertOk()
            ->assertJsonPath('wallet_refund.amount', 2000)
            ->assertJsonPath('delivery_status', 'partially_delivered')
            ->assertJsonPath('compensable', false);
        $this->assertSame(2000, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
        $this->assertSame(600, $order->fresh()->platform_profit); // (5000 − 2000) − 2400
        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertUnprocessable();
        // Compensated — Retry Delivery must never deliver on top of the refund.
        $this->postJson("/api/orders/{$order->id}/retry-delivery")->assertUnprocessable();
    }

    public function test_refund_to_wallet_rejects_a_non_wallet_order(): void
    {
        $this->actingAsAdmin();
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertUnprocessable();
    }

    public function test_refund_to_wallet_rejects_a_non_failed_order(): void
    {
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        $order = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ]);

        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertUnprocessable();
    }

    public function test_refund_to_wallet_rejects_a_repeat_request(): void
    {
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        $order = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ]);

        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertOk();
        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertUnprocessable();

        $this->assertSame(945, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
    }

    /** M-5, 2026-09-29 audit: a voucher already issued for this order must block a wallet refund too, not just a repeat refund. */
    public function test_refund_to_wallet_rejects_an_order_whose_voucher_has_already_been_issued(): void
    {
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        $order = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ]);
        Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_id' => $order->id,
            'code' => 'KRS-ALREADY-ISSUED',
            'customer_email' => 'buyer@example.com',
            'amount' => 945,
            'remaining' => 945,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertUnprocessable();

        $this->assertSame(0, app(LedgerService::class)->balance(LedgerOwnerType::ResellerWallet, $reseller->id));
    }

    /**
     * ADR-076 decision 6 — refundToWallet() never touches
     * payment_status/delivery_status, so it's invisible to
     * SendResellerBotOrderNotification's OrderStatusUpdated listener;
     * this action must notify explicitly instead, guarded by
     * refund_notified_at so a repeat call (already rejected above)
     * can never double-send.
     */
    public function test_refund_to_wallet_marks_the_bot_order_notification_as_refund_notified(): void
    {
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        $order = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ]);
        ResellerBotOrderNotification::query()->create([
            'order_id' => $order->id, 'whatsapp_group_id' => 'g1@g.us',
        ]);

        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertOk();

        $this->assertDatabaseHas('reseller_bot_order_notifications', ['order_id' => $order->id]);
        $this->assertNotNull(
            ResellerBotOrderNotification::query()->where('order_id', $order->id)->first()->refund_notified_at,
        );
    }

    public function test_refund_to_wallet_does_nothing_when_the_order_has_no_bot_notification_row(): void
    {
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        $order = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ]);

        // A wallet order placed via the Reseller API (not the Bot) has
        // no whatsapp_group_id to notify — must not throw.
        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertOk();
    }

    /**
     * ADR-084 PR-3 decision 4: refundToWallet() dispatches `order.refunded`
     * directly (not via OrderStatusUpdated — it never changes
     * delivery_status), the API-channel counterpart to the Bot refund
     * notice.
     */
    public function test_refund_to_wallet_queues_an_order_refunded_webhook(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        app(ResellerWebhookService::class)->setEndpoint($reseller, 'https://example.test/hook');
        $order = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ]);

        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertOk();

        $delivery = ResellerWebhookDelivery::query()->where('order_id', $order->id)->sole();
        $this->assertSame('order.refunded', $delivery->event);
        $this->assertSame('failed', $delivery->payload['delivery_status']);
        $this->assertTrue($delivery->payload['wallet_refunded']);
        $this->assertSame(945, $delivery->payload['wallet_refund']['amount_sen']);
        $this->assertNotNull($delivery->payload['wallet_refund']['refunded_at']);
        Queue::assertPushed(DeliverResellerWebhook::class, fn ($job) => $job->deliveryId === $delivery->id);
    }

    public function test_refund_to_wallet_does_not_queue_a_webhook_when_the_reseller_has_none(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $reseller = $this->walletReseller();
        $order = $this->order([
            'wallet_reseller_id' => $reseller->id,
            'delivery_status' => DeliveryStatus::Failed->value,
            'final_amount' => 945,
        ]);

        $this->postJson("/api/orders/{$order->id}/refund-to-wallet")->assertOk();

        $this->assertSame(0, ResellerWebhookDelivery::query()->count());
        Queue::assertNotPushed(DeliverResellerWebhook::class);
    }
}
