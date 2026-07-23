<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
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
 */
class XenditWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $paymentGateway,
        private readonly OrderFulfillmentService $fulfillment,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $token = $request->header('x-callback-token', '');

        if (! $this->paymentGateway->verifyWebhookSignature($token)) {
            Log::warning('Rejected Xendit webhook: invalid callback token');

            return response()->json(['message' => 'invalid signature'], 401);
        }

        $event = $this->paymentGateway->parseWebhookEvent($request->all());

        $order = Order::query()->where('payment_ref', $event->paymentRequestId)->first();

        if ($order === null) {
            Log::warning("Rejected Xendit webhook: no order found for payment_request_id {$event->paymentRequestId}");

            return response()->json(['message' => 'order not found'], 404);
        }

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

            return response()->json(['message' => 'acknowledged']);
        }

        $order->update(['payment_status' => PaymentStatus::Paid->value]);

        try {
            $this->fulfillment->fulfill($order->fresh());
        } catch (InvalidOrderTransitionException $e) {
            // A concurrent webhook delivery already advanced this
            // order past NotStarted/Failed before we got the lock —
            // fulfill()'s own guard correctly rejected this attempt.
            Log::info("Fulfillment skipped for order {$order->id}: {$e->getMessage()}");
        }

        return response()->json(['message' => 'ok']);
    }
}
