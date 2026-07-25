<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Fulfillment\OrderFulfillmentService;
use App\Services\Order\InvalidOrderTransitionException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ADR-014: moves OrderFulfillmentService::fulfill() off the Xendit
 * webhook request thread. The service's own interface and its
 * lockForUpdate() guard (2026-07-24 security review) are untouched —
 * only where it's called from changes, so a duplicate webhook
 * delivery still can't produce two supplier orders for one payment.
 */
final class FulfillOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Coarser than GamevionAdapter/XenditGateway's own sub-second HTTP
     * retry (TransientFailureRetryPolicy — 200ms/500ms/1s, exhausted
     * before this job-level attempt even ends): this layer exists for
     * an outage that outlasts that short window (a real
     * ConnectionException still propagates out of fulfill() once HTTP
     * retries are exhausted — see GamevionAdapter::client()'s
     * throw:false note). A business-level failure (4xx, e.g. a
     * genuinely invalid product code) never throws at all — fulfill()
     * returns normally with delivery_status=failed — so it is not
     * retried here; it lands directly on ORD-7's manual retry action.
     */
    public $tries = 3;

    public function __construct(
        public readonly Order $order,
    ) {
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(OrderFulfillmentService $fulfillment): void
    {
        try {
            $fulfillment->fulfill($this->order);
        } catch (InvalidOrderTransitionException $e) {
            // A concurrent webhook delivery already advanced this
            // order past NotStarted/Failed before this job got the
            // lock — fulfill()'s own guard correctly rejected this
            // attempt. Expected outcome, not a job failure.
            Log::info("Fulfillment skipped for order {$this->order->id}: {$e->getMessage()}");
        }
    }

    /**
     * All $tries exhausted on a genuine (likely connection-level)
     * failure — surfaces in `failed_jobs` for operator visibility,
     * distinct from a business-level delivery_status=failed (which
     * never reaches here, see handle()'s own note).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("FulfillOrderJob exhausted all retries for order {$this->order->id}: {$exception->getMessage()}");
    }
}
