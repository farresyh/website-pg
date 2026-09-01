<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Voucher\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ADR-022's 2026-08-03 addendum, decision 5 — the payment webhook
 * entry point, not behind auth:sanctum (the signature check IS the
 * authentication for this route).
 *
 * Resolves `PaymentGatewayFactory::make('chip')` explicitly in the
 * constructor rather than the plain `PaymentGateway::class` default
 * binding — a webhook route's gateway is fixed by its URL, not
 * resolved per-request, so it names its gateway rather than leaning on
 * whichever one happens to be the current default (ADR-022's
 * 2026-09-01 addendum decision 2 — the seam stays even though CHIP is
 * the only gateway, so this stays explicit for the day it isn't).
 *
 * ADR-014: fulfillment is dispatched to FulfillOrderJob, never run
 * inline — a slow/hung Gamevion response must never hold this request
 * (or CHIP's own webhook-delivery timeout) hostage.
 */
class ChipWebhookController extends Controller
{
    private readonly PaymentGateway $paymentGateway;

    public function __construct(
        PaymentGatewayFactory $gatewayFactory,
        private readonly VoucherService $vouchers,
    ) {
        $this->paymentGateway = $gatewayFactory->make('chip');
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->paymentGateway->verifyWebhookSignature($request)) {
            Log::warning('Rejected CHIP webhook: invalid signature');

            return response()->json(['message' => 'invalid signature'], 401);
        }

        $event = $this->paymentGateway->parseWebhookEvent($request->all());

        $order = Order::query()->where('payment_ref', $event->paymentRequestId)->first();

        if ($order === null) {
            Log::warning('Rejected CHIP webhook: no order found', [
                'payment_request_id' => $event->paymentRequestId,
            ]);

            return response()->json(['message' => 'order not found'], 404);
        }

        Log::withContext([
            'order_number' => $order->order_number,
            'payment_request_id' => $event->paymentRequestId,
        ]);

        // PAY-2: CHIP can and does deliver the same event more than
        // once — if payment_status is already Paid, this is a repeat
        // delivery. Acknowledge without reprocessing.
        if ($order->payment_status === PaymentStatus::Paid) {
            return response()->json(['message' => 'already processed']);
        }

        if ($event->status !== PaymentStatus::Paid) {
            $order->update(['payment_status' => $event->status->value]);

            // ADR-024 decision #6a: a terminal Failed status (never a
            // merely intermediate Pending update) gives back any
            // reserved voucher redemption this order made — a no-op if
            // this order never used a voucher. Mirrored by
            // ReconcilePendingPaymentsCommand's own failure branch.
            if ($event->status === PaymentStatus::Failed) {
                $this->vouchers->restore($order->id);
            }

            return response()->json(['message' => 'acknowledged']);
        }

        // Defense-in-depth: payment_ref already binds this webhook to
        // one specific, fixed-amount purchase created by requestPayment()
        // (amountSen: $order->final_amount), and a signature-verified
        // paid status from CHIP already implies the full requested amount
        // was received — this is a second, independent check.
        if ($event->amountSen !== $order->final_amount) {
            Log::error('Rejected CHIP webhook: amount mismatch', [
                'expected_sen' => $order->final_amount,
                'received_sen' => $event->amountSen,
            ]);

            return response()->json(['message' => 'amount mismatch'], 409);
        }

        $order->update(['payment_status' => PaymentStatus::Paid->value, 'paid_at' => now()]);

        FulfillOrderJob::dispatch($order->fresh());

        return response()->json(['message' => 'ok']);
    }
}
