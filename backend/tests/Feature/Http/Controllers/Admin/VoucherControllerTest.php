<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AdminUser;
use App\Models\Affiliate;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VoucherControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'order_number' => 'KRS-TEST-1',
            'reference_number' => 'REF-TEST-1',
            'customer_email' => 'buyer@example.com',
            'player_id' => '123456',
            'cost_price' => 900,
            'standard_selling_price' => 900,
            'selling_price' => 1000,
            'transaction_fee' => 90,
            'final_amount' => 1090,
            'platform_profit' => 100,
            'affiliate_profit' => 0,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ], $overrides));
    }

    /**
     * ADR-094 decision 9 (Phase 4): a combo order whose legs genuinely
     * split Delivered/Failed — needs_review, but not the ordinary
     * ambiguous kind. Legs are created directly (not via the
     * fulfillment engine itself, already covered by
     * OrderFulfillmentServiceComboTest) since this suite is only
     * exercising VoucherController's own gating logic.
     */
    private function makePartialComboOrder(): Order
    {
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia']);
        $delivered = Package::query()->create([
            'game_id' => $game->id, 'name' => '4810 Diamonds', 'denomination' => 4810,
            'cost_price' => 40000, 'standard_selling_price' => 44000, 'markup_percent' => 10,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-4810',
        ]);
        $failed = Package::query()->create([
            'game_id' => $game->id, 'name' => '2976 Diamonds', 'denomination' => 2976,
            'cost_price' => 25000, 'standard_selling_price' => 27500, 'markup_percent' => 10,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-2976',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => '7786 Diamonds (Combo)', 'is_combo' => true,
            'denomination' => 7786, 'cost_price' => 65000, 'standard_selling_price' => 71500, 'markup_percent' => 10,
        ]);

        $order = $this->makeOrder([
            'package_id' => $combo->id,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'final_amount' => 71500 + 90,
            'transaction_fee' => 90,
        ]);

        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $delivered->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'GV-REF-1',
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $failed->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::Failed->value, 'failure_reason' => 'Insufficient balance',
        ]);

        return $order;
    }

    public function test_admin_can_create_a_standalone_voucher_below_threshold(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 5_000,
            'reason' => 'Goodwill credit',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('remaining', 5_000);
        $this->assertNotNull($response->json('code'));
        $this->assertSame(-5_000, app(LedgerService::class)->balance('platform', null));
    }

    /**
     * ADR-060 PR-4d, decision 5: the brand picker is required, and the
     * created voucher is scoped to it.
     */
    public function test_create_requires_a_brand_and_scopes_the_voucher_to_it(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $brand = Affiliate::query()->create(['business_name' => 'Acme Resell', 'markup_pct' => 5, 'status' => 'active']);

        $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'amount' => 1_000,
            'reason' => 'no brand',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertJsonValidationErrors('affiliate_id');

        $response = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $brand->id,
            'amount' => 1_000,
            'reason' => 'Goodwill credit',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('vouchers', ['code' => $response->json('code'), 'affiliate_id' => $brand->id]);
    }

    public function test_a_compensation_voucher_inherits_the_orders_brand(): void
    {
        $brand = Affiliate::query()->create(['business_name' => 'Acme Resell', 'markup_pct' => 5, 'status' => 'active']);
        $order = $this->makeOrder(['affiliate_id' => $brand->id]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/orders/{$order->id}/voucher");

        $response->assertCreated();
        $this->assertDatabaseHas('vouchers', ['order_id' => $order->id, 'affiliate_id' => $brand->id]);
    }

    public function test_regular_admin_cannot_create_a_voucher_at_or_above_threshold(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 50_000,
            'reason' => 'Large goodwill credit',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_amount_above_the_sanity_ceiling_is_rejected_even_for_super_admin(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $response = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 1_000_001,
            'reason' => 'Fat-fingered amount',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('vouchers', 0);
    }

    public function test_super_admin_can_create_a_voucher_at_or_above_threshold(): void
    {
        Sanctum::actingAs(AdminUser::factory()->superAdmin()->create());

        $response = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 50_000,
            'reason' => 'Large goodwill credit',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'active');
    }

    public function test_index_returns_stats(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 1_000,
            'reason' => 'Test',
            'idempotency_key' => (string) Str::uuid(),
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
        $response->assertJsonPath('restored_only', false);
        $response->assertJsonPath('voucher.amount', 1000); // final_amount(1090) - transaction_fee(90)
        $response->assertJsonPath('voucher.customer_email', 'buyer@example.com');
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
            'affiliate_id' => $this->primaryAffiliate()->id,
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

    /**
     * ADR-024 addendum (2026-09-17, restore-only) — a full-cover-by-
     * voucher order (ADR-024 decision 5: transaction_fee=0, final_amount=0)
     * that later fails delivery must still give back the ORIGINAL
     * voucher's spent balance, but must never mint a pointless RM0.00
     * compensation voucher on top of it — the amount this order's own
     * "cash portion" comes to is genuinely zero.
     */
    public function test_full_cover_by_voucher_order_restores_without_minting_a_zero_amount_voucher(): void
    {
        $originalVoucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-FULLCOVER',
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 0,
            'status' => 'exhausted',
            'reason' => 'earlier compensation',
        ]);
        $order = $this->makeOrder([
            'voucher_id' => $originalVoucher->id,
            'voucher_discount' => 1000,
            'transaction_fee' => 0,
            'final_amount' => 0,
        ]);
        VoucherRedemption::query()->create([
            'voucher_id' => $originalVoucher->id,
            'order_id' => $order->id,
            'amount' => 1000,
            'status' => 'reserved',
        ]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/orders/{$order->id}/voucher");

        $response->assertCreated();
        $response->assertJsonPath('restored_only', true);
        $response->assertJsonPath('voucher', null);
        $this->assertDatabaseCount('vouchers', 1); // only the original — no new row
        $this->assertSame(1000, $originalVoucher->fresh()->remaining);
        $this->assertSame('active', $originalVoucher->fresh()->status);
        $this->assertSame('restored', VoucherRedemption::query()->where('order_id', $order->id)->value('status'));
        // No ledger entry for a voucher that was never issued.
        $this->assertSame(0, app(LedgerService::class)->balance('platform', null));
    }

    /**
     * The click is safe to repeat — restore() is idempotent (no-op once
     * the redemption is already 'restored'), and no Voucher row exists
     * to trip the "already issued" guard either, so a second click must
     * not double-credit the original voucher or error out.
     */
    public function test_restore_only_action_is_idempotent_on_a_repeated_click(): void
    {
        $originalVoucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'KRS-FULLCOVER-2',
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 0,
            'status' => 'exhausted',
            'reason' => 'earlier compensation',
        ]);
        $order = $this->makeOrder([
            'voucher_id' => $originalVoucher->id,
            'voucher_discount' => 1000,
            'transaction_fee' => 0,
            'final_amount' => 0,
        ]);
        VoucherRedemption::query()->create([
            'voucher_id' => $originalVoucher->id,
            'order_id' => $order->id,
            'amount' => 1000,
            'status' => 'reserved',
        ]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $this->postJson("/api/orders/{$order->id}/voucher")->assertCreated();
        $second = $this->postJson("/api/orders/{$order->id}/voucher");

        $second->assertCreated();
        $second->assertJsonPath('restored_only', true);
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertSame(1000, $originalVoucher->fresh()->remaining); // not double-credited
    }

    /**
     * A full-cover order that never actually redeemed a voucher (edge
     * case — shouldn't happen given ADR-024 decision 5, but the amount
     * computation alone must never depend on a redemption existing) is
     * still a no-mint restore-only response, not an error.
     */
    public function test_zero_amount_order_with_no_prior_redemption_is_still_restore_only_with_no_error(): void
    {
        $order = $this->makeOrder(['transaction_fee' => 0, 'final_amount' => 0]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/orders/{$order->id}/voucher");

        $response->assertCreated();
        $response->assertJsonPath('restored_only', true);
        $this->assertDatabaseCount('vouchers', 0);
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
     * ADR-026 decision 4c still holds for the ordinary ambiguous case —
     * decision 9's carve-out is narrow (a real Delivered+Failed split),
     * not "any needs_review order". No legs at all is the ordinary
     * single-supplier needs_review this test represents.
     */
    public function test_ordinary_needs_review_order_still_cannot_issue_a_voucher(): void
    {
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/orders/{$order->id}/voucher");

        $response->assertUnprocessable();
        $this->assertDatabaseCount('vouchers', 0);
    }

    /** Decision 9's actual carve-out: a genuine partial-delivery combo order requires and accepts a custom amount. */
    public function test_partial_combo_delivery_order_can_issue_a_voucher_with_a_custom_amount(): void
    {
        $order = $this->makePartialComboOrder();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/orders/{$order->id}/voucher", ['amount' => 27500]);

        $response->assertCreated();
        $response->assertJsonPath('restored_only', false);
        $response->assertJsonPath('voucher.amount', 27500);
        $this->assertDatabaseHas('vouchers', ['order_id' => $order->id, 'amount' => 27500]);
    }

    /** Without an amount, a partial-delivery order has no auto-computed default — it's required. */
    public function test_partial_combo_delivery_order_requires_an_amount(): void
    {
        $order = $this->makePartialComboOrder();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/orders/{$order->id}/voucher");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['amount']);
        $this->assertDatabaseCount('vouchers', 0);
    }

    /** Decision 9's amount is admin-adjustable, but never past what the customer actually paid. */
    public function test_partial_combo_delivery_amount_cannot_exceed_final_amount(): void
    {
        $order = $this->makePartialComboOrder();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson("/api/orders/{$order->id}/voucher", ['amount' => $order->final_amount + 1]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['amount']);
        $this->assertDatabaseCount('vouchers', 0);
    }

    /**
     * ADR-024 decision #9 — the detail page's real data source.
     */
    public function test_show_returns_usage_history_and_stats(): void
    {
        $order = $this->makeOrder(['delivery_status' => DeliveryStatus::Delivered->value]);
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
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
            'affiliate_id' => $this->primaryAffiliate()->id,
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
            'affiliate_id' => $this->primaryAffiliate()->id,
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

    public function test_rejects_a_standalone_voucher_without_an_idempotency_key(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 1_000,
            'reason' => 'Goodwill credit',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('idempotency_key');
    }

    /**
     * ADR-035 — the actual scenario this guard exists for: a network
     * timeout retry (or a double-click) resubmits the identical
     * idempotency_key. Must return the same Voucher, not mint a second
     * one and double-debit the platform ledger.
     */
    public function test_replays_the_same_voucher_for_a_repeated_idempotency_key(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 1_000,
            'reason' => 'Goodwill credit',
            'idempotency_key' => $key,
        ]);
        $first->assertCreated();

        $second = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 1_000,
            'reason' => 'Goodwill credit',
            'idempotency_key' => $key,
        ]);
        $second->assertCreated();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('vouchers', 1);
        $this->assertSame(-1_000, app(LedgerService::class)->balance('platform', null));
    }

    public function test_a_different_idempotency_key_creates_a_genuinely_separate_voucher(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $first = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 1_000,
            'reason' => 'Goodwill credit',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $first->assertCreated();

        $second = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 1_000,
            'reason' => 'Goodwill credit (unrelated, same customer)',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $second->assertCreated();

        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('vouchers', 2);
        $this->assertSame(-2_000, app(LedgerService::class)->balance('platform', null));
    }

    public function test_a_preexisting_idempotency_key_replays_that_voucher_instead_of_erroring(): void
    {
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));
        Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'VC-EXISTING',
            'idempotency_key' => 'shared-key',
            'customer_email' => 'someone-else@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'Pre-existing voucher',
        ]);

        $response = $this->postJson('/api/vouchers', [
            'customer_email' => 'customer@example.com',
            'affiliate_id' => $this->primaryAffiliate()->id,
            'amount' => 1_000,
            'reason' => 'Goodwill credit',
            'idempotency_key' => 'shared-key',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('code', 'VC-EXISTING');
        $this->assertDatabaseCount('vouchers', 1);
    }

    private function makeVoucher(array $overrides = []): Voucher
    {
        return Voucher::query()->create(array_merge([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'VC-'.Str::upper(Str::random(8)),
            'customer_email' => 'customer@example.com',
            'amount' => 1_000,
            'remaining' => 1_000,
            'status' => 'active',
            'reason' => 'Test',
        ], $overrides));
    }

    /**
     * ADR-036 — the real scenario this exists for: a voucher-funded
     * order that itself failed left the customer holding two separate
     * codes (checkout can only ever apply one). Merging must sum
     * `remaining` (not `amount`), void both sources without deleting
     * them, and never write a second ledger debit — the liability is
     * already booked via the sources' own original issuance.
     */
    public function test_admin_can_merge_two_active_vouchers_for_the_same_customer(): void
    {
        $ledgerBefore = app(LedgerService::class)->balance('platform', null);
        $a = $this->makeVoucher(['amount' => 1_000, 'remaining' => 1_000]);
        $b = $this->makeVoucher(['amount' => 500, 'remaining' => 300]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers/merge', [
            'voucher_ids' => [$a->id, $b->id],
            'reason' => 'Consolidating two compensation vouchers',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('amount', 1_300);
        $response->assertJsonPath('remaining', 1_300);
        $response->assertJsonPath('status', 'active');
        $this->assertNotSame($a->code, $response->json('code'));

        $a->refresh();
        $b->refresh();
        $this->assertSame('merged', $a->status);
        $this->assertSame(0, $a->remaining);
        $this->assertSame('merged', $b->status);
        $this->assertSame(0, $b->remaining);

        $this->assertDatabaseHas('voucher_merges', [
            'source_voucher_id' => $a->id,
            'target_voucher_id' => $response->json('id'),
        ]);
        $this->assertDatabaseHas('voucher_merges', [
            'source_voucher_id' => $b->id,
            'target_voucher_id' => $response->json('id'),
        ]);

        // No second ledger debit — the liability was already booked
        // by each source's own original issuance.
        $this->assertSame($ledgerBefore, app(LedgerService::class)->balance('platform', null));
    }

    public function test_can_merge_more_than_two_vouchers_matched_by_phone_instead_of_email(): void
    {
        $a = $this->makeVoucher(['customer_email' => 'a@example.com', 'customer_phone' => '60123456789', 'remaining' => 100]);
        $b = $this->makeVoucher(['customer_email' => 'b@example.com', 'customer_phone' => '60123456789', 'remaining' => 200]);
        $c = $this->makeVoucher(['customer_email' => 'c@example.com', 'customer_phone' => '60123456789', 'remaining' => 300]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers/merge', [
            'voucher_ids' => [$a->id, $b->id, $c->id],
            'reason' => 'Same phone, different emails',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('remaining', 600);
    }

    public function test_rejects_merging_vouchers_belonging_to_different_customers(): void
    {
        $a = $this->makeVoucher(['customer_email' => 'a@example.com']);
        $b = $this->makeVoucher(['customer_email' => 'b@example.com']);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers/merge', [
            'voucher_ids' => [$a->id, $b->id],
            'reason' => 'Attempted cross-customer merge',
        ]);

        $response->assertUnprocessable();
        $a->refresh();
        $this->assertSame('active', $a->status);
    }

    /**
     * ADR-060 PR-4d, decision 5: a merge can only combine vouchers of
     * one storefront brand.
     */
    public function test_rejects_merging_vouchers_from_different_brands(): void
    {
        $otherBrand = Affiliate::query()->create(['business_name' => 'Acme Resell', 'markup_pct' => 5]);
        $a = $this->makeVoucher();
        $b = $this->makeVoucher(['affiliate_id' => $otherBrand->id]);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers/merge', [
            'voucher_ids' => [$a->id, $b->id],
            'reason' => 'Attempted cross-brand merge',
        ]);

        $response->assertUnprocessable();
        $this->assertSame('active', $a->fresh()->status);
        $this->assertSame('active', $b->fresh()->status);
    }

    public function test_rejects_merging_a_non_active_voucher(): void
    {
        $a = $this->makeVoucher();
        $b = $this->makeVoucher(['status' => 'revoked']);
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers/merge', [
            'voucher_ids' => [$a->id, $b->id],
            'reason' => 'Attempted merge with a revoked voucher',
        ]);

        $response->assertUnprocessable();
    }

    public function test_rejects_a_merge_with_fewer_than_two_vouchers(): void
    {
        $a = $this->makeVoucher();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers/merge', [
            'voucher_ids' => [$a->id],
            'reason' => 'Only one voucher selected',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('voucher_ids');
    }

    public function test_a_regular_admin_can_merge_vouchers_without_a_super_admin(): void
    {
        $a = $this->makeVoucher();
        $b = $this->makeVoucher();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/vouchers/merge', [
            'voucher_ids' => [$a->id, $b->id],
            'reason' => 'No maker-checker gate for merges',
        ]);

        $response->assertCreated();
    }

    public function test_a_merged_voucher_shows_its_source_provenance_on_the_detail_view(): void
    {
        $a = $this->makeVoucher();
        $b = $this->makeVoucher();
        Sanctum::actingAs(AdminUser::factory()->create(['role' => 'admin']));

        $merged = $this->postJson('/api/vouchers/merge', [
            'voucher_ids' => [$a->id, $b->id],
            'reason' => 'Provenance check',
        ]);

        $response = $this->getJson("/api/vouchers/{$merged->json('id')}");

        $response->assertOk();
        $response->assertJsonCount(2, 'voucher.merges_as_target');

        $sourceDetail = $this->getJson("/api/vouchers/{$a->id}");
        $sourceDetail->assertOk();
        $sourceDetail->assertJsonPath('voucher.merge_as_source.target_voucher_id', $merged->json('id'));
    }
}
