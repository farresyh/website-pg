<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoucherControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'KRS-TEST-1',
            'reference_number' => 'REF-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'reseller_cost_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 90,
            'final_amount' => 1090,
            'platform_profit' => 100,
            'reseller_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ], $overrides));
    }

    public function test_admin_can_create_a_standalone_voucher_below_threshold(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'amount' => 5_000,
            'reason' => 'Goodwill credit',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('remaining', 5_000);
        $this->assertNotNull($response->json('code'));
        $this->assertSame(-5_000, app(LedgerService::class)->balance('platform', null));
    }

    public function test_regular_admin_cannot_create_a_voucher_at_or_above_threshold(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'amount' => 50_000,
            'reason' => 'Large goodwill credit',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_super_admin_can_create_a_voucher_at_or_above_threshold(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $response = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'amount' => 50_000,
            'reason' => 'Large goodwill credit',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'active');
    }

    public function test_index_returns_stats(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'amount' => 1_000,
            'reason' => 'Test',
        ])->assertCreated();

        $response = $this->getJson('/api/vouchers');

        $response->assertOk();
        $response->assertJsonPath('stats.active.count', 1);
        $response->assertJsonPath('stats.total_issued.total', 1_000);
    }

    public function test_admin_can_issue_a_voucher_for_a_failed_order_without_threshold_check(): void
    {
        $order = $this->makeOrder();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/orders/{$order->id}/voucher");

        $response->assertCreated();
        $response->assertJsonPath('amount', 1000); // final_amount(1090) - transaction_fee(90)
        $response->assertJsonPath('customer_email', 'buyer@example.com');
        $this->assertDatabaseHas('vouchers', ['order_id' => $order->id, 'amount' => 1000]);
    }

    /**
     * ADR-024 decision #7 — when the order being given up on itself
     * spent a different voucher (X), restoring X and issuing the new
     * voucher (Y) for the cash portion happen together, and stay two
     * independent, un-merged vouchers.
     */
    public function test_issuing_a_voucher_also_restores_a_different_voucher_this_order_had_spent(): void
    {
        $originalVoucher = Voucher::query()->create([
            'code' => 'KRS-ORIGINAL',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 300,
            'status' => 'active',
            'reason' => 'earlier compensation',
        ]);
        $order = $this->makeOrder(['voucher_id' => $originalVoucher->id, 'voucher_discount' => 200]);
        VoucherRedemption::query()->create([
            'voucher_id' => $originalVoucher->id,
            'order_id' => $order->id,
            'amount' => 200,
            'status' => 'reserved',
        ]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/orders/{$order->id}/voucher");

        $response->assertCreated();
        // The new voucher (Y) — a genuinely separate row, never merged
        // into the restored one.
        $this->assertDatabaseHas('vouchers', ['order_id' => $order->id, 'amount' => 1000]);
        $this->assertSame(2, Voucher::query()->count());

        $this->assertSame(500, $originalVoucher->fresh()->remaining);
        $this->assertSame('active', $originalVoucher->fresh()->status);
        $this->assertSame('restored', VoucherRedemption::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_cannot_issue_order_voucher_for_a_non_failed_order(): void
    {
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::Delivered->value]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/orders/{$order->id}/voucher");

        $response->assertUnprocessable();
    }

    public function test_cannot_issue_a_second_voucher_for_the_same_order(): void
    {
        $order = $this->makeOrder();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();
        $response = $this->postJson("/api/orders/{$order->id}/voucher");

        $response->assertUnprocessable();
        $this->assertDatabaseCount('vouchers', 1);
    }

    /**
     * ADR-024 decision #9 — the detail page's real data source.
     */
    public function test_show_returns_usage_history_and_stats(): void
    {
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::Delivered->value]);
        $voucher = Voucher::query()->create([
            'code' => 'KRS-SHOW-TEST',
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 600,
            'status' => 'active',
            'reason' => 'test',
        ]);
        VoucherRedemption::query()->create([
            'voucher_id' => $voucher->id,
            'order_id' => $order->id,
            'amount' => 400,
            'status' => 'committed',
        ]);

        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $response = $this->getJson("/api/vouchers/{$voucher->id}");

        $response->assertOk();
        $response->assertJsonPath('voucher.code', 'KRS-SHOW-TEST');
        $response->assertJsonPath('voucher.redemptions.0.order.order_number', $order->order_number);
        $response->assertJsonPath('stats.original', 1000);
        $response->assertJsonPath('stats.remaining', 600);
        $response->assertJsonPath('stats.total_used', 400);
        $response->assertJsonPath('stats.restored', 0);
        $response->assertJsonPath('stats.success_rate', 100);
    }

    public function test_admin_can_revoke_an_active_voucher(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        $voucher = Voucher::query()->create([
            'code' => 'VC-TEST0001',
            'customer_email' => 'customer@example.com',
            'amount' => 1_000,
            'remaining' => 1_000,
            'status' => 'active',
            'reason' => 'Test',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/vouchers/{$voucher->id}/revoke");

        $response->assertOk();
        $response->assertJsonPath('status', 'revoked');
    }

    public function test_cannot_revoke_an_already_revoked_voucher(): void
    {
        $admin = AdminUser::factory()->create(['role' => 'admin']);
        $voucher = Voucher::query()->create([
            'code' => 'VC-TEST0002',
            'customer_email' => 'customer@example.com',
            'amount' => 1_000,
            'remaining' => 1_000,
            'status' => 'revoked',
            'reason' => 'Test',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->patchJson("/api/vouchers/{$voucher->id}/revoke");

        $response->assertUnprocessable();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/vouchers');

        $response->assertUnauthorized();
    }
}
