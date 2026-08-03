<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ADR-022's newest addendum, decision 5 — CHIP's counterpart to
 * XenditWebhookController, not behind auth:sanctum for the same
 * reason (the signature check IS the authentication for this route).
 * Deliberately a separate, self-contained controller rather than a
 * shared base with XenditWebhookController: today the post-
 * verification order-lifecycle logic happens to be identical, but per
 * this project's own "don't prematurely abstract" convention
 * (AGENTS.md), duplicating ~30 lines of an already-small, well-tested
 * controller costs less than a shared abstraction that would have to
 * be un-done the first time one gateway's webhook handling needs to
 * diverge from the other's.
 *
 * Resolves `PaymentGatewayFactory::make('chip')` explicitly in the
 * constructor rather than the plain `PaymentGateway::class` default
 * binding — that default is deliberately pinned to Xendit
 * (AppServiceProvider's own doc comment), since a webhook route's
 * gateway is fixed by its URL, not resolved per-request.
 *
 * ADR-014: fulfillment is dispatched to FulfillOrderJob, never run
 * inline — a slow/hung Gamevion response must never hold this request
 * (or CHIP's own webhook-delivery timeout) hostage.
 */
class ChipWebhookController extends Controller
{
    private readonly PaymentGateway $paymentGateway;

    public function __construct(PaymentGatewayFactory $gatewayFactory)
    {
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

            return response()->json(['message' => 'acknowledged']);
        }

        $order->update(['payment_status' => PaymentStatus::Paid->value]);

        FulfillOrderJob::dispatch($order->fresh());

        return response()->json(['message' => 'ok']);
    }
}
