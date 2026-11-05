<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\Supplier;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Supplier\Gamevion\GamevionAdapter;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Supplier\SupplierResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ADR-137 decision 9: Gamevion's `order.updated` webhook finalizes a
 * Pending order or combo leg, mirroring DigiflazzWebhookController. The
 * reconcile poll stays the backstop.
 *
 * Auth: `X-Gamevion-Signature: v1=<hex>` is HMAC-SHA256 of
 * `timestamp + "." + raw body` with the supplier's `webhook_secret`,
 * rejected when `X-Gamevion-Timestamp` is more than 5 minutes off.
 * Gamevion retries three times and may deliver twice; finalizing is
 * idempotent, and a 404 (our transaction not committed yet) is retried.
 * The status mapping is GamevionAdapter::responseFor(), shared with the
 * order calls.
 */
class GamevionWebhookController extends Controller
{
    private const MAX_SKEW_SECONDS = 300;

    public function __construct(
        private readonly OrderFulfillmentService $fulfillment,
        private readonly BackupFailureAlerter $alerter,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $supplier = Supplier::query()->where('slug', 'gamevion')->first();
        $secret = $supplier?->api_config['webhook_secret'] ?? null;

        if ($secret === null || $secret === '') {
            Log::warning('Rejected Gamevion webhook: no webhook_secret configured on the gamevion supplier');

            return response()->json(['message' => 'webhook not configured'], 503);
        }

        $timestamp = (string) $request->header('X-Gamevion-Timestamp', '');
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, (string) $request->header('X-Gamevion-Signature', ''))
            || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            Log::warning('Rejected Gamevion webhook: invalid signature or stale timestamp');

            return response()->json(['message' => 'invalid signature'], 401);
        }

        $order = $request->input('order');

        if ($request->input('event') !== 'order.updated' || ! is_array($order)) {
            return response()->json(['message' => 'ignored']);
        }

        $reference = $order['client_ref'] ?? null;
        $result = GamevionAdapter::responseFor($order);

        if ($reference === null) {
            return response()->json(['message' => 'bad request'], 400);
        }

        Log::withContext(['ref_id' => $reference, 'gamevion_status' => $order['order_status'] ?? null]);

        $plain = Order::query()->with('supplier')->where('reference_number', $reference)->first();
        $leg = $plain === null
            ? OrderDeliveryLeg::query()->with(['order', 'componentPackage.supplier'])->where('reference_number', $reference)->first()
            : null;

        if ($plain === null && $leg === null) {
            Log::info('Gamevion webhook: no order or combo leg yet for client_ref; a retry or the poll will finalize it');

            return response()->json(['message' => 'order not found'], 404);
        }

        $ownerSupplier = $plain?->supplier ?? $leg->componentPackage?->supplier;

        if ($ownerSupplier?->id !== $supplier->id) {
            Log::error('Rejected Gamevion webhook: the order belongs to another supplier', ['order_supplier' => $ownerSupplier?->slug]);

            return response()->json(['message' => 'supplier mismatch'], 409);
        }

        // Only a final answer finalizes: `pending`, or a status we do not know, waits for the next event or the poll.
        if ($result->outcome === SupplierOutcome::Pending
            || ($result->outcome === SupplierOutcome::Failure && ! $result->outcomeConfirmedFailed)) {
            return response()->json(['message' => 'acknowledged']);
        }

        $delivered = ($plain?->delivery_status ?? $leg->status) === DeliveryStatus::Delivered;

        try {
            $this->finalize($plain, $leg, $result);
        } catch (InvalidOrderTransitionException $e) {
            Log::info('Gamevion webhook: already finalized, ignoring', ['reason' => $e->getMessage()]);
        }

        // Decision 14: Gamevion refunded an order we delivered. Its state is
        // left alone (the customer may have the credit); a person decides.
        if ($delivered && ($order['order_status'] ?? null) === 'refunded') {
            $this->alerter->alert(
                'Gamevion refunded a delivered order',
                'Order '.($plain ?? $leg->order)->order_number." (client_ref {$reference}) was delivered, then refunded by Gamevion. Check the delivery with the customer and record the refund in the funding ledger by hand.",
                'Supplier refund',
            );
        }

        return response()->json(['message' => 'ok']);
    }

    private function finalize(?Order $plain, ?OrderDeliveryLeg $leg, SupplierResponse $result): void
    {
        $success = $result->outcome === SupplierOutcome::Success;
        $supplierRef = $success ? ($result->data['supplier_ref'] ?? null) : null;
        $evidence = $result->data ?? ['error_code' => $result->errorCode, 'error_message' => $result->errorMessage];

        $plain !== null
            ? $this->fulfillment->finalizePendingDelivery($plain, $result->outcome, $supplierRef, $evidence, outcomeConfirmedFailed: ! $success)
            : $this->fulfillment->finalizePendingDeliveryLeg($leg, $result->outcome, $supplierRef, $evidence, outcomeConfirmedFailed: ! $success);
    }
}
