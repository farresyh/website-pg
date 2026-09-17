<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Package;
use App\Services\Fulfillment\OrderResendService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ADR-017 / ADR-014 discipline: same reason FulfillOrderJob exists —
 * the admin panel must never block on a live Gamevion call. Live
 * package values (decision #3's reconciliation) are read fresh
 * inside handle(), at actual attempt time, not stale-captured from
 * when the admin submitted the request.
 */
final class ResendOrderDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Same retry/backoff shape as FulfillOrderJob — a connection-level failure, not a business one (see handle()'s own note). */
    public $tries = 3;

    public function __construct(
        public readonly Order $order,
        public readonly int $packageId,
        public readonly ?string $note,
        public readonly ?string $triggeredBy,
        // ADR-102 decision 10 — the optional Player ID/Server ID
        // correction, carried through the queue same as every other
        // resend field.
        public readonly ?string $playerId = null,
        public readonly ?string $serverId = null,
        // ADR-105 decision 4 — required only when this attempt's live
        // cost would sell below what the customer already paid;
        // `OrderController::guardResendUnsafeOverride()` already
        // validated it's non-empty by the time this reaches here, for
        // any request that actually needed it.
        public readonly ?string $overrideReason = null,
    ) {
        // ADR-020 decision #5 — same queue as FulfillOrderJob, same
        // reasoning. onQueue(), not a redeclared $queue property — see
        // FulfillOrderJob's own constructor for why.
        $this->onQueue('orders');
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(OrderResendService $resend): void
    {
        Log::withContext(['order_number' => $this->order->order_number]);

        $package = Package::query()->find($this->packageId);

        if ($package === null) {
            // Deleted between the admin's request and this job
            // running — genuinely unrecoverable, not a transient
            // failure, so log and stop rather than retry into the
            // same outcome three times.
            Log::error('Resend skipped: target package no longer exists', ['package_id' => $this->packageId]);

            return;
        }

        try {
            $resend->resend($this->order, $package, $this->note, $this->triggeredBy, $this->playerId, $this->serverId, $this->overrideReason);
        } catch (ValidationException $e) {
            // A guard (same-game, active, resendable, player-ID
            // window) that held at request time but no longer does by
            // the time this job actually runs — e.g. the order was
            // already resolved another way in between. Expected,
            // occasional outcome, not a job failure to retry.
            Log::info('Resend rejected at attempt time', ['reason' => $e->getMessage()]);
        }
    }

    /**
     * All $tries exhausted on a genuine connection-level failure —
     * surfaces in `failed_jobs`, distinct from a business-level
     * delivery failure (which OrderResendService already records as
     * an `order_resend_attempts` row with outcome=failed, not an
     * exception).
     */
    public function failed(\Throwable $exception): void
    {
        Log::withContext(['order_number' => $this->order->order_number]);
        Log::error('ResendOrderDeliveryJob exhausted all retries', ['exception' => $exception->getMessage()]);
    }
}
