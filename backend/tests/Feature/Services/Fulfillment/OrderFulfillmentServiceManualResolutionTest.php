<?php

namespace Tests\Feature\Services\Fulfillment;

use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Accounting\SupplierLedgerEntryType;
use App\Services\Fulfillment\OrderFulfillmentException;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\OrderStatusService;
use App\Services\Order\PaymentStatus;
use App\Services\Supplier\SupplierOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OrderFulfillmentFixtures;
use Tests\TestCase;

/** This file: mark delivered, confirm failed and finalizing a pending delivery. */
class OrderFulfillmentServiceManualResolutionTest extends TestCase
{
    use OrderFulfillmentFixtures;
    use RefreshDatabase;

    public function test_mark_delivered_manually_transitions_from_needs_review_and_sets_supplier_ref(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->markDeliveredManually($order, 'GV-RAPI-MANUAL1', 'confirmed via Gamevion dashboard', 'Jane Admin');

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame('GV-RAPI-MANUAL1', $result->supplier_ref);
        $this->assertTrue($result->supplier_response['manually_confirmed']);
        $this->assertSame('Jane Admin', $result->supplier_response['confirmed_by']);
        $this->assertSame('confirmed via Gamevion dashboard', $result->supplier_response['note']);
        $this->assertNotNull($result->delivered_at);
    }

    public function test_mark_delivered_manually_credits_ledger_profit(): void
    {
        $order = $this->paidOrder([
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'platform_profit' => 150,
            'affiliate_profit' => 50,
        ]);

        $this->service($this->fakeSupplierAdapter(true))
            ->markDeliveredManually($order, 'GV-RAPI-MANUAL2', null, 'Jane Admin');

        $this->assertSame(150, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'affiliate')->sum('amount'));
    }

    /**
     * ADR-106 decision 3 — the third gap this ADR found:
     * markDeliveredManually() overwrites Order.supplier_response/
     * delivery_status via a third code path with no order_resend_attempts
     * row of its own before now. outcome is always 'success' (this
     * method exists only to confirm a delivery), note/triggered_by come
     * from the call's own params, price_diff_sen is null (no comparison
     * was made).
     */
    public function test_mark_delivered_manually_writes_a_durable_manual_confirm_attempt_row(): void
    {
        $order = $this->paidOrder([
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'cost_price' => 900, 'standard_selling_price' => 950,
        ]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->markDeliveredManually($order, 'GV-RAPI-MANUAL3', 'TARGET/SERVICE matched', 'Jane Admin');

        $attempt = OrderResendAttempt::query()->where('order_id', $result->id)->sole();
        $this->assertSame('manual_confirm', $attempt->attempt_type);
        $this->assertSame('success', $attempt->outcome);
        $this->assertSame($order->package_id, $attempt->package_id);
        $this->assertSame(900, $attempt->cost_price_sen);
        $this->assertSame(950, $attempt->standard_selling_price_sen);
        $this->assertNull($attempt->price_diff_sen);
        $this->assertSame('TARGET/SERVICE matched', $attempt->note);
        $this->assertSame('Jane Admin', $attempt->triggered_by);
        $this->assertTrue($attempt->supplier_response['manually_confirmed']);
    }

    /**
     * ADR-026 decision 4a: deliberately only reachable from
     * needs_review — no other state lets an admin's own claim
     * substitute for a real supplier confirmation.
     */
    public function test_mark_delivered_manually_rejects_when_not_needs_review(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->expectException(InvalidOrderTransitionException::class);

        $this->service($this->fakeSupplierAdapter(true))
            ->markDeliveredManually($order, 'GV-RAPI-MANUAL3', null, 'Jane Admin');
    }

    /**
     * ADR-026 addendum (2026-09-16, found shipping ADR-098) — the exit
     * that lets a genuinely-unresolvable needs_review order (a
     * transactionAlreadyFormed Digiflazz rc, retry can never change it)
     * reach Failed, unlocking Issue Voucher (Failed-only gate,
     * unchanged). Preserves the original error_code/message instead of
     * overwriting it — that's the actual evidence this failed.
     */
    public function test_confirm_delivery_failed_transitions_from_needs_review_and_preserves_original_error(): void
    {
        $order = $this->paidOrder([
            'delivery_status' => DeliveryStatus::NeedsReview->value,
            'supplier_response' => ['error_code' => '02', 'error_message' => 'Transaksi Gagal'],
        ]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->confirmDeliveryFailed($order, 'Confirmed dead via request logs — same rc replayed 3x.', 'Jane Admin');

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $this->assertSame('02', $result->supplier_response['error_code']);
        $this->assertSame('Transaksi Gagal', $result->supplier_response['error_message']);
        $this->assertSame('Jane Admin', $result->supplier_response['confirmed_failed_by']);
        $this->assertSame('Confirmed dead via request logs — same rc replayed 3x.', $result->supplier_response['note']);
    }

    public function test_confirm_delivery_failed_rejects_when_not_needs_review(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Failed->value]);

        $this->expectException(InvalidOrderTransitionException::class);

        $this->service($this->fakeSupplierAdapter(true))
            ->confirmDeliveryFailed($order, 'note', 'Jane Admin');
    }

    /** Unblocks the whole point of this action: Issue Voucher works once the order is genuinely Failed. */
    public function test_confirm_delivery_failed_then_unblocks_voucher_issuance(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::NeedsReview->value]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->confirmDeliveryFailed($order, 'note', 'Jane Admin');

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $this->assertSame(0, Voucher::query()->where('order_id', $order->id)->count());
    }

    /**
     * ADR-032 decision 3: the webhook/poll-driven money path out of
     * Pending — reached via createOrder()'s async acceptance, not a
     * synchronous fulfill() call.
     */
    public function test_finalize_pending_delivery_marks_delivered_and_credits_ledger_on_success(): void
    {
        $order = $this->paidOrder([
            'delivery_status' => DeliveryStatus::Pending->value,
            'platform_profit' => 150,
            'affiliate_profit' => 50,
        ]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Success, 'DGFLZ-FINAL-1', ['status' => 'Sukses']);

        $this->assertSame(DeliveryStatus::Delivered, $result->delivery_status);
        $this->assertSame('DGFLZ-FINAL-1', $result->supplier_ref);
        $this->assertSame(['status' => 'Sukses'], $result->supplier_response);
        $this->assertSame(150, (int) LedgerEntry::query()->where('owner_type', 'platform')->sum('amount'));
        $this->assertSame(50, (int) LedgerEntry::query()->where('owner_type', 'affiliate')->sum('amount'));
        $this->assertNotNull($result->delivered_at);
    }

    /**
     * ADR-106's own "Consequence to track" — resolvePendingResendAttempt()
     * was verified to be entirely type-agnostic (no attempt_type filter),
     * but this is a genuinely new code path worth its own explicit case:
     * a first-ever attempt (not a resend) that lands on Digiflazz's async
     * Pending, later resolved by a webhook/poll through the SAME
     * finalizePendingDelivery() every other caller shares. The durable
     * 'initial' row — not a 'resend' one — must self-correct from
     * outcome=pending to the real resolved outcome.
     */
    public function test_finalize_pending_delivery_resolves_an_initial_attempts_pending_row(): void
    {
        $order = $this->paidOrder();
        $pending = $this->service($this->fakePendingSupplierAdapter(['trx_id' => 'DGFLZ-1']))->fulfill($order);

        $initialAttempt = OrderResendAttempt::query()->where('order_id', $pending->id)->sole();
        $this->assertSame('initial', $initialAttempt->attempt_type);
        $this->assertSame('pending', $initialAttempt->outcome);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($pending->fresh(), SupplierOutcome::Success, 'DGFLZ-FINAL-1', ['status' => 'Sukses']);

        // Still exactly one row — resolvePendingResendAttempt() UPDATEs
        // the existing 'initial' row in place, never inserts a second one.
        $attempt = OrderResendAttempt::query()->where('order_id', $pending->id)->sole();
        $this->assertSame('initial', $attempt->attempt_type);
        $this->assertSame('success', $attempt->outcome);
        $this->assertSame(['status' => 'Sukses'], $attempt->supplier_response);
    }

    /**
     * ADR-032 decision 7: a Pending order finalized as Failed lands on
     * the same Failed state a synchronous rejection would — no ledger
     * effect, and the existing Failed-only voucher gate applies
     * unchanged (no separate double-compensation guard needed here).
     */
    public function test_finalize_pending_delivery_marks_failed_and_skips_ledger_on_failure(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Pending->value]);

        $result = $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Failure, null, ['status' => 'Gagal']);

        $this->assertSame(DeliveryStatus::Failed, $result->delivery_status);
        $this->assertSame(['status' => 'Gagal'], $result->supplier_response);
        $this->assertSame(PaymentStatus::Paid, $result->payment_status);
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_finalize_pending_delivery_commits_a_reserved_voucher_redemption_on_success(): void
    {
        $voucher = Voucher::query()->create([
            'affiliate_id' => $this->primaryAffiliate()->id,
            'code' => 'VC-TESTPENDINGCOMMIT',
            'customer_email' => 'buyer@example.com',
            'amount' => 1000,
            'remaining' => 500,
            'status' => 'active',
            'reason' => 'test',
        ]);
        $order = $this->paidOrder([
            'delivery_status' => DeliveryStatus::Pending->value,
            'voucher_id' => $voucher->id,
            'voucher_discount' => 500,
        ]);
        VoucherRedemption::query()->create([
            'voucher_id' => $voucher->id,
            'order_id' => $order->id,
            'amount' => 500,
            'status' => 'reserved',
        ]);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Success, 'DGFLZ-FINAL-2');

        $this->assertSame('committed', VoucherRedemption::query()->where('order_id', $order->id)->value('status'));
    }

    /**
     * Idempotency: a second finalize (duplicate webhook delivery, or a
     * webhook racing the poll) observes the already-advanced state and
     * is rejected — OrderStatusService's own guard, not a re-check
     * this method has to duplicate.
     */
    public function test_finalize_pending_delivery_rejects_when_not_pending(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Delivered->value]);

        $this->expectException(InvalidOrderTransitionException::class);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Success, 'DGFLZ-FINAL-3');
    }

    public function test_finalize_pending_delivery_rejects_being_called_with_outcome_pending(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Pending->value]);

        $this->expectException(OrderFulfillmentException::class);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Pending);
    }

    /**
     * ADR-083 decision 3: the Digiflazz webhook (or the reconcile poll's
     * own checkStatus() re-submit) is where a Pending order's real
     * `price` first becomes known — this is the only place its drawdown
     * gets recorded.
     */
    public function test_finalize_pending_delivery_records_a_supplier_ledger_drawdown_on_success(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Pending->value]);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Success, 'DGFLZ-DRAWDOWN-1', ['status' => 'Sukses', 'price' => 15000.0]);

        $entry = SupplierLedgerEntry::query()->sole();
        $this->assertSame(SupplierLedgerEntryType::OrderDrawdown->value, $entry->type);
        $this->assertSame('-15000.0000', $entry->amount);
        $this->assertSame('order', $entry->reference_type);
        $this->assertSame($order->id, $entry->reference_id);
    }

    /**
     * Grilled 2026-09-11 (ADR-083): a Gagal after Pending writes NO
     * REFUND — a Pending response never carried a price, so nothing was
     * ever recorded as drawn down for it in the first place.
     */
    public function test_finalize_pending_delivery_records_no_supplier_ledger_entry_on_failure(): void
    {
        $order = $this->paidOrder(['delivery_status' => DeliveryStatus::Pending->value]);

        $this->service($this->fakeSupplierAdapter(true))
            ->finalizePendingDelivery($order, SupplierOutcome::Failure, null, ['status' => 'Gagal', 'buyer_last_saldo' => 500000]);

        $this->assertSame(0, SupplierLedgerEntry::query()->count());
    }
}
