<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\Game;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AdminOrderControllerFixtures;
use Tests\TestCase;

/** This file: mark delivered / confirm failed on a needs-review order. */
class OrderControllerManualResolutionTest extends TestCase
{
    use AdminOrderControllerFixtures;
    use RefreshDatabase;

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
        //
        // ADR-106 decision 3: markDeliveredManually() now writes a real
        // attempt_type=manual_confirm row (the third gap this ADR
        // found) — resend_attempts is no longer empty for this action.
        $response->assertJsonCount(1, 'resend_attempts');
        $response->assertJsonPath('resend_attempts.0.attempt_type', 'manual_confirm');
        $response->assertJsonPath('resend_attempts.0.outcome', 'success');
        $response->assertJsonPath('resend_attempts.0.price_diff_sen', null);
        $response->assertJsonPath('resend_attempts.0.note', 'Confirmed via Gamevion dashboard, TARGET/SERVICE/date matched.');
        $this->assertNotNull($response->json('resend_attempts.0.triggered_by'));
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

    /**
     * ADR-094's 2026-09-21 addendum decision 24 — this action never had
     * any combo/leg awareness at all: force-setting the whole order
     * Delivered with one leg still genuinely outstanding permanently
     * orphaned that leg (Resend/Retry only render for order-level
     * Failed/NeedsReview, and the order would now be Delivered). Hard
     * blocked for any combo order regardless of leg mix.
     */
    public function test_mark_delivered_rejects_a_combo_order(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion-mark-delivered-combo-test', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia-mark-delivered-combo-test']);
        $delivered = Package::query()->create([
            'game_id' => $game->id, 'name' => '4810 Diamonds', 'denomination' => 4810,
            'cost_price' => 40000, 'standard_selling_price' => 44000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-4810',
        ]);
        $needsReview = Package::query()->create([
            'game_id' => $game->id, 'name' => '2976 Diamonds', 'denomination' => 2976,
            'cost_price' => 25000, 'standard_selling_price' => 27500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-2976',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => '7786 Diamonds (Combo)', 'is_combo' => true,
            'denomination' => 7786, 'cost_price' => 65000, 'standard_selling_price' => 71500,
        ]);
        $order = $this->order([
            'package_id' => $combo->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $delivered->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'GV-REF-1',
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $needsReview->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::NeedsReview->value, 'failure_reason' => 'Duplicate reference',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/mark-delivered", ['supplier_ref' => 'GV-1']);

        $response->assertUnprocessable();
        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
        // The outstanding leg was never touched by the rejected action.
        $this->assertSame(DeliveryStatus::NeedsReview, OrderDeliveryLeg::query()->where('leg_number', 2)->first()->status);
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

    /**
     * ADR-026 addendum (2026-09-16) — the exit decision 4c's own text
     * always assumed existed but was never built: retry can never
     * change a transactionAlreadyFormed order (ADR-098), so without
     * this, that class of order has no way to ever unlock Issue
     * Voucher.
     */
    public function test_confirm_failed_transitions_a_needs_review_order_to_failed(): void
    {
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Transaksi Gagal'],
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/confirm-failed", [
            'note' => 'Same rc=02 replayed 3x on the same reference — confirmed dead via request logs.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('delivery_status', 'failed');
        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
        $this->assertSame('02', $order->fresh()->supplier_response['error_code']);
        $response->assertJsonStructure(['game', 'package', 'supplier', 'affiliate', 'voucher']);
    }

    public function test_confirm_failed_requires_a_note(): void
    {
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/confirm-failed", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('note');
    }

    public function test_confirm_failed_rejects_an_order_that_is_not_needs_review(): void
    {
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::Failed->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/confirm-failed", ['note' => 'note']);

        $response->assertUnprocessable();
        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
    }

    public function test_confirm_failed_rejects_an_order_with_an_already_issued_voucher(): void
    {
        $this->actingAsAdmin();
        $order = $this->order([
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-CONFIRM-FAILED-GUARD',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/confirm-failed", ['note' => 'note']);

        $response->assertUnprocessable();
        $this->assertSame(DeliveryStatus::NeedsReview, $order->fresh()->delivery_status);
    }

    public function test_confirm_failed_404s_for_a_sandbox_order(): void
    {
        $order = $this->order([
            'is_test' => true,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);
        $this->actingAsAdmin();

        $this->postJson("/api/orders/{$order->id}/confirm-failed", ['note' => 'note'])->assertNotFound();
    }

    /**
     * ADR-094 decision 31 — confirming a combo failed flips its ambiguous
     * leg to Failed; with a Delivered leg it lands on PartiallyDelivered,
     * never a whole-order Failed (which would over-compensate the goods
     * already received). No compensation is issued by this step itself.
     */
    public function test_confirm_failed_on_a_combo_with_a_delivered_leg_lands_in_partially_delivered(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion-confirm-failed-needs-review-test', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia-confirm-failed-needs-review-test']);
        $delivered = Package::query()->create([
            'game_id' => $game->id, 'name' => '4810 Diamonds', 'denomination' => 4810,
            'cost_price' => 40000, 'standard_selling_price' => 44000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-4810',
        ]);
        $needsReview = Package::query()->create([
            'game_id' => $game->id, 'name' => '2976 Diamonds', 'denomination' => 2976,
            'cost_price' => 25000, 'standard_selling_price' => 27500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-2976',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => '7786 Diamonds (Combo)', 'is_combo' => true,
            'denomination' => 7786, 'cost_price' => 65000, 'standard_selling_price' => 71500,
        ]);
        $order = $this->order([
            'package_id' => $combo->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'final_amount' => 71500 + 90,
            'transaction_fee' => 90,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $delivered->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Delivered->value, 'supplier_reference' => 'GV-REF-1',
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $needsReview->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::NeedsReview->value, 'failure_reason' => 'Duplicate reference',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/confirm-failed", ['note' => 'note']);

        $response->assertOk();
        $response->assertJsonPath('delivery_status', 'partially_delivered');
        $response->assertJsonPath('compensable', true);
        $this->assertSame(DeliveryStatus::Failed, OrderDeliveryLeg::query()->where('order_id', $order->id)->where('leg_number', 2)->value('status'));
        $this->assertSame(0, Voucher::query()->where('order_id', $order->id)->count());
    }

    /**
     * Guards decision 25's own scoping so it doesn't over-block: a
     * Failed+NeedsReview mix with no Delivered leg at all is NOT
     * "partial" — nothing was delivered yet, so confirming the whole
     * order Failed (and a later full-amount voucher) is correct, same
     * as before this addendum.
     */
    public function test_confirm_failed_still_allows_a_combo_with_no_delivered_leg_at_all(): void
    {
        $this->actingAsAdmin();
        $supplier = Supplier::query()->create(['name' => 'Gamevion', 'slug' => 'gamevion-confirm-failed-no-delivered-test', 'api_config' => [], 'currency' => 'MYR']);
        $game = Game::query()->create(['name' => 'MLBB Malaysia', 'slug' => 'mlbb-malaysia-confirm-failed-no-delivered-test']);
        $failed = Package::query()->create([
            'game_id' => $game->id, 'name' => '4810 Diamonds', 'denomination' => 4810,
            'cost_price' => 40000, 'standard_selling_price' => 44000,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-4810',
        ]);
        $needsReview = Package::query()->create([
            'game_id' => $game->id, 'name' => '2976 Diamonds', 'denomination' => 2976,
            'cost_price' => 25000, 'standard_selling_price' => 27500,
            'supplier_id' => $supplier->id, 'supplier_package_ref' => 'GV-2976',
        ]);
        $combo = Package::query()->create([
            'game_id' => $game->id, 'name' => '7786 Diamonds (Combo)', 'is_combo' => true,
            'denomination' => 7786, 'cost_price' => 65000, 'standard_selling_price' => 71500,
        ]);
        $order = $this->order([
            'package_id' => $combo->id,
            'payment_status' => PaymentStatus::Paid->value,
            'delivery_status' => DeliveryStatus::NeedsReview->value,
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $failed->id, 'supplier_id' => $supplier->id,
            'leg_number' => 1, 'status' => DeliveryStatus::Failed->value, 'failure_reason' => 'Insufficient balance',
        ]);
        OrderDeliveryLeg::query()->create([
            'order_id' => $order->id, 'component_package_id' => $needsReview->id, 'supplier_id' => $supplier->id,
            'leg_number' => 2, 'status' => DeliveryStatus::NeedsReview->value, 'failure_reason' => 'Duplicate reference',
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/confirm-failed", ['note' => 'note']);

        $response->assertOk();
        $this->assertSame(DeliveryStatus::Failed, $order->fresh()->delivery_status);
    }
}
