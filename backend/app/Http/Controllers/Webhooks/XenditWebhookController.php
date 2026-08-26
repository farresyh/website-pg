<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Voucher\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PAY-1/PAY-2: not behind auth:sanctum — Xendit isn't an admin user.
 * The x-callback-token comparison IS the authentication mechanism for
 * this endpoint. An unverified callback is logged and discarded, never
 * actioned (PAY-1) — this is the single entry point that may ever
 * transition payment_status to Paid based on external input, so it is
 * treated as the most security-sensitive boundary in the system.
 *
 * ADR-014: deliberately thin — fulfillment (the part that calls
 * Gamevion's live API) is dispatched to FulfillOrderJob rather than
 * run inline, so a slow/hung supplier response can never hold this
 * request (and Xendit's own webhook-delivery timeout) hostage.
 */
class XenditWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $paymentGateway,
        private readonly VoucherService $vouchers,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->paymentGateway->verifyWebhookSignature($request)) {
            Log::warning('Rejected Xendit webhook: invalid callback token');

            return response()->json(['message' => 'invalid signature'], 401);
        }

        $event = $this->paymentGateway->parseWebhookEvent($request->all());

        $order = Order::query()->where('payment_ref', $event->paymentRequestId)->first();

        if ($order === null) {
            Log::warning('Rejected Xendit webhook: no order found', [
                'payment_request_id' => $event->paymentRequestId,
            ]);

            return response()->json(['message' => 'order not found'], 404);
        }

        // ADR-014: every subsequent log line in this request carries
        // order_number, so a support/ops grep finds the whole webhook
        // story without hand-parsing free-text messages. FulfillOrderJob
        // sets its own context independently (queue workers run in a
        // separate process — this context does not cross that
        // boundary), using the same key so the two are still
        // correlatable by grepping for one order_number.
        Log::withContext([
            'order_number' => $order->order_number,
            'payment_request_id' => $event->paymentRequestId,
        ]);

        // PAY-2: Xendit can and does deliver the same event more than
        // once — if payment_status is already Paid, this is a repeat
        // delivery. Acknowledge without reprocessing rather than
        // re-running fulfillment (which would additionally be rejected
        // by fulfill()'s own lock/guard if it somehow got this far).
        if ($order->payment_status === PaymentStatus::Paid) {
            return response()->json(['message' => 'already processed']);
        }

        if ($event->status !== PaymentStatus::Paid) {
            $order->update(['payment_status' => $event->status->value]);

            // ADR-024 decision #6a: a terminal Failed status (never a
            // merely intermediate Pending update some gateways also
            // send) is one of the two real restore triggers — gives
            // back any reserved voucher redemption this order made. A
            // no-op if this order never used a voucher.
            if ($event->status === PaymentStatus::Failed) {
                $this->vouchers->restore($order->id);
            }

            return response()->json(['message' => 'acknowledged']);
        }

        // Defense-in-depth: payment_ref already binds this webhook to
        // one specific, fixed-amount payment request created by
        // requestPayment() (amountSen: $order->final_amount), and a
        // signature-verified SUCCEEDED status from Xendit already
        // implies the full requested amount was received — this is a
        // second, independent check, not the only thing standing
        // between an underpayment and free fulfillment.
        if ($event->amountSen !== $order->final_amount) {
            Log::error('Rejected Xendit webhook: amount mismatch', [
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
