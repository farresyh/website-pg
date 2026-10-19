<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderStatusService;
use App\Services\Supplier\SupplierOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-125 decisions 8/28/36/42: every human confirmation of a manual
 * supplier's order. Delivered and Failed are callers of
 * OrderFulfillmentService::finalizePendingDelivery(), the one seam that
 * locks, captures the real cost, reconciles profit, credits the
 * affiliate, commits the voucher and records the drawdown. PR 2's bot
 * (`.done` / `.gagal`) will call the same two methods.
 *
 * The real cost is RM (MYR, rate 1.0), defaulting to the order's own
 * `cost_price` snapshot; any other value needs a reason (decision 36).
 * Each confirmation writes a `manual_confirm` attempt row naming who did
 * it (decision 42), since finalize only resolves an existing pending row.
 */
final class ManualDeliveryService
{
    public function __construct(
        private readonly OrderFulfillmentService $fulfillment,
        private readonly OrderStatusService $orderStatus,
    ) {}

    public function confirmDelivered(Order $order, ?int $realCostSen, ?string $reason, string $confirmedBy, string $channel = 'admin'): Order
    {
        $this->assertManual($order);
        $realCostSen = $this->realCost($order, $realCostSen, $reason);
        $response = $this->response($realCostSen, $reason, $confirmedBy, $channel);

        $finalized = $this->fulfillment->finalizePendingDelivery($order, SupplierOutcome::Success, supplierResponse: $response);
        $this->recordAttempt($finalized, 'success', $response, $reason, $confirmedBy);

        return $finalized;
    }

    public function confirmFailed(Order $order, string $reason, string $confirmedBy, string $channel = 'admin'): Order
    {
        $this->assertManual($order);
        $response = ['manual' => true, 'outcome' => 'failed', 'reason' => $reason, 'confirmed_by' => $confirmedBy, 'channel' => $channel];

        // A manual Failed is a confirmed non-delivery (decision 9), so it
        // lands on Failed, never NeedsReview.
        $finalized = $this->fulfillment->finalizePendingDelivery($order, SupplierOutcome::Failure, supplierResponse: $response, outcomeConfirmedFailed: true);
        $this->recordAttempt($finalized, 'failed', $response, $reason, $confirmedBy);

        return $finalized;
    }

    /**
     * Decision 42: a wrong Failed (an admin's click, or a supplier's
     * `.gagal` in PR 2) when the top-up did happen. Only before any
     * compensation: Failed wrote no ledger, voucher or drawdown entry, so
     * re-opening it to NeedsReview and finalizing as Delivered reverses
     * nothing. Under the order lock, so it can't race Issue Voucher or a
     * Retry, which both re-check the status under the same lock.
     */
    public function correctFailedToDelivered(Order $order, ?int $realCostSen, string $reason, string $confirmedBy): Order
    {
        $this->assertManual($order);

        return DB::transaction(function () use ($order, $realCostSen, $reason, $confirmedBy) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->delivery_status !== DeliveryStatus::Failed) {
                throw ValidationException::withMessages(['order' => ['Only a Failed order can be corrected to Delivered.']]);
            }

            if ($locked->isAlreadyCompensated()) {
                throw ValidationException::withMessages(['order' => ['This order was already compensated; it cannot be marked Delivered.']]);
            }

            $locked->update(['delivery_status' => $this->orderStatus->markNeedsReview($locked->delivery_status)->value]);

            $realCostSen = $this->realCost($locked, $realCostSen, $reason);
            $response = $this->response($realCostSen, $reason, $confirmedBy, 'admin') + ['corrected_from' => 'failed'];

            $finalized = $this->fulfillment->finalizePendingDelivery($locked, SupplierOutcome::Success, supplierResponse: $response);
            $this->recordAttempt($finalized, 'success', $response, "Corrected from Failed: {$reason}", $confirmedBy);

            return $finalized;
        });
    }

    private function assertManual(Order $order): void
    {
        $order->loadMissing('supplier');

        if ($order->supplier === null || $order->supplier->isApiBacked() || $order->is_test) {
            throw ValidationException::withMessages(['order' => ['Only a manual supplier\'s order is confirmed here.']]);
        }
    }

    /** Decision 36: default the agreed cost; anything else needs a reason. */
    private function realCost(Order $order, ?int $realCostSen, ?string $reason): int
    {
        $realCostSen ??= $order->cost_price;

        if ($realCostSen <= 0) {
            throw ValidationException::withMessages(['real_cost_sen' => ['The real cost must be more than zero.']]);
        }

        if ($realCostSen !== $order->cost_price && blank($reason)) {
            throw ValidationException::withMessages(['reason' => ['Give a reason when the real cost differs from the agreed cost.']]);
        }

        return $realCostSen;
    }

    /** @return array<string, mixed> */
    private function response(int $realCostSen, ?string $reason, string $confirmedBy, string $channel): array
    {
        // `price` in RM: captureRealCostSen() and the drawdown read it
        // through the MYR rate of 1.0, so it round-trips to the sen.
        return [
            'manual' => true,
            'outcome' => 'delivered',
            'price' => $realCostSen / 100,
            'reason' => $reason,
            'confirmed_by' => $confirmedBy,
            'channel' => $channel,
            'confirmed_at' => now()->toISOString(),
        ];
    }

    /** @param  array<string, mixed>  $response */
    private function recordAttempt(Order $order, string $outcome, array $response, ?string $note, string $confirmedBy): void
    {
        OrderResendAttempt::query()->create([
            'order_id' => $order->id,
            'attempt_type' => 'manual_confirm',
            'package_id' => $order->package_id,
            'cost_price_sen' => $order->cost_price,
            'standard_selling_price_sen' => $order->standard_selling_price,
            'price_diff_sen' => null,
            'outcome' => $outcome,
            'supplier_response' => $response,
            'note' => $note,
            'triggered_by' => $confirmedBy,
        ]);
    }
}
