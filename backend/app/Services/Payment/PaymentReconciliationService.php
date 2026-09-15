<?php

namespace App\Services\Payment;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Order\PaymentStatus;
use App\Services\Voucher\VoucherService;
use Illuminate\Support\Facades\Log;

/**
 * ADR-096 decision 7: the shared body behind both
 * `ReconcilePendingPaymentsCommand` (ADR-021/PAY-3's scheduled sweep —
 * unchanged behaviour, now a thin wrapper) and the new admin "Check
 * from Gateway" manual-poll action (`Admin\OrderController::checkGateway()`)
 * — one gateway-status-check + recover/fail implementation for both
 * callers, extracted out of the command's own previously-private
 * methods so neither path can silently drift from the other.
 *
 * Resolution is terminal-status-driven, not time-driven — acts on the
 * gateway's own answer (`PaymentResponse::$status`, a typed
 * PaymentStatus, never a raw gateway-specific string), same discipline
 * as ReconcilePendingPaymentsCommand's own original docblock.
 */
final class PaymentReconciliationService
{
    public function __construct(
        private readonly PaymentGatewayFactory $gatewayFactory,
        private readonly VoucherService $vouchers,
    ) {}

    /**
     * `lookup_succeeded` distinguishes "the gateway call itself failed"
     * (network/auth error — `flagIfStale()` doesn't apply) from "the
     * gateway answered but the status is still ambiguous" (Pending or
     * an unmapped null — the case ReconcilePendingPaymentsCommand's own
     * stale-pending sweep flags for review via flagIfStale()).
     *
     * @return array{outcome: ?string, applied: bool, lookup_succeeded: bool, data: mixed, error_code: ?string, error_message: ?string}
     */
    public function reconcileOrder(Order $order): array
    {
        Log::withContext(['order_number' => $order->order_number, 'payment_request_id' => $order->payment_ref]);

        if ($order->payment_gateway === null) {
            Log::error('Payment reconciliation: order has no payment_gateway recorded — checkout stamping bug, investigate CheckoutService', [
                'order_number' => $order->order_number,
                'payment_request_id' => $order->payment_ref,
            ]);

            return ['outcome' => null, 'applied' => false, 'lookup_succeeded' => false, 'data' => null, 'error_code' => 'no_payment_gateway', 'error_message' => 'Order has no payment_gateway recorded.'];
        }

        $gateway = $this->gatewayFactory->make($order->payment_gateway);

        $payment = $gateway->getPayment($order->payment_ref);

        if (! $payment->success) {
            Log::warning('Payment reconciliation: gateway lookup failed', [
                'error_code' => $payment->errorCode,
                'error_message' => $payment->errorMessage,
            ]);

            return ['outcome' => null, 'applied' => false, 'lookup_succeeded' => false, 'data' => $payment->data, 'error_code' => $payment->errorCode, 'error_message' => $payment->errorMessage];
        }

        $applied = match ($payment->status) {
            PaymentStatus::Paid => $this->recover($order, $payment),
            PaymentStatus::Failed => $this->markFailed($order),
            // PaymentStatus::Pending, or null (a gateway that somehow
            // didn't set it) — both genuinely ambiguous, never guessed at.
            default => false,
        };

        return [
            'outcome' => $payment->status?->value,
            'applied' => $applied,
            'lookup_succeeded' => true,
            'data' => $payment->data,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    /**
     * ADR-021 flag-for-review sweep — only meaningful for the scheduled
     * command's own stale-pending pass, not the manual button (an admin
     * clicking "Check from Gateway" already knows the order is old).
     */
    public function flagIfStale(Order $order, int $flagAfterHours, ?string $status): void
    {
        if ($order->created_at->lte(now()->subHours($flagAfterHours))) {
            Log::warning('Payment reconciliation: order flagged for review — no terminal answer from gateway', [
                'gateway_status' => $status,
                'age_hours' => $order->created_at->diffInHours(now()),
            ]);
        }
    }

    /**
     * ADR-024 decision #6a — the second of the two paths (alongside
     * the webhook's own terminal-Failed branch) that can catch a
     * customer who applied a voucher then abandoned the gateway page
     * entirely, without even the webhook ever firing. restore() is a
     * no-op if this order never used a voucher.
     */
    private function markFailed(Order $order): bool
    {
        $order->update(['payment_status' => PaymentStatus::Failed->value]);

        $this->vouchers->restore($order->id);

        return true;
    }

    /**
     * Mirrors ChipWebhookController's own success branch exactly —
     * same PAY-2 duplicate-processing guard (a late webhook could have
     * already flipped this order to Paid and dispatched fulfillment
     * before this run got to it).
     */
    private function recover(Order $order, PaymentResponse $payment): bool
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            return false;
        }

        // Defense-in-depth — same amount cross-check as the webhook
        // controllers' own Paid branch. This pull-based path already
        // asks the gateway directly (harder to forge than a webhook
        // delivery), but a mismatch here still signals something is
        // wrong enough to not silently fulfill for free.
        $amountSen = is_array($payment->data) ? ($payment->data['amount_sen'] ?? null) : null;

        if ($amountSen !== $order->final_amount) {
            Log::error('Payment reconciliation: amount mismatch, refusing to mark paid', [
                'expected_sen' => $order->final_amount,
                'received_sen' => $amountSen,
            ]);

            return false;
        }

        $order->update(['payment_status' => PaymentStatus::Paid->value, 'paid_at' => now()]);

        FulfillOrderJob::dispatch($order->fresh());

        Log::info('Payment reconciliation: recovered a stuck-pending order');

        return true;
    }
}
