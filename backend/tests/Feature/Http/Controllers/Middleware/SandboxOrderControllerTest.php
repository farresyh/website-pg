<?php

namespace Tests\Feature\Http\Controllers\Middleware;

use App\Models\AdminUser;
use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\Supplier;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADR-018 — every assertion here is really checking one of two things:
 * (1) the sandbox behaves exactly like the real Order lifecycle (same
 * guards, same shared services), and (2) it is genuinely isolated —
 * never a real ledger write, never visible through Admin\OrderController.
 */
class SandboxOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'super_admin', 'name' => 'Test Admin']));
    }

    public function test_regular_admin_is_forbidden(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->getJson('/api/middleware/sandbox')->assertForbidden();
    }

    private function gameWithPackage(array $packageOverrides = []): array
    {
        $supplier = Supplier::query()->firstOrCreate(
            ['slug' => 'gamevion'],
            ['name' => 'Gamevion', 'api_config' => [], 'currency' => 'MYR'],
        );
        $game = Game::query()->create(['name' => 'Free Fire Global '.uniqid(), 'slug' => 'free-fire-global-'.uniqid()]);
        $package = Package::query()->create(array_merge([
            'game_id' => $game->id,
            'name' => '100 Diamonds',
            'cost_price' => 421,
            'reseller_cost_price' => 500,
            'is_active' => true,
            'supplier_id' => $supplier->id,
            'supplier_package_ref' => 'A-'.uniqid(),
        ], $packageOverrides));

        return [$game, $package];
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/middleware/sandbox')->assertUnauthorized();
    }

    public function test_store_creates_a_paid_failed_test_order(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/sandbox', [
            'game_id' => $game->id,
            'package_id' => $package->id,
        ]);

        $response->assertCreated();
        $this->assertTrue((bool) $response->json('is_test'));
        $this->assertSame(PaymentStatus::Paid->value, $response->json('payment_status'));
        $this->assertSame(DeliveryStatus::Failed->value, $response->json('delivery_status'));
        $this->assertDatabaseHas('orders', ['id' => $response->json('id'), 'is_test' => true]);
    }

    public function test_store_rejects_a_package_from_a_different_game(): void
    {
        [$game] = $this->gameWithPackage();
        [, $otherPackage] = $this->gameWithPackage();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/sandbox', [
            'game_id' => $game->id,
            'package_id' => $otherPackage->id,
        ]);

        $response->assertUnprocessable();
    }

    public function test_store_rejects_an_inactive_package(): void
    {
        [$game, $package] = $this->gameWithPackage(['is_active' => false]);
        $this->actingAsAdmin();

        $response = $this->postJson('/api/middleware/sandbox', [
            'game_id' => $game->id,
            'package_id' => $package->id,
        ]);

        $response->assertUnprocessable();
    }

    public function test_index_never_includes_real_orders(): void
    {
        [$game, $package] = $this->gameWithPackage();
        Order::query()->create([
            'order_number' => 'KRS-REAL-1',
            'is_test' => false,
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'cost_price' => 421, 'reseller_cost_price' => 500, 'selling_price' => 500,
            'transaction_fee' => 0, 'final_amount' => 500, 'platform_profit' => 79, 'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        $this->actingAsAdmin();

        $response = $this->getJson('/api/middleware/sandbox');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_show_404s_for_a_real_order(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $order = Order::query()->create([
            'order_number' => 'KRS-REAL-2',
            'is_test' => false,
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'cost_price' => 421, 'reseller_cost_price' => 500, 'selling_price' => 500,
            'transaction_fee' => 0, 'final_amount' => 500, 'platform_profit' => 79, 'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        $this->actingAsAdmin();

        $this->getJson("/api/middleware/sandbox/{$order->id}")->assertNotFound();
    }

    private function createSandboxOrder(Game $game, Package $package): int
    {
        return $this->postJson('/api/middleware/sandbox', [
            'game_id' => $game->id,
            'package_id' => $package->id,
        ])->json('id');
    }

    public function test_resend_with_simulate_success_delivers_and_never_touches_the_ledger(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $this->actingAsAdmin();
        $orderId = $this->createSandboxOrder($game, $package);

        $response = $this->postJson("/api/middleware/sandbox/{$orderId}/resend", [
            'package_id' => $package->id,
            'simulate_success' => true,
        ]);

        $response->assertOk();
        $this->assertSame(DeliveryStatus::Delivered->value, $response->json('delivery_status'));
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(1, OrderResendAttempt::query()->where('order_id', $orderId)->count());
        $this->assertSame('success', OrderResendAttempt::query()->where('order_id', $orderId)->first()->outcome);
    }

    public function test_resend_with_simulate_failure_keeps_delivery_failed(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $this->actingAsAdmin();
        $orderId = $this->createSandboxOrder($game, $package);

        $response = $this->postJson("/api/middleware/sandbox/{$orderId}/resend", [
            'package_id' => $package->id,
            'simulate_success' => false,
            'error_code' => 'sandbox_test',
            'error_message' => 'Simulated for a test',
        ]);

        $response->assertOk();
        $this->assertSame(DeliveryStatus::Failed->value, $response->json('delivery_status'));
        $this->assertSame('failed', OrderResendAttempt::query()->where('order_id', $orderId)->first()->outcome);
        // Still resendable — a real admin can keep trying different outcomes.
        $this->postJson("/api/middleware/sandbox/{$orderId}/resend", [
            'package_id' => $package->id,
            'simulate_success' => true,
        ])->assertOk();
    }

    /**
     * ADR-026: `error_code` is free-text, so `duplicate_reference`
     * reaches needs_review through the exact same
     * OrderFulfillmentService::fulfill() logic a real ambiguous
     * Gamevion response would — no sandbox-specific wiring needed.
     */
    public function test_resend_with_duplicate_reference_error_code_reaches_needs_review(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $this->actingAsAdmin();
        $orderId = $this->createSandboxOrder($game, $package);

        $response = $this->postJson("/api/middleware/sandbox/{$orderId}/resend", [
            'package_id' => $package->id,
            'simulate_success' => false,
            'error_code' => 'duplicate_reference',
            'error_message' => 'Gamevion already has an order for this reference number',
        ]);

        $response->assertOk();
        $this->assertSame(DeliveryStatus::NeedsReview->value, $response->json('delivery_status'));
    }

    /**
     * ADR-026 decision 4b's sandbox counterpart — the same retry
     * mechanism resolves a needs_review test order, not just a failed
     * one.
     */
    public function test_resend_from_needs_review_is_allowed(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $this->actingAsAdmin();
        $orderId = $this->createSandboxOrder($game, $package);
        $this->postJson("/api/middleware/sandbox/{$orderId}/resend", [
            'package_id' => $package->id,
            'simulate_success' => false,
            'error_code' => 'duplicate_reference',
        ])->assertOk();

        $response = $this->postJson("/api/middleware/sandbox/{$orderId}/resend", [
            'package_id' => $package->id,
            'simulate_success' => true,
        ]);

        $response->assertOk();
        $this->assertSame(DeliveryStatus::Delivered->value, $response->json('delivery_status'));
    }

    /**
     * ADR-026 decision 4a's sandbox counterpart — same
     * OrderFulfillmentService::markDeliveredManually() a real
     * needs_review order uses, so no LedgerEntry is written even
     * though the response looks otherwise identical to a real one.
     */
    public function test_mark_delivered_confirms_a_needs_review_test_order_without_touching_the_ledger(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $this->actingAsAdmin();
        $orderId = $this->createSandboxOrder($game, $package);
        $this->postJson("/api/middleware/sandbox/{$orderId}/resend", [
            'package_id' => $package->id,
            'simulate_success' => false,
            'error_code' => 'duplicate_reference',
        ])->assertOk();

        $response = $this->postJson("/api/middleware/sandbox/{$orderId}/mark-delivered", [
            'supplier_ref' => 'GV-SANDBOX-MANUAL1',
        ]);

        $response->assertOk();
        $this->assertSame(DeliveryStatus::Delivered->value, $response->json('delivery_status'));
        $this->assertSame('GV-SANDBOX-MANUAL1', $response->json('supplier_ref'));
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_mark_delivered_rejects_a_test_order_that_is_not_needs_review(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $this->actingAsAdmin();
        $orderId = $this->createSandboxOrder($game, $package); // starts at delivery_status=failed

        $response = $this->postJson("/api/middleware/sandbox/{$orderId}/mark-delivered", [
            'supplier_ref' => 'GV-1',
        ]);

        $response->assertUnprocessable();
    }

    public function test_mark_delivered_404s_for_a_real_order(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $order = Order::query()->create([
            'order_number' => 'KRS-REAL-MARK-DELIVERED',
            'is_test' => false,
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'cost_price' => 421, 'reseller_cost_price' => 500, 'selling_price' => 500,
            'transaction_fee' => 0, 'final_amount' => 500, 'platform_profit' => 79, 'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/middleware/sandbox/{$order->id}/mark-delivered", ['supplier_ref' => 'GV-1'])
            ->assertNotFound();
    }

    public function test_resend_never_calls_the_real_supplier_adapter_binding(): void
    {
        // No Http::fake()/mock of GamevionAdapter is set up at all in
        // this test — if resend() ever resolved the container's real
        // SupplierAdapter binding instead of constructing
        // FakeSupplierAdapter directly, this would throw a connection
        // error rather than a clean 200.
        [$game, $package] = $this->gameWithPackage();
        $this->actingAsAdmin();
        $orderId = $this->createSandboxOrder($game, $package);

        $this->postJson("/api/middleware/sandbox/{$orderId}/resend", [
            'package_id' => $package->id,
            'simulate_success' => true,
        ])->assertOk();
    }

    public function test_destroy_deletes_a_sandbox_order(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $this->actingAsAdmin();
        $orderId = $this->createSandboxOrder($game, $package);

        $this->deleteJson("/api/middleware/sandbox/{$orderId}")->assertOk();

        $this->assertDatabaseMissing('orders', ['id' => $orderId]);
    }

    /**
     * ADR-018 decision #7: "safe with no residue" depends on
     * order_resend_attempts actually cascading — this proves it, not
     * just assumes the FK behaves as documented.
     */
    public function test_destroy_cascade_deletes_resend_attempts(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $this->actingAsAdmin();
        $orderId = $this->createSandboxOrder($game, $package);
        $this->postJson("/api/middleware/sandbox/{$orderId}/resend", [
            'package_id' => $package->id,
            'simulate_success' => false,
        ])->assertOk();
        $this->assertSame(1, OrderResendAttempt::query()->where('order_id', $orderId)->count());

        $this->deleteJson("/api/middleware/sandbox/{$orderId}")->assertOk();

        $this->assertSame(0, OrderResendAttempt::query()->where('order_id', $orderId)->count());
    }

    public function test_destroy_404s_for_a_real_order(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $order = Order::query()->create([
            'order_number' => 'KRS-REAL-3',
            'is_test' => false,
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'cost_price' => 421, 'reseller_cost_price' => 500, 'selling_price' => 500,
            'transaction_fee' => 0, 'final_amount' => 500, 'platform_profit' => 79, 'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        $this->actingAsAdmin();

        $this->deleteJson("/api/middleware/sandbox/{$order->id}")->assertNotFound();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_destroy_all_only_deletes_test_orders(): void
    {
        [$game, $package] = $this->gameWithPackage();
        $realOrder = Order::query()->create([
            'order_number' => 'KRS-REAL-4',
            'is_test' => false,
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'game_id' => $game->id,
            'package_id' => $package->id,
            'cost_price' => 421, 'reseller_cost_price' => 500, 'selling_price' => 500,
            'transaction_fee' => 0, 'final_amount' => 500, 'platform_profit' => 79, 'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);
        $this->actingAsAdmin();
        $orderId1 = $this->createSandboxOrder($game, $package);
        $this->createSandboxOrder($game, $package);
        $this->postJson("/api/middleware/sandbox/{$orderId1}/resend", [
            'package_id' => $package->id,
            'simulate_success' => false,
        ])->assertOk();

        $this->deleteJson('/api/middleware/sandbox')->assertOk();

        $this->assertSame(0, Order::query()->where('is_test', true)->count());
        $this->assertSame(0, OrderResendAttempt::query()->count());
        $this->assertDatabaseHas('orders', ['id' => $realOrder->id]);
    }
}
