<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\Game;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\Supplier;
use App\Models\Voucher;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Supplier\SupplierOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\OrderResendFixtures;
use Tests\TestCase;

/**
 * ADR-017: same-game package swap + live price reconciliation on top
 * of OrderFulfillmentService's own untouched fulfill() contract.
 *
 * This file: resend guards, the package swap, attempt rows and preflight.
 */
class OrderResendServiceTest extends TestCase
{
    use OrderResendFixtures;
    use RefreshDatabase;

    public function test_rejects_a_package_from_a_different_game(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $otherGame = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = $this->package($game, $supplier);
        $otherGamePackage = $this->package($otherGame, $supplier, ['name' => '5 Diamonds', 'supplier_package_ref' => 'B']);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $otherGamePackage, null, 'Admin');
    }

    public function test_rejects_an_inactive_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $inactivePackage = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'C', 'is_active' => false]);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $inactivePackage, null, 'Admin');
    }

    /**
     * ADR-094 decision 10: this package-swap tool assumes exactly one
     * supplier_product_ref to copy onto the Order — nonsensical for a
     * combo. Rejected on either side of the swap; the ordinary "Resend
     * Delivery" retry (no swap) stays the correct tool for a combo
     * order instead.
     */
    public function test_rejects_swapping_a_combo_order_to_a_different_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $comboOrderPackage = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true, 'is_active' => true,
            'denomination' => 100, 'cost_price' => 900, 'standard_selling_price' => 900, 'markup_percent' => 0,
        ]);
        $targetPackage = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $comboOrderPackage, $supplier, ['supplier_id' => null, 'supplier_product_ref' => null]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $targetPackage, null, 'Admin');
    }

    public function test_rejects_swapping_a_plain_order_to_a_combo_package(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $comboTarget = Package::query()->create([
            'game_id' => $game->id, 'name' => 'Combo', 'is_combo' => true, 'is_active' => true,
            'denomination' => 100, 'cost_price' => 900, 'standard_selling_price' => 900, 'markup_percent' => 0,
        ]);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $comboTarget, null, 'Admin');
    }

    public function test_rejects_an_order_that_is_not_currently_failed(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['delivery_status' => DeliveryStatus::Delivered->value]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $package, null, 'Admin');
    }

    /**
     * ADR-102 decision 1: before this ADR, `assertResendable()` never
     * checked compensation at all — only `OrderController::resend()`
     * did, at click time. This proves the actual attempt-time re-check
     * closes the TOCTOU gap that let a voucher get issued for an order
     * between the click and the job running.
     */
    public function test_rejects_an_order_with_an_already_issued_voucher(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);
        Voucher::query()->create([
            'order_id' => $order->id,
            'affiliate_id' => $order->affiliate_id,
            'code' => 'KRS-RESEND-GUARD',
            'customer_email' => 'buyer@example.com',
            'amount' => 500,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);

        $this->expectException(ValidationException::class);

        $this->service($this->fakeSupplierAdapter(true))->resend($order, $package, null, 'Admin');
    }

    /**
     * ADR-026 decision 4b: this job-level re-check (assertResendable())
     * is separate from OrderController::resend()'s own pre-check — both
     * must independently allow needs_review, or the controller could
     * accept the request while the actual queued job silently rejects
     * it.
     */
    public function test_allows_resend_from_a_needs_review_order(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier, ['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin');

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
    }

    /**
     * Decision #1: package_id/supplier_product_ref DO update — that's
     * the whole point of a package swap.
     */
    public function test_swaps_the_package_and_supplier_product_ref_on_success(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        // ADR-105: kept under the order's own standard_selling_price
        // (900) so this resend doesn't trip decision 4's below-cost
        // guard — this test isn't about that.
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 850, 'standard_selling_price' => 850]);
        $order = $this->failedOrder($game, $original, $supplier);

        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, null, 'Admin');

        $this->assertSame($swap->id, $result->package_id);
        $this->assertSame('D', $result->supplier_product_ref);
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
    }

    /**
     * Decision #3: price_diff_sen = this attempt's live cost_price
     * minus the order's original snapshotted cost_price — recorded in
     * both directions, success or failure (decision #3's "always
     * absorb, always record").
     */
    public function test_records_a_resend_attempt_with_the_live_price_reconciliation(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1200, 'standard_selling_price' => 1200]);
        // ADR-105: standard_selling_price (and selling_price, since the
        // negative-profit guard now reconciles against selling_price —
        // decision 8) raised above the swap's live cost (1200) so this
        // absorbed-cost-increase scenario doesn't also trip decision 4's
        // below-cost guard — this test is about the attempt row, not
        // that guard.
        $order = $this->failedOrder($game, $original, $supplier, ['standard_selling_price' => 1300, 'selling_price' => 1300]);

        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $swap, 'Customer requested a bigger pack', 'Admin User');

        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame($order->id, $attempt->order_id);
        $this->assertSame($swap->id, $attempt->package_id);
        $this->assertSame(1200, $attempt->cost_price_sen);
        $this->assertSame(1200, $attempt->standard_selling_price_sen);
        $this->assertSame(300, $attempt->price_diff_sen); // 1200 live - 900 original snapshot
        $this->assertSame('success', $attempt->outcome);
        $this->assertSame('Customer requested a bigger pack', $attempt->note);
        $this->assertSame('Admin User', $attempt->triggered_by);
    }

    public function test_records_a_failed_attempt_without_throwing(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $result = $this->service($this->fakeSupplierAdapter(false))->resend($order, $package, null, 'Admin');

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame('failed', $attempt->outcome);
    }

    /**
     * 2026-09-15 bugfix (Bug A, part 1): a genuinely still-Pending async
     * result (Digiflazz rc=03) is no longer coerced into a hard
     * 'failed' the moment the resend call returns.
     */
    public function test_records_a_pending_outcome_for_an_async_supplier_resend(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $result = $this->service($this->pendingSupplierAdapter(['status' => 'Pending']))->resend($order, $package, null, 'Admin');

        $this->assertSame(DeliveryStatus::Pending, $result->delivery_status);
        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame('pending', $attempt->outcome);
    }

    /**
     * 2026-09-15 bugfix (Bug A, part 2): the historical attempt row
     * self-corrects the moment the real async outcome resolves — via
     * finalizePendingDelivery(), the one shared path a webhook, the
     * scheduled reconcile poll, and ADR-096's manual "Check from
     * Supplier" button all funnel through.
     */
    public function test_a_pending_resend_attempt_self_corrects_to_success_once_the_order_finalizes(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->service($this->pendingSupplierAdapter(['status' => 'Pending']))->resend($order, $package, null, 'Admin');
        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame('pending', $attempt->outcome);

        $this->fulfillmentService()->finalizePendingDelivery(
            $order->fresh(),
            SupplierOutcome::Success,
            'GV-RESOLVED-LATER',
            ['status' => 'Sukses'],
        );

        $attempt->refresh();
        $this->assertSame('success', $attempt->outcome);
        $this->assertSame(['status' => 'Sukses'], $attempt->supplier_response);
    }

    /** Same self-correction, the Failure branch. */
    public function test_a_pending_resend_attempt_self_corrects_to_failed_once_the_order_finalizes(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->service($this->pendingSupplierAdapter(['status' => 'Pending']))->resend($order, $package, null, 'Admin');
        $attempt = OrderResendAttempt::query()->sole();

        $this->fulfillmentService()->finalizePendingDelivery(
            $order->fresh(),
            SupplierOutcome::Failure,
            null,
            ['status' => 'Gagal'],
        );

        $attempt->refresh();
        $this->assertSame('failed', $attempt->outcome);
        $this->assertSame(['status' => 'Gagal'], $attempt->supplier_response);
    }

    /**
     * A resend of a supplier that never went Pending at all (the
     * ordinary Gamevion sync case) must never accidentally "steal" an
     * unrelated older order's pending attempt — the lookup is scoped by
     * order_id.
     */
    public function test_finalizing_a_different_orders_pending_delivery_does_not_touch_this_orders_attempt(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        $this->service($this->pendingSupplierAdapter(['status' => 'Pending']))->resend($order, $package, null, 'Admin');
        $attempt = OrderResendAttempt::query()->sole();

        $otherOrder = $this->failedOrder($game, $package, $supplier, ['order_number' => 'KRS-RESEND-OTHER', 'delivery_status' => DeliveryStatus::Pending->value]);

        $this->fulfillmentService()->finalizePendingDelivery($otherOrder, SupplierOutcome::Success, 'GV-OTHER', ['status' => 'Sukses']);

        $attempt->refresh();
        $this->assertSame('pending', $attempt->outcome);
    }

    public function test_allows_resend_when_game_has_no_validator_profile_assigned(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $game->update(['player_validator_enabled' => true, 'player_validator_profile_id' => null]);
        $package = $this->package($game, $supplier);
        $order = $this->failedOrder($game, $package, $supplier);

        // Without a profile id, the gate never engages (matches
        // CheckoutController's own null-check) — resend proceeds.
        $result = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']))
            ->resend($order, $package, null, 'Admin');

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
    }

    /** ADR-105 2026-10-06 decision 12 — preflight() reports the impact and writes nothing. */
    public function test_preflight_reports_the_impact_without_writing_anything(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100]);
        $order = $this->failedOrder($game, $original, $supplier);

        $impact = $this->service($this->fakeSupplierAdapter(true))->preflight($order, $swap);

        $this->assertNull($impact->blockedReason);
        $this->assertSame(0, $impact->affiliateProfitSen);
        $this->assertSame(-100, $impact->platformProfitSen); // 1000 − 1100 − 0
        $this->assertSame(200, $impact->costDiffSen);
        $this->assertTrue($impact->overrideRequired());
        $this->assertSame(900, $order->fresh()->cost_price);
        $this->assertSame($original->id, $order->fresh()->package_id);
        $this->assertSame(0, OrderResendAttempt::query()->count());
    }

    /** Decision 12 — a hard guard becomes a blocked reason, not an exception, so the preview can show it. */
    public function test_preflight_reports_a_blocked_reason_for_a_package_from_another_game(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $otherGame = Game::query()->create(['name' => 'MLBB', 'slug' => 'mlbb']);
        $package = $this->package($game, $supplier);
        $foreign = $this->package($otherGame, $supplier, ['supplier_package_ref' => 'X']);
        $order = $this->failedOrder($game, $package, $supplier);

        $impact = $this->service($this->fakeSupplierAdapter(true))->preflight($order, $foreign);

        $this->assertSame('package_id', $impact->blockedField);
        $this->assertSame('The selected package must belong to the same game as this order.', $impact->blockedReason);
    }

    /** Decision 16 — a refused resend leaves an auditable row with its NOT NULL cost columns filled. */
    public function test_record_rejection_writes_a_rejected_attempt_row(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $swap = $this->package($game, $supplier, ['name' => '210 Diamonds', 'supplier_package_ref' => 'D', 'cost_price' => 1100, 'standard_selling_price' => 1300]);
        $order = $this->failedOrder($game, $original, $supplier);

        $this->service($this->fakeSupplierAdapter(true))->recordRejection($order, $swap, 'Live cost now exceeds what was collected.', 'Admin');

        $attempt = OrderResendAttempt::query()->sole();
        $this->assertSame('rejected', $attempt->outcome);
        $this->assertSame('resend', $attempt->attempt_type);
        $this->assertSame($swap->id, $attempt->package_id);
        $this->assertSame(1100, $attempt->cost_price_sen);
        $this->assertSame(1300, $attempt->standard_selling_price_sen);
        $this->assertSame(200, $attempt->price_diff_sen);
        $this->assertSame('Live cost now exceeds what was collected.', $attempt->note);
        $this->assertSame('Admin', $attempt->triggered_by);
    }

    /** Decision 13 — price_diff_sen is relative to the cost assigned before this attempt. */
    public function test_a_second_resend_diffs_against_the_previous_assignment(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $first = $this->package($game, $supplier, ['name' => 'First', 'supplier_package_ref' => 'F', 'cost_price' => 920]);
        $second = $this->package($game, $supplier, ['name' => 'Second', 'supplier_package_ref' => 'G', 'cost_price' => 950]);
        $order = $this->failedOrder($game, $original, $supplier);

        $this->service($this->fakeSupplierAdapter(false))->resend($order, $first, null, 'Admin');
        $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-2']))->resend($order->fresh(), $second, null, 'Admin');

        $diffs = OrderResendAttempt::query()->orderBy('id')->pluck('price_diff_sen')->all();
        $this->assertSame([20, 30], $diffs);
        $this->assertSame(950, $order->fresh()->cost_price);
    }

    /**
     * ADR-105 2026-10-06 decision 14, build-time correction — the window
     * the lock cannot close: B's locked write lands after A commits but
     * before A's fulfill() phase 1. A then delivers B's package; A's row
     * must name what was actually sent, and B's later attempt is refused
     * with a `rejected` row. Exactly one delivery, ledger = order row.
     */
    public function test_a_write_landing_between_as_commit_and_as_fulfill_is_recorded_as_what_was_sent(): void
    {
        $supplier = $this->supplier();
        $game = $this->game();
        $original = $this->package($game, $supplier);
        $packageA = $this->package($game, $supplier, ['name' => 'A', 'supplier_package_ref' => 'PA', 'cost_price' => 920]);
        $packageB = $this->package($game, $supplier, ['name' => 'B', 'supplier_package_ref' => 'PB', 'cost_price' => 950, 'standard_selling_price' => 990]);
        $order = $this->failedOrder($game, $original, $supplier);
        $service = $this->service($this->fakeSupplierAdapter(true, ['supplier_ref' => 'GV-1']));

        // B's locked write, landing in the window right after A commits.
        $fired = false;
        Order::updated(function (Order $updated) use (&$fired, $service, $packageB) {
            if ($fired || $updated->package_id === $packageB->id) {
                return;
            }
            $fired = true;
            DB::afterCommit(function () use ($service, $updated, $packageB) {
                $impact = $service->preflight($updated->fresh(), $packageB);
                DB::table('orders')->where('id', $updated->id)->update([
                    'package_id' => $packageB->id,
                    'supplier_product_ref' => $packageB->supplier_package_ref,
                    'cost_price' => $impact->costPriceSen,
                    'platform_profit' => $impact->platformProfitSen,
                ]);
            });
        });

        $result = $service->resend($order, $packageA, null, 'Admin A');

        $this->assertSame($packageB->id, $result->package_id);
        $this->assertSame('PB', $result->supplier_product_ref);
        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $rowA = OrderResendAttempt::query()->sole();
        $this->assertSame($packageB->id, $rowA->package_id);
        $this->assertSame(950, $rowA->cost_price_sen);
        $this->assertSame(990, $rowA->standard_selling_price_sen);
        $this->assertSame(50, $result->platform_profit); // 1000 − 950 − 0
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));

        // B's own job: fulfill() would throw on the Delivered order; its
        // retry re-runs preflight under the lock and is refused.
        try {
            $service->resend($order->fresh(), $packageB, null, 'Admin B');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $service->recordRejection($order->fresh(), $packageB, $e->getMessage(), 'Admin B');
        }

        $this->assertSame(['success', 'rejected'], OrderResendAttempt::query()->orderBy('id')->pluck('outcome')->all());
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
    }
}
