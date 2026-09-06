<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Jobs\FulfillOrderJob;
use App\Jobs\ResendOrderDeliveryJob;
use App\Models\AdminUser;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerBotOrderNotification;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
    }

    private function order(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-'.uniqid(),
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 100,
            'final_amount' => 1100,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Pending->value,
            'delivery_status' => DeliveryStatus::NotStarted->value,
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
    }

    public function test_index_lists_orders_with_game_and_package_names(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'standard_selling_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        $this->order(['game_id' => $game->id, 'package_id' => $package->id]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertSame('Free Fire Global', $response->json('data.0.game.name'));
        $this->assertSame('100 Diamonds', $response->json('data.0.package.name'));
    }

    /** Founder-requested visibility: which brand's storefront this order came from. */
    public function test_index_lists_orders_with_the_affiliate_they_belong_to(): void
    {
        $affiliate = $this->primaryAffiliate();
        $this->order(['affiliate_id' => $affiliate->id]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertSame($affiliate->id, $response->json('data.0.affiliate.id'));
        $this->assertSame($affiliate->business_name, $response->json('data.0.affiliate.business_name'));
    }

    /** ADR-073 decision 5: which Reseller (wallet) account placed this order, for a wallet order. */
    public function test_index_lists_wallet_orders_with_the_reseller_that_placed_them(): void
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme Reseller', 'is_active' => true]);
        $this->order(['wallet_reseller_id' => $reseller->id, 'payment_method' => 'wallet']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertSame($reseller->id, $response->json('data.0.wallet_reseller.id'));
        $this->assertSame('Acme Reseller', $response->json('data.0.wallet_reseller.business_name'));
    }

    public function test_index_wallet_reseller_is_null_for_a_normal_storefront_order(): void
    {
        $this->order();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertNull($response->json('data.0.wallet_reseller'));
    }

    public function test_index_can_filter_by_need_action(): void
    {
        $this->order(['order_number' => 'KRS-NEEDS-ACTION', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->order(['order_number' => 'KRS-DELIVERED', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=need_action');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-NEEDS-ACTION', $response->json('data.0.order_number'));
    }

    /**
     * ADR-026 — needs_review is deliberately its own filter, distinct
     * from need_action: "Issue Voucher" is never available for these,
     * only "Mark as Delivered" or "Resend Delivery".
     */
    public function test_index_can_filter_by_needs_review(): void
    {
        $this->order(['order_number' => 'KRS-NEEDS-REVIEW', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::NeedsReview->value]);
        $this->order(['order_number' => 'KRS-NEEDS-ACTION', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=needs_review');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-NEEDS-REVIEW', $response->json('data.0.order_number'));
    }

    /** ADR-032: an async supplier accepted the order but hasn't confirmed the final outcome yet. */
    public function test_index_can_filter_by_pending_delivery(): void
    {
        $this->order(['order_number' => 'KRS-DELIVERY-PENDING', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Pending->value]);
        $this->order(['order_number' => 'KRS-PROCESSING-NOT-PENDING', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Processing->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=pending_delivery');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-DELIVERY-PENDING', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_processing(): void
    {
        $this->order(['order_number' => 'KRS-PROCESSING', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Processing->value]);
        $this->order(['order_number' => 'KRS-PENDING', 'payment_status' => PaymentStatus::Pending->value, 'delivery_status' => DeliveryStatus::NotStarted->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=processing');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-PROCESSING', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_completed(): void
    {
        $this->order(['order_number' => 'KRS-DONE', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Delivered->value]);
        $this->order(['order_number' => 'KRS-NOT-DONE', 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Processing->value]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=completed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-DONE', $response->json('data.0.order_number'));
    }

    /**
     * ADR-021 (PAY-3) — surfaces an order whose payment webhook never
     * arrived. Matches the same 30-minute window
     * ReconcilePendingPaymentsCommand itself acts on.
     */
    public function test_index_can_filter_by_awaiting_payment(): void
    {
        $stuck = $this->order(['order_number' => 'KRS-STUCK-PENDING']);
        $stuck->forceFill(['created_at' => now()->subMinutes(45)])->save();
        $this->order(['order_number' => 'KRS-FRESH-PENDING']); // just checked out, webhook hasn't had time to arrive yet
        $this->order(['order_number' => 'KRS-ALREADY-PAID', 'payment_status' => PaymentStatus::Paid->value])
            ->forceFill(['created_at' => now()->subMinutes(45)])->save();

        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=awaiting_payment');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-STUCK-PENDING', $response->json('data.0.order_number'));
    }

    public function test_index_can_filter_by_today(): void
    {
        $today = $this->order(['order_number' => 'KRS-TODAY']);
        $yesterday = $this->order(['order_number' => 'KRS-YESTERDAY']);
        $yesterday->forceFill(['created_at' => now()->subDay()])->save();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?status=today');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-TODAY', $response->json('data.0.order_number'));
    }

    public function test_index_can_search_by_order_number(): void
    {
        $this->order(['order_number' => 'KRS-FINDME', 'customer_email' => 'a@example.com']);
        $this->order(['order_number' => 'KRS-OTHER', 'customer_email' => 'b@example.com']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?search=FINDME');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_can_search_by_customer_email(): void
    {
        $this->order(['order_number' => 'KRS-A', 'customer_email' => 'findme@example.com']);
        $this->order(['order_number' => 'KRS-B', 'customer_email' => 'other@example.com']);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders?search=findme@example.com');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_show_returns_full_order_detail(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 421, 'standard_selling_price' => 500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A',
        ]);
        $order = $this->order([
            'game_id' => $game->id, 'package_id' => $package->id, 'supplier_id' => $supplier->id,
            'supplier_response' => ['supplier_ref' => 'GV-123'],
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('order_number', $order->order_number);
        $response->assertJsonPath('game.name', 'Free Fire Global');
        $response->assertJsonPath('package.name', '100 Diamonds');
        $response->assertJsonPath('supplier.name', 'Gamevion');
        $response->assertJsonPath('supplier_response.supplier_ref', 'GV-123');
    }

    public function test_show_returns_404_for_a_nonexistent_order(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders/999999');

        $response->assertNotFound();
    }

    /**
     * ORD-7 / ADR-014: the manual retry action queues FulfillOrderJob
     * rather than running it inline, same as the webhook path.
     */
    public function test_retry_delivery_queues_a_fulfillment_job_for_a_failed_order(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertOk();
        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    public function test_retry_delivery_rejects_an_order_that_is_not_failed(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /**
     * ADR-024 decision #8 — once a voucher has been issued for this
     * order (the admin's own "give up" decision), a later successful
     * resend must never be possible, or the customer would be
     * double-compensated (goods delivered and a voucher already held).
     */
    public function test_retry_delivery_rejects_an_order_with_an_already_issued_voucher(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-GUARD-TEST',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /**
     * ADR-026 decision 4b — the same retry mechanism resolves a
     * needs_review order, not just a plain Failed one.
     */
    public function test_retry_delivery_queues_a_fulfillment_job_for_a_needs_review_order(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/retry-delivery");

        $response->assertOk();
        Queue::assertPushed(FulfillOrderJob::class, fn (FulfillOrderJob $job) => $job->order->id === $order->id);
    }

    public function test_retry_delivery_requires_authentication(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->postJson("/api/orders/{$order->id}/retry-delivery")->assertUnauthorized();
    }

    /**
     * ADR-017: same async-dispatch discipline as retryDelivery — the
     * admin request never blocks on a live Gamevion call.
     */
    public function test_resend_queues_a_resend_job_for_a_failed_order_with_a_same_game_package(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 1900, 'standard_selling_price' => 1900,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id, 'note' => 'Bigger pack']);

        $response->assertOk();
        Queue::assertPushed(ResendOrderDeliveryJob::class, fn (ResendOrderDeliveryJob $job) => $job->order->id === $order->id
            && $job->packageId === $package->id
            && $job->note === 'Bigger pack');
    }

    /**
     * ADR-024 decision #8 — see the identical retryDelivery() guard
     * test above for the full reasoning.
     */
    public function test_resend_rejects_an_order_with_an_already_issued_voucher(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create([
            'game_id' => $game->id, 'name' => '210 Diamonds', 'cost_price' => 1900, 'standard_selling_price' => 1900,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B', 'is_active' => true,
        ]);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-GUARD-TEST-2',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_resend_rejects_an_order_that_is_not_failed(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Delivered->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    /**
     * Decision #1: same-game swap only — cross-game rejected before
     * ever reaching the queue.
     */
    public function test_resend_rejects_a_package_from_a_different_game(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $otherGame = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $otherGamePackage = Package::query()->create(['game_id' => $otherGame->id, 'name' => '5 Diamonds', 'cost_price' => 500, 'standard_selling_price' => 500, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'B']);
        $order = $this->order([
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $otherGamePackage->id]);

        $response->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_resend_requires_authentication(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order(['game_id' => $game->id, 'delivery_status' => DeliveryStatus::Failed->value]);

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id])->assertUnauthorized();
    }

    /**
     * Decision #4: "Delivery Logs" — show() surfaces every resend
     * attempt, most recent first.
     */
    public function test_show_includes_resend_attempt_history(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order(['game_id' => $game->id, 'package_id' => $package->id]);
        OrderResendAttempt::query()->create([
            'order_id' => $order->id,
            'package_id' => $package->id,
            'cost_price_sen' => 950,
            'standard_selling_price_sen' => 950,
            'price_diff_sen' => 50,
            'outcome' => 'success',
            'triggered_by' => 'Admin User',
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('resend_attempts'));
        $this->assertSame(50, $response->json('resend_attempts.0.price_diff_sen'));
        $this->assertSame('Admin User', $response->json('resend_attempts.0.triggered_by'));
    }

    /**
     * The Admin Panel's "Issue Voucher" button (Orders page) needs to
     * know whether one already exists for this order, without a
     * separate lookup — show() eager-loads the voucher relation.
     */
    public function test_show_includes_the_issued_voucher_when_one_exists(): void
    {
        $this->actingAsAdmin();
        $order = $this->order(['delivery_status' => DeliveryStatus::Failed->value, 'payment_status' => PaymentStatus::Paid->value]);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'VC-TESTCODE',
            'customer_email' => $order->customer_email,
            'amount' => 1000,
            'remaining' => 1000,
            'status' => 'active',
            'reason' => 'Delivery failed - refund voucher',
            'created_by' => AdminUser::factory()->create()->id,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('voucher.code', 'VC-TESTCODE');
    }

    public function test_show_voucher_is_null_when_none_issued(): void
    {
        $this->actingAsAdmin();
        $order = $this->order();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk();
        $response->assertJsonPath('voucher', null);
    }

    /**
     * ADR-018 decision #2: permanent, unconditional exclusion — a
     * sandbox order must never appear on this real, money-critical
     * screen, regardless of what status filter is applied.
     */
    public function test_index_never_includes_sandbox_orders(): void
    {
        $this->order(['order_number' => 'KRS-SANDBOX-1', 'is_test' => true]);
        $this->order(['order_number' => 'KRS-REAL-1', 'is_test' => false]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/orders');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('KRS-REAL-1', $response->json('data.0.order_number'));
    }

    public function test_show_404s_for_a_sandbox_order(): void
    {
        $order = $this->order(['is_test' => true]);
        $this->actingAsAdmin();

        $this->getJson("/api/orders/{$order->id}")->assertNotFound();
    }

    public function test_retry_delivery_404s_for_a_sandbox_order(): void
    {
        $order = $this->order(['is_test' => true, 'payment_status' => PaymentStatus::Paid->value, 'delivery_status' => DeliveryStatus::Failed->value]);
        $this->actingAsAdmin();

        $this->postJson("/api/orders/{$order->id}/retry-delivery")->assertNotFound();
    }

    public function test_resend_404s_for_a_sandbox_order(): void
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'Free Fire Global', 'slug' => 'free-fire-global']);
        $package = Package::query()->create(['game_id' => $game->id, 'name' => '100 Diamonds', 'cost_price' => 900, 'standard_selling_price' => 900, 'supplier_id' => $supplier->id, 'supplier_package_ref' => 'A']);
        $order = $this->order([
            'is_test' => true,
            'game_id' => $game->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/orders/{$order->id}/resend", ['package_id' => $package->id])->assertNotFound();
    }

    /**
     * ADR-026 decision 4a — the one needs_review exit that isn't a
     * retry. Synchronous (no queue involved), so a plain assertOk() +
     * field check proves the whole path, matching this class's own
     * style for non-queued actions.
     */
    public function test_mark_delivered_confirms_a_needs_review_order(): void
    {
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/mark-delivered", [
            'supplier_ref' => 'GV-RAPI-CONFIRMED1',
            'note' => 'Confirmed via Gamevion dashboard, TARGET/SERVICE/date matched.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('delivery_status', 'delivered');
        $response->assertJsonPath('supplier_ref', 'GV-RAPI-CONFIRMED1');
        $this->assertSame(DeliveryStatus::Delivered, $order->fresh()->delivery_status);
        // Found live via the ADR-023 admin-mark-delivered E2E spec:
        // markDeliveredManually() returns a bare $locked->fresh() with
        // no relations loaded — without show()'s own eager-load
        // mirrored here, this key is missing from the JSON entirely
        // (not null), which crashed the admin panel's own
        // DeliveryLogsTable on the now-undefined resend_attempts once
        // it replaced the full OrderDetail with this response.
        $response->assertJsonPath('resend_attempts', []);
        $response->assertJsonStructure(['game', 'package', 'supplier', 'affiliate', 'voucher']);
    }

    public function test_mark_delivered_requires_a_supplier_ref(): void
    {
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/mark-delivered", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('supplier_ref');
    }

    public function test_mark_delivered_rejects_an_order_that_is_not_needs_review(): void
    {
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'GV-1']);

        $response->assertUnprocessable();
        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
    }

    public function test_mark_delivered_rejects_an_order_with_an_already_issued_voucher(): void
    {
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-GUARD-TEST-2',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'GV-1']);

        $response->assertUnprocessable();
        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    public function test_mark_delivered_404s_for_a_sandbox_order(): void
    {
        $order = $this->order([
            'is_test' => true,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'GV-1'])->assertNotFound();
    }

    public function test_mark_delivered_requires_authentication(): void
    {
        $order = $this->order(['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'GV-1'])->assertUnauthorized();
    }

    /** ADR-073 decision 7: makes a wallet Reseller + its ledger account, mirrors order()'s helper shape. */
    private function walletReseller(): Reseller
    {
        $reseller = Reseller::query()->create(['business_name' => 'Acme Reseller', 'is_active' => true]);
        app(LedgerService::class)->openAccount(LedgerOwnerType::ResellerWallet, $reseller->id);

        return $reseller;
    }

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
}
