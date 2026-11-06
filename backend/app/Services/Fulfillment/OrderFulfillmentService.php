<?php

namespace App\Services\Fulfillment;

use App\Jobs\CheckSupplierDeliveryJob;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\OrderResendAttempt;
use App\Models\Supplier;
use App\Services\Accounting\SupplierFundingService;
use App\Services\Campaign\CampaignService;
use App\Services\Currency\LandedCostRate;
use App\Services\Ledger\LedgerService;
use App\Services\Notification\CustomerNotificationService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\InvalidOrderTransitionException;
use App\Services\Order\OrderStatusService;
use App\Services\Order\ReferenceNumberService;
use App\Services\Supplier\Manual\SupplierGroupNotifier;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderRequest;
use App\Services\Supplier\SupplierOutcome;
use App\Services\Voucher\VoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orchestrates checkout COMPLETION — submitting a paid order to the
 * supplier and recording the outcome. Assumes payment_status is
 * already genuinely Paid (set by whatever verifies and processes the
 * payment-gateway webhook — that verification/idempotency check
 * happens before this is ever called, not inside it).
 *
 * ADR-026 (ORD-10): a duplicate_reference response — Gamevion's own
 * idempotency signal that a prior attempt for this reference_number
 * already reached them — routes straight to delivery_status=needs_review
 * instead of a plain failed, regardless of whether this call is a
 * fresh first attempt or a retry (ReconcilePendingDeliveriesCommand's
 * own re-dispatch included). No automated resolution is attempted
 * beyond that: Gamevion's check-status endpoint needs its own invoice
 * number, which duplicate_reference never supplies, and neither its
 * API nor its dashboard support a reference-number lookup (confirmed
 * live, see ADR-026's Context) — only an admin manually cross-checking
 * Gamevion's dashboard can resolve it (markDeliveredManually() below).
 */
final class OrderFulfillmentService
{
    public function __construct(
        private readonly OrderStatusService $orderStatus,
        private readonly ReferenceNumberService $referenceNumbers,
        private readonly SupplierAdapterFactory $supplierAdapters,
        private readonly LedgerService $ledger,
        private readonly VoucherService $vouchers,
        private readonly SupplierFundingService $supplierFunding,
        private readonly LandedCostRate $landedCost,
        // A default, not a required argument: the console test commands and
        // fixtures build this service by hand, and CampaignService has no
        // dependencies of its own.
        private readonly CampaignService $campaigns = new CampaignService,
    ) {}

    /**
     * A webhook sender (Xendit's own docs call this out as expected
     * behavior) can deliver the same payment-confirmed event twice —
     * PAY-2 requires that this never causes duplicate supplier orders
     * or duplicate credit delivery. The Order row is re-read here
     * with lockForUpdate() inside a transaction (same pattern as
     * LedgerService::withdraw()/VoucherService::redeem()) so a second,
     * genuinely concurrent call blocks until the first commits, then
     * observes the already-advanced delivery_status and is rejected by
     * OrderStatusService's guard — never generates a second
     * reference_number or submits a second supplier order for the
     * same Order.
     *
     * $recordAttempt (ADR-106 addendum, 2026-09-21): OrderResendService::resend()
     * calls this internally after already writing its own, richer
     * `attempt_type=resend` row (package swap, live-cost reconciliation)
     * — passes false here so this method's own generic initial/retry
     * write never double-books the same attempt. Every other caller
     * (FulfillOrderJob — the webhook, checkout, reseller placement, both
     * reconciliation paths, and retryDelivery()'s plain retry) leaves
     * this at its true default, since none of them write a row of their
     * own.
     */
    public function fulfill(Order $order, ?string $triggeredBy = null, ?string $note = null, bool $recordAttempt = true): Order
    {
        // ADR-094 decision 7: a combo order (Order.package.is_combo)
        // diverts to its own leg-loop sub-flow before any of the
        // single-ref guards below — it has no supplier_product_ref/
        // supplier_id of its own to check (ADR-094 decision 3). Never
        // reached via OrderResendService::resend() (decision 10 rejects
        // every combo resend), so $recordAttempt has no combo case to
        // gate — not threaded through fulfillCombo().
        if ($order->isCombo()) {
            return $this->fulfillCombo($order, $triggeredBy, $note);
        }

        // ADR-083 decision 3 — captured here, written to the supplier
        // funding ledger only AFTER the transaction below commits (see
        // the bottom of this method). Stays null unless the Success
        // branch actually runs and the adapter reported a price.
        $drawdownPrice = null;
        $recoveryPollScheduled = false;

        // Phase 1 (2026-09-28 audit finding M-1 fix): every guard check,
        // reference resolution, and the NotStarted/Failed/NeedsReview ->
        // Processing transition — committed on its own, BEFORE the
        // supplier call. This used to share ONE transaction with phase 2
        // below, so a thrown exception on the supplier call (a real
        // ConnectionException on a slow/timing-out supplier included)
        // rolled EVERYTHING back — the reference and the Processing
        // write both undone. A retry then minted a brand-new reference
        // via resolve(null) (a non-null stored value is what makes reuse
        // possible at all), and if the supplier had actually processed
        // the first, now-orphaned attempt despite the timeout, the order
        // could be submitted and delivered a second time under the new
        // reference. Splitting phase 1 out means phase 2's catch block
        // (below) always finds the order already durably at Processing
        // with its reference already persisted, so it can transition to
        // NeedsReview — markNeedsReview() only accepts Processing/Failed/
        // Pending as the FROM state, which a full rollback would have
        // undone had this stayed one transaction.
        [$referenceNumber, $wasNotStarted, $resubmit] = DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // ADR-102 decision 1: the real, final defense-in-depth
            // check — everything upstream (OrderController's guards,
            // OrderResendService::assertResendable()) is a friendly
            // pre-check that can still go stale between "admin clicked
            // resend" and "this job actually acquired the row lock" (an
            // admin issuing a voucher/wallet-refund in that exact gap).
            // Checked inside the lock, before anything else, so a
            // resend can never deliver on top of compensation already
            // given regardless of which entry point raced which.
            if ($locked->isAlreadyCompensated()) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has already been compensated (voucher issued or wallet refunded) — refusing to fulfill",
                );
            }

            // ADR-106 decision 3 — captured BEFORE this call's own
            // update() advances delivery_status past NotStarted, so it
            // genuinely reflects "was this order's very first
            // fulfillment attempt" rather than re-deriving it later from
            // an already-mutated value. A resend/retry always arrives
            // here from Failed/NeedsReview/Pending, never NotStarted, so
            // this is false on every call except a true first attempt.
            $wasNotStarted = $locked->delivery_status === DeliveryStatus::NotStarted;

            // ORD-11's central guard: delivery may only start once
            // payment is genuinely paid. OrderStatusService throws
            // otherwise — the single most direct path to giving away
            // free game credits without confirmed payment, never
            // bypassed here.
            $processingStatus = $this->orderStatus->startDelivery($locked->payment_status, $locked->delivery_status);

            if ($locked->supplier_product_ref === null) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has no supplier_product_ref set — cannot submit to supplier",
                );
            }

            // ADR-031: one Package = one supplier, fixed at
            // package-curation/checkout time — resolved fresh on every
            // call (including a retry) rather than cached on this
            // service, since a resend (OrderResendService) can change
            // which supplier an order targets between attempts.
            if ($locked->supplier_id === null) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has no supplier_id set — cannot resolve a SupplierAdapter",
                );
            }

            // ORD-8, narrowed by ADR-102 decision 9: reused on every
            // retry EXCEPT one deliberate case — a resend initiated
            // from Failed always gets a fresh reference. Safe because
            // Failed (post decision 4) always means confirmed
            // non-delivery, so there's no ambiguity a fresh reference
            // could double up on; it also makes a package swap (ADR-017)
            // genuinely effective again, since a fresh reference is
            // never subject to the replay behavior a resubmitted
            // already-formed reference has. A resend from NeedsReview
            // keeps reusing the stored value unchanged (resolve()) —
            // reuse IS the safety mechanism there, so Digiflazz's/
            // Gamevion's own dedup on the same reference still protects
            // a genuinely-unknown-outcome order from a real double
            // delivery. Combo orders never reach this line (fulfillCombo()
            // diverts before it) — decision 9 excludes them, see ADR-103.
            $referenceNumber = $locked->delivery_status === DeliveryStatus::Failed
                ? $this->referenceNumbers->generate()
                : $this->referenceNumbers->resolve($locked->reference_number);

            // ADR-102 2026-10-05 addendum (rc correction): a reused stored
            // reference may already exist at the supplier, so this call
            // is a status check as much as an order.
            $resubmit = $locked->delivery_status !== DeliveryStatus::Failed && $locked->reference_number !== null;

            // ADR-102 2026-11-05 addendum: past the supplier's window the
            // stored reference is unknown to it, so a re-submit would buy again.
            if ($resubmit && ! $locked->sameReferenceWithinWindow()) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} is too old to re-submit with the same reference; use Confirm Failed",
                );
            }

            // ADR-014: extends whatever context the caller already set
            // (order_number, from the webhook/job) with the ORD-8 key
            // Gamevion itself is called with — the two together are
            // what a support conversation ("customer's order didn't
            // arrive") and a raw Gamevion dashboard lookup have in
            // common.
            Log::withContext(['reference_number' => $referenceNumber]);

            // ADR-126 decision 10: a joki attempt after a failed one waits
            // for a penjoki again (its package's supplier, the pool), never
            // back on the penjoki who failed it. Its cost is the agreed
            // payout and is not touched.
            $restartSupplierId = $locked->isJoki() && in_array($locked->delivery_status, [DeliveryStatus::Failed, DeliveryStatus::NeedsReview], true)
                ? $locked->package?->supplier_id
                : null;

            $locked->update([
                'reference_number' => $referenceNumber,
                'delivery_status' => $processingStatus->value,
                ...($restartSupplierId !== null ? ['supplier_id' => $restartSupplierId] : []),
            ]);

            return [$referenceNumber, $wasNotStarted, $resubmit];
        });

        // Phase 2: the actual supplier call and outcome handling, in its
        // own transaction so a thrown exception here rolls back only
        // this phase's own writes, never phase 1's already-committed
        // Processing/reference state.
        try {
            $delivered = DB::transaction(function () use ($order, $referenceNumber, $wasNotStarted, $resubmit, &$drawdownPrice, &$recoveryPollScheduled, $triggeredBy, $note, $recordAttempt) {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

                $adapter = $this->supplierAdapters->for($locked->supplier);

                $result = $adapter->createOrder(new SupplierOrderRequest(
                    productRef: $locked->supplier_product_ref,
                    referenceNumber: $referenceNumber,
                    playerId: $locked->player_id,
                    serverId: $locked->server_id,
                    customerPhone: $locked->customer_phone,
                    // ADR-097 decision 15 — Digiflazz-specific, ignored by
                    // every other adapter.
                    customerNoSeparator: $locked->game?->customerNoSeparatorOverride(),
                    orderId: $locked->id,
                    resubmit: $resubmit,
                ));

                // ADR-032: branches on the adapter's normalized outcome,
                // never on raw success/failure alone — a Pending response
                // (async supplier, e.g. Digiflazz) is neither a clean
                // delivery nor a rejection, and must never be mistaken for
                // either.
                if ($result->outcome === SupplierOutcome::Pending) {
                    $locked->update([
                        'delivery_status' => $this->orderStatus->markPending($locked->delivery_status)->value,
                        'supplier_response' => $result->data,
                    ]);

                    if ($recordAttempt) {
                        $this->recordFulfillmentAttempt($locked, $wasNotStarted, $triggeredBy, $note);
                    }

                    Log::info('Delivery pending — awaiting async supplier confirmation', [
                        'supplier_response' => $result->data,
                    ]);

                    $this->postToManualSupplierAfterCommit($locked);

                    return $locked->fresh();
                }

                if ($result->outcome === SupplierOutcome::Failure) {
                    // ADR-026 / ADR-098, reclassified by ADR-102 decision 4:
                    // needs_review is narrowed to a GENUINELY unknown
                    // outcome — unsafe to resubmit with the same reference
                    // AND not a confirmed Gagal. Gamevion's 409/
                    // duplicate_reference is exactly that (unsafe, not
                    // confirmed) and still routes here unchanged. Digiflazz's
                    // own 20-code "Terbentuk Transaksi=Ya" table used to
                    // route here too, but that was ADR-098's own modeling
                    // flaw: Digiflazz's `status` field already told the
                    // platform the definitive outcome (Gagal) for those
                    // codes — "can't safely resubmit" isn't the same fact
                    // as "outcome unknown". A confirmed Gagal now always
                    // reaches Failed instead, whether or not resubmitting
                    // is unsafe, so Issue Voucher is available immediately
                    // instead of needing a Confirm-Failed detour first.
                    $requiresManualReview = $result->resendUnsafeWithSameReference && ! $result->outcomeConfirmedFailed;
                    $supplierResponse = ['error_code' => $result->errorCode, 'error_message' => $result->errorMessage];

                    // ADR-102 addendum (2026-09-29): ambiguous on a supplier
                    // whose same-ref re-submit replays the outcome → Pending,
                    // auto-polled with this same reference, not an admin.
                    if ($requiresManualReview && $this->resubmitReplaysOutcome($locked)) {
                        $this->parkAmbiguousAsPending($locked, $supplierResponse);
                        $recoveryPollScheduled = true;
                    } else {
                        $locked->update([
                            'delivery_status' => $requiresManualReview
                                ? $this->orderStatus->markNeedsReview($locked->delivery_status)->value
                                : $this->orderStatus->markDeliveryFailed($locked->delivery_status)->value,
                            'supplier_response' => $supplierResponse,
                        ]);
                    }

                    if ($recordAttempt) {
                        $this->recordFulfillmentAttempt($locked, $wasNotStarted, $triggeredBy, $note);
                    }

                    // ADR-014: the one line a file-log admin actually needs
                    // to notice without watching the Admin Orders screen —
                    // a business-level failure (this branch) never throws,
                    // so without this line it would be silent until someone
                    // looks. Grep by reference_number/order_number to find
                    // the matching webhook/job lines for full context.
                    Log::warning(match (true) {
                        $recoveryPollScheduled => 'Delivery ambiguous — Pending, auto-polling with the same reference',
                        $requiresManualReview => 'Delivery ambiguous — needs manual review',
                        default => 'Delivery failed',
                    }, [
                        'error_code' => $result->errorCode,
                        'error_message' => $result->errorMessage,
                    ]);

                    return $locked->fresh();
                }

                // ADR-111 decision 2/3: real per-transaction cost, captured
                // regardless of the feature flag; only USED to recompute
                // platform_profit when the flag is enabled (decision 8).
                // Closes this branch's own gap ADR-111's Context called out
                // as the biggest latent one — a plain successful delivery
                // (first attempt or retry) never reconciled platform_profit
                // against anything beyond the checkout-time catalog estimate.
                $realCostPriceSen = $this->captureRealCostSen($result->data, $locked->supplier);

                $updateData = [
                    'supplier_ref' => $result->data['supplier_ref'] ?? null,
                    'supplier_response' => $result->data,
                    'delivery_status' => $this->orderStatus->markDelivered($locked->delivery_status)->value,
                    'delivered_at' => now(),
                    'real_cost_price_sen' => $realCostPriceSen,
                ];

                if ($realCostPriceSen !== null) {
                    $updateData += $this->reconcileRealCostProfit($locked, $realCostPriceSen);
                }

                $locked->update($updateData);

                if ($recordAttempt) {
                    $this->recordFulfillmentAttempt($locked, $wasNotStarted, $triggeredBy, $note);
                }

                $this->creditProfit($locked);

                // ADR-024 decision #6 — both payment and delivery succeeded,
                // the third and final outcome of the voucher-redemption
                // three-outcome model: any reserved redemption this order
                // made is now permanent, never restored. No-op if this
                // order never used a voucher.
                $this->commitInstruments($locked->id);

                if (isset($result->data['price'])) {
                    $drawdownPrice = (float) $result->data['price'];
                }

                return $locked->fresh();
            });
        } catch (Throwable $e) {
            // 2026-09-28 audit finding M-1: we genuinely don't know
            // whether the supplier received this order before things
            // broke, so the safe default mirrors attemptLeg()'s
            // identical reasoning for combo legs — NeedsReview, never
            // Failed (Failed implies "nothing happened, safe to retry
            // from scratch," which isn't a safe assumption here). A
            // subsequent resend reuses the SAME reference (ADR-102
            // decision 9's NeedsReview carve-out) — if the supplier
            // really did see it, its own dedup on that reference is
            // what actually prevents a second delivery.
            Log::error('Delivery attempt threw unexpectedly — marking NeedsReview rather than stranding the order at Processing', [
                'order_id' => $order->id,
                'exception' => $e->getMessage(),
            ]);

            $delivered = DB::transaction(function () use ($order, $wasNotStarted, $triggeredBy, $note, $recordAttempt, $e, &$recoveryPollScheduled) {
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

                // Same race-guard as attemptLeg()'s own catch handler —
                // a different, faster concurrent attempt already
                // resolved this order (Delivered) or has it in flight
                // with a supplier that hasn't confirmed yet (Pending)
                // while this attempt was still failing; never overwrite
                // either.
                if (in_array($locked->delivery_status, [DeliveryStatus::Delivered, DeliveryStatus::Pending], true)) {
                    return $locked;
                }

                $supplierResponse = ['error_message' => 'Delivery attempt failed unexpectedly: '.$e->getMessage()];

                if ($this->resubmitReplaysOutcome($locked)) {
                    // ADR-102 addendum (2026-09-29) — see the Failure branch.
                    $this->parkAmbiguousAsPending($locked, $supplierResponse);
                    $recoveryPollScheduled = true;
                } else {
                    $locked->update([
                        'delivery_status' => $this->orderStatus->markNeedsReview($locked->delivery_status)->value,
                        'supplier_response' => $supplierResponse,
                    ]);
                }

                if ($recordAttempt) {
                    $this->recordFulfillmentAttempt($locked, $wasNotStarted, $triggeredBy, $note);
                }

                return $locked->fresh();
            });
        }

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($delivered, $drawdownPrice);
        }

        if ($recoveryPollScheduled) {
            $this->scheduleRecoveryPoll($delivered);
        }

        return $delivered;
    }

    /**
     * ADR-094 decision 7: the combo counterpart to fulfill()'s
     * single-supplier-call path. Three short, separately-committed
     * steps rather than one transaction spanning the whole order —
     * holding lockForUpdate() across up to 3 outbound HTTP round-trips
     * (decision 20's leg cap) would be the exact lock-contention class
     * ADR-077 already fixed once in production:
     *
     *  1. Advance the order to Processing and seed `order_delivery_legs`
     *     from `package->components` on the first attempt only (a retry
     *     finds its legs already there and reuses them — decision 7's
     *     idempotent-by-construction leg loop).
     *  2. Attempt every not-yet-terminal leg exactly once, in `leg_number`
     *     order: a committed claim, then one locked supplier call
     *     (attemptLeg(), ADR-103 2026-11-07 addendum).
     *  3. Aggregate the legs' resulting statuses into the order's own
     *     delivery_status (resolveComboOutcome()).
     */
    private function fulfillCombo(Order $order, ?string $triggeredBy = null, ?string $note = null): Order
    {
        DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // ADR-102 decision 1 — see fulfill()'s identical guard for
            // the full reasoning; the combo path needs the same
            // final-defense check inside its own lock.
            if ($locked->isAlreadyCompensated()) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} has already been compensated (voucher issued or wallet refunded) — refusing to fulfill",
                );
            }

            // ADR-103 decision 16: past the same-reference window a combo sends
            // nothing, not even under a fresh reference. The window counts
            // from the order's age, so a leg killed mid-call there would age
            // out at once and reach Confirm Failed without being asked about.
            if (! $locked->sameReferenceWithinWindow()) {
                throw new OrderFulfillmentException(
                    "Order #{$locked->id} is too old to re-submit with the same reference; use Confirm Failed",
                );
            }

            $processingStatus = $this->orderStatus->startDelivery($locked->payment_status, $locked->delivery_status);
            $referenceNumber = $this->referenceNumbers->resolve($locked->reference_number);

            Log::withContext(['reference_number' => $referenceNumber]);

            $locked->update([
                'reference_number' => $referenceNumber,
                'delivery_status' => $processingStatus->value,
            ]);

            if (! OrderDeliveryLeg::query()->where('order_id', $locked->id)->exists()) {
                $this->seedDeliveryLegs($locked);
            }
        });

        $order = $order->fresh();

        $legs = OrderDeliveryLeg::query()->where('order_id', $order->id)->orderBy('leg_number')->get();
        $parkedForRecovery = false;

        foreach ($legs as $leg) {
            $parkedForRecovery = $this->attemptLeg($order, $leg, $triggeredBy, $note) || $parkedForRecovery;
        }

        $resolved = $this->resolveComboOutcome($order);

        // ADR-102 addendum (2026-09-29): a leg parked at Pending by the
        // replay-safe rule gets its first poll in ~2 minutes rather than
        // waiting for the 15-minute sweep.
        if ($parkedForRecovery) {
            $this->scheduleRecoveryPoll($resolved);
        }

        return $resolved;
    }

    /**
     * ADR-094 decision 1/4: one row per real leg — `package_components`
     * quantity > 1 (a repeated component) expands into that many leg
     * rows here, not a single row with a multiplier, so each one gets
     * its own `leg_number`/idempotency key/supplier_reference.
     */
    private function seedDeliveryLegs(Order $order): void
    {
        $legNumber = 1;

        foreach ($order->package->components as $component) {
            $quantity = (int) $component->pivot->quantity;

            for ($i = 0; $i < $quantity; $i++) {
                OrderDeliveryLeg::query()->create([
                    'order_id' => $order->id,
                    'component_package_id' => $component->id,
                    'supplier_id' => $component->supplier_id,
                    'leg_number' => $legNumber,
                    'status' => DeliveryStatus::NotStarted->value,
                    // ADR-107 decision 1 — frozen at the one moment this
                    // leg is created (checkout, synchronous inside
                    // fulfillCombo()'s first pass); feeds decision 4's
                    // partial-combo voucher apportionment. Each expanded
                    // leg already represents one unit (the quantity loop
                    // above), so this is the component's own per-unit
                    // price, never multiplied by $quantity.
                    'selling_price_sen' => $component->standard_selling_price,
                ]);

                $legNumber++;
            }
        }
    }

    /**
     * ADR-094 decision 7/26 (idempotency key): one real supplier call,
     * one leg row updated. `{reference_number}-L{n}` extends ORD-8.
     *
     * ADR-103 2026-11-07 addendum, decisions 10-12: the two phases
     * fulfill() has. claimLeg() commits the reference, Processing and a
     * pending attempt row first, so a killed worker leaves a leg that says
     * "sent, outcome unknown" under the reference it really used. Only
     * then the supplier call, in its own transaction, order then leg. The
     * claim stays outside the try: an exception there has sent nothing, so
     * it must not park a leg as if it had.
     *
     * Returns true when the leg was parked for an automatic poll.
     */
    private function attemptLeg(Order $order, OrderDeliveryLeg $leg, ?string $triggeredBy = null, ?string $note = null): bool
    {
        $claim = $this->claimLeg($order, $leg, $triggeredBy, $note);

        if ($claim === null) {
            return false;
        }

        [$legReferenceNumber, $resubmit] = $claim;
        $legId = $leg->id;
        $drawdownPrice = null;
        $parked = false; // ADR-102 addendum (2026-09-29): true when the replay-safe rule parked this leg at Pending

        try {
            DB::transaction(function () use ($order, $legId, $legReferenceNumber, $resubmit, &$drawdownPrice, &$parked) {
                Order::query()->lockForUpdate()->findOrFail($order->id);
                $lockedLeg = OrderDeliveryLeg::query()->lockForUpdate()->findOrFail($legId);

                $component = $lockedLeg->componentPackage;
                $adapter = $this->supplierAdapters->for($component->supplier);

                $result = $adapter->createOrder(new SupplierOrderRequest(
                    productRef: $component->supplier_package_ref,
                    referenceNumber: $legReferenceNumber,
                    playerId: $order->player_id,
                    serverId: $order->server_id,
                    customerPhone: $order->customer_phone,
                    // ADR-097 decision 15/14 — `$order->game`, not the
                    // leg's component's own game: a combo's components
                    // are enforced same-game at save time
                    // (StoreComboPackageRequest), so they're identical
                    // anyway, and $order already carries it.
                    customerNoSeparator: $order->game?->customerNoSeparatorOverride(),
                    orderId: $order->id,
                    resubmit: $resubmit,
                ));

                if ($result->outcome === SupplierOutcome::Pending) {
                    $lockedLeg->update(['status' => DeliveryStatus::Pending->value]);

                    $this->resolvePendingLegAttempt($lockedLeg->id, 'pending', $result->data);

                    Log::info('Combo leg pending — awaiting async supplier confirmation', ['leg_id' => $lockedLeg->id]);

                    return;
                }

                if ($result->outcome === SupplierOutcome::Failure) {
                    $attemptData = ['error_code' => $result->errorCode, 'error_message' => $result->errorMessage];

                    // ADR-098 / ADR-102 decision 4 — same split-flag
                    // routing fulfill()'s own Failure branch uses.
                    if ($result->resendUnsafeWithSameReference && ! $result->outcomeConfirmedFailed) {
                        // ADR-103 decision 2: the flag feeds decision 8's
                        // OR-rollup, and only a NeedsReview leg needs it.
                        $parked = $this->parkUnknownLeg($lockedLeg, $result->errorMessage, $attemptData, ['resend_unsafe_with_same_reference' => true]);

                        Log::warning('Combo leg ambiguous — outcome unknown', ['leg_id' => $lockedLeg->id, 'error_code' => $result->errorCode, 'parked_for_poll' => $parked]);

                        return;
                    }

                    $lockedLeg->update(['status' => DeliveryStatus::Failed->value, 'failure_reason' => $result->errorMessage]);

                    $this->resolvePendingLegAttempt($lockedLeg->id, 'failed', $attemptData);

                    Log::warning('Combo leg failed', ['leg_id' => $lockedLeg->id] + $attemptData);

                    return;
                }

                // ADR-111 decision 2 — the leg-scoped twin of fulfill()'s
                // own capture; no order-level platform_profit recompute
                // here (that's resolveComboOutcome()'s job, once every
                // leg has settled).
                $realLegCostPriceSen = $this->captureRealCostSen($result->data, $component->supplier);

                $lockedLeg->update([
                    'status' => DeliveryStatus::Delivered->value,
                    'supplier_reference' => $result->data['supplier_ref'] ?? null,
                    'delivered_at' => now(),
                    'real_cost_price_sen' => $realLegCostPriceSen,
                ]);

                $this->resolvePendingLegAttempt($lockedLeg->id, 'success', $result->data);

                if (isset($result->data['price'])) {
                    $drawdownPrice = (float) $result->data['price'];
                }
            });
        } catch (Throwable $e) {
            // We don't know whether the supplier received this leg's
            // request before things broke, so it is never Failed (Failed
            // means "safe to retry from scratch"). Deliberately not wrapping
            // recordOrderDrawdown() below: it runs after a real Delivered
            // commit, so a failure there is a bookkeeping concern, never a
            // reason to relabel a delivery that succeeded.
            Log::error('Combo leg attempt threw unexpectedly — outcome unknown', [
                'leg_id' => $legId,
                'exception' => $e->getMessage(),
            ]);

            DB::transaction(function () use ($order, $legId, $e, &$parked) {
                Order::query()->lockForUpdate()->findOrFail($order->id);
                $lockedLeg = OrderDeliveryLeg::query()->lockForUpdate()->findOrFail($legId);

                if ($lockedLeg->status === DeliveryStatus::Processing) {
                    $parked = $this->parkUnknownLeg($lockedLeg, 'Delivery attempt failed unexpectedly: '.$e->getMessage(), ['error_message' => $e->getMessage()]);
                }
            });

            return $parked;
        }

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($order, $drawdownPrice, $leg->fresh());
        }

        return $parked;
    }

    /**
     * ADR-103 decision 10: the committed half of an attempt. Delivered,
     * Pending and Processing legs are already owned, so never re-submitted;
     * anything else gets one attempt this pass.
     *
     * The reference follows decision 3 (ADR-102 decision 9 at leg
     * granularity): NotStarted keeps its derived value, Failed mints a fresh
     * one (decision 4's ULID suffix; this leg is confirmed non-delivered),
     * NeedsReview reuses the stored value (the double-delivery protection).
     * ADR-102 2026-10-05 addendum (rc correction): `resubmit` is decided from
     * the status before this claim overwrites it.
     *
     * @return array{0: string, 1: bool}|null reference and resubmit; null when the leg needs no attempt
     */
    private function claimLeg(Order $order, OrderDeliveryLeg $leg, ?string $triggeredBy, ?string $note): ?array
    {
        return DB::transaction(function () use ($order, $leg, $triggeredBy, $note) {
            Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedLeg = OrderDeliveryLeg::query()->lockForUpdate()->findOrFail($leg->id);

            if (in_array($lockedLeg->status, [DeliveryStatus::Delivered, DeliveryStatus::Pending, DeliveryStatus::Processing], true)) {
                return null;
            }

            $wasLegNotStarted = $lockedLeg->status === DeliveryStatus::NotStarted;
            $resubmit = $lockedLeg->status !== DeliveryStatus::Failed && $lockedLeg->reference_number !== null;

            $legReferenceNumber = match ($lockedLeg->status) {
                DeliveryStatus::Failed => "{$order->reference_number}-L{$lockedLeg->leg_number}-".Str::ulid(),
                DeliveryStatus::NeedsReview => $lockedLeg->reference_number,
                default => $lockedLeg->reference_number ?? "{$order->reference_number}-L{$lockedLeg->leg_number}",
            };

            Log::withContext(['reference_number' => $legReferenceNumber]);

            $lockedLeg->update(['reference_number' => $legReferenceNumber, 'status' => DeliveryStatus::Processing->value]);

            $this->recordLegAttempt($lockedLeg, $wasLegNotStarted, $triggeredBy, $note);

            return [$legReferenceNumber, $resubmit];
        });
    }

    /**
     * ADR-103 decision 12: the one rule for a leg whose outcome is unknown.
     * A replay-safe supplier is polled with the same reference (ADR-102
     * addendum 2026-09-29), anything else waits for an admin. Either way the
     * reference stays. Caller holds the order and leg locks. Returns true
     * when the leg was parked for a poll.
     *
     * @param  array<string, mixed>  $attemptData
     * @param  array<string, mixed>  $reviewColumns
     */
    private function parkUnknownLeg(OrderDeliveryLeg $lockedLeg, ?string $reason, array $attemptData, array $reviewColumns = []): bool
    {
        if (SupplierAdapterFactory::resubmitReplaysOutcome($lockedLeg->componentPackage?->supplier)) {
            $lockedLeg->update(['status' => DeliveryStatus::Pending->value]);

            $this->resolvePendingLegAttempt($lockedLeg->id, 'pending', $attemptData + ['auto_recovery' => self::AUTO_RECOVERY_NOTE]);

            return true;
        }

        $lockedLeg->update(['status' => DeliveryStatus::NeedsReview->value, 'failure_reason' => $reason] + $reviewColumns);

        $this->resolvePendingLegAttempt($lockedLeg->id, 'failed', $attemptData);

        return false;
    }

    /**
     * ADR-103 decision 13: an order at Processing always means a live pass,
     * so a stale one (the worker died) is moved out here, in one transaction:
     * every in-flight leg parked, the order to Pending or NeedsReview, then
     * the same rollup as everywhere else. All delivered gives Delivered with
     * profit credited; a leg in flight is polled with its reference; a leg
     * never tried waits for an admin's Retry (founder's call: no automatic
     * path buys it). A no-op on any other status.
     */
    public function recoverDeadComboPass(Order $order): Order
    {
        $recovered = DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->delivery_status !== DeliveryStatus::Processing) {
                return $locked;
            }

            $legs = OrderDeliveryLeg::query()->where('order_id', $locked->id)->with('componentPackage.supplier')->lockForUpdate()->get();

            foreach ($legs->where('status', DeliveryStatus::Processing) as $leg) {
                $this->parkUnknownLeg($leg, 'Worker stopped mid-delivery', ['error_message' => 'Worker stopped mid-delivery']);
            }

            Log::withContext(['order_number' => $locked->order_number]);
            Log::warning('Delivery reconciliation: stale combo pass recovered', ['legs' => $legs->map(fn (OrderDeliveryLeg $leg) => $leg->status->value)->all()]);

            $locked->update(['delivery_status' => $legs->contains('status', DeliveryStatus::Pending)
                ? $this->orderStatus->markPending($locked->delivery_status)->value
                : $this->orderStatus->markNeedsReview($locked->delivery_status)->value]);

            return $this->resolveComboOutcome($locked);
        });

        if ($recovered->delivery_status === DeliveryStatus::Pending) {
            $this->scheduleRecoveryPoll($recovered);
        }

        return $recovered;
    }

    /**
     * ADR-103 decision 14: applies DeliveryStatus::comboRollup() to the
     * order and runs what each outcome owes. The rule itself lives there;
     * this is its side effects.
     *
     * Two guards first. A terminal order is left alone. An order at
     * Processing with a leg not yet final is left alone too: its pass still
     * owns it, so a webhook that lands mid-pass records its leg and nothing
     * more, and the pass's own end decides (a dead pass: recoverDeadComboPass()).
     *
     * Delivered: ADR-107 decision 2 reconciles `platform_profit` once, here,
     * as a money-conservation residual (`selling_price (frozen) − Σ leg cost −
     * affiliate_profit`, ADR-105 decision 8's identity across legs).
     * `affiliate_profit` keeps its checkout value. Profit credits once and any
     * reserved voucher commits once, order-level, not per leg (ORD-9).
     * Failed: nothing delivered, Resend Delivery retries normally.
     * PartiallyDelivered: ADR-094 decision 28, settled by an admin with a
     * formula (OrderSettlementService), which credits the profit then.
     *
     * Callable from Processing (a pass, webhook or poll), Pending or
     * NeedsReview (a webhook or poll, or recovery), so each transition
     * dispatches to whichever of markDelivered()/finalizePendingSuccess()
     * (or markDeliveryFailed()/finalizePendingFailure()) matches.
     */
    private function resolveComboOutcome(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            // componentPackage:cost_price eager-loaded here (not a
            // separate query below) — ADR-107 decision 2 needs each
            // leg's live cost at exactly this moment, and a
            // query-builder pluck() would bypass the model's enum cast
            // anyway (real models needed so `status` compares against
            // DeliveryStatus below, not a bare string).
            $legs = OrderDeliveryLeg::query()->where('order_id', $locked->id)->with('componentPackage:id,cost_price')->get();
            $statuses = $legs->pluck('status');

            if (in_array($locked->delivery_status, [DeliveryStatus::Delivered, DeliveryStatus::Failed, DeliveryStatus::PartiallyDelivered], true)) {
                return $locked->fresh();
            }

            if ($locked->delivery_status === DeliveryStatus::Processing
                && $statuses->contains(fn (DeliveryStatus $status) => in_array($status, [DeliveryStatus::NotStarted, DeliveryStatus::Processing], true))) {
                return $locked->fresh();
            }

            // ADR-102 2026-10-05 addendum, decision 7: NeedsReview is the
            // other "awaiting the supplier" state a late leg result can
            // arrive in, so it exits the same way Pending does.
            $enteringFromSupplierWait = in_array($locked->delivery_status, [DeliveryStatus::Pending, DeliveryStatus::NeedsReview], true);
            $outcome = DeliveryStatus::comboRollup($statuses);

            if ($outcome === DeliveryStatus::Pending) {
                // Only Processing→Pending is a real transition; markPending()
                // accepts nothing else, and an order already waiting stays.
                if (! $enteringFromSupplierWait) {
                    $locked->update(['delivery_status' => $this->orderStatus->markPending($locked->delivery_status)->value]);
                }

                return $locked->fresh();
            }

            if ($outcome === DeliveryStatus::NeedsReview) {
                if ($locked->delivery_status !== DeliveryStatus::NeedsReview) {
                    $locked->update(['delivery_status' => $this->orderStatus->markNeedsReview($locked->delivery_status)->value]);
                }

                return $locked->fresh();
            }

            if ($outcome === DeliveryStatus::Delivered) {
                $newStatus = $enteringFromSupplierWait
                    ? $this->orderStatus->finalizePendingSuccess($locked->delivery_status)
                    : $this->orderStatus->markDelivered($locked->delivery_status);

                // ADR-107 decision 2, cost input widened by ADR-111
                // decision 3: real per-transaction cost (decision 2's
                // leg-level capture, falling back to this leg's own live
                // catalog cost only if ITS real cost is missing — a
                // genuinely unavailable FX rate at that one leg's delivery
                // moment, decision 6). Never a frozen snapshot — there
                // isn't one for cost, only `selling_price_sen` (decision 1).
                $costTotal = (int) $legs->sum(fn (OrderDeliveryLeg $leg) => $leg->costSen());
                $reconciledPlatformProfit = $locked->selling_price - $costTotal - $locked->affiliate_profit;

                // Decision 3: never block delivery over this — a combo
                // can finalize via webhook/scheduled poll with no admin
                // present to supply an override reason (ADR-105 decision
                // 4's gate is deliberately NOT reused here). The order
                // still delivers, the (possibly negative) figure is
                // recorded as-is, and ADR-111 decision 7's persisted
                // `profit_reconciled_flagged` (any negative result, OR a
                // material drift from the pre-reconciliation estimate —
                // both universal, not combo-only any more) is the
                // after-the-fact signal an admin needs to actually
                // notice it — the UI-facing half of the same signal.
                $flagged = $this->isProfitDriftFlagged($locked->platform_profit, $reconciledPlatformProfit, $locked->selling_price);

                if ($flagged) {
                    Log::warning('Combo order platform profit reconciled negative or drifted materially on final delivery', [
                        'order_id' => $locked->id,
                        'reconciled_platform_profit' => $reconciledPlatformProfit,
                        'cost_total' => $costTotal,
                        'affiliate_profit' => $locked->affiliate_profit,
                        'frozen_selling_price' => $locked->selling_price,
                    ]);
                }

                $locked->update([
                    'delivery_status' => $newStatus->value,
                    'delivered_at' => now(),
                    'platform_profit' => $reconciledPlatformProfit,
                    'profit_reconciled_flagged' => $flagged,
                ]);

                $this->creditProfit($locked);
                $this->commitInstruments($locked->id);

                return $locked->fresh();
            }

            if ($outcome === DeliveryStatus::Failed) {
                $newStatus = $enteringFromSupplierWait
                    ? $this->orderStatus->finalizePendingFailure($locked->delivery_status)
                    : $this->orderStatus->markDeliveryFailed($locked->delivery_status);

                $locked->update(['delivery_status' => $newStatus->value]);

                return $locked->fresh();
            }

            // ADR-094 decision 28: a known outcome, its own terminal status.
            // No profit credit here: it's credited at settlement (decision
            // 35), the same as a Failed order.
            $locked->update(['delivery_status' => $this->orderStatus->markPartiallyDelivered($locked->delivery_status)->value]);

            return $locked->fresh();
        });
    }

    /**
     * ADR-094 decision 7 (Phase 3b): the leg-level counterpart to
     * finalizePendingDelivery() — reached by DigiflazzWebhookController
     * (primary, after parsing the -L{n} suffix) or
     * CheckSupplierDeliveryJob's combo branch (poll backup), never
     * called directly from fulfillCombo() itself. $outcome must be
     * Success or Failure, same restriction finalizePendingDelivery()
     * itself enforces.
     *
     * Idempotent by construction: OrderStatusService::finalizePendingSuccess()/
     * finalizePendingFailure() both require the LEG to currently be
     * Pending, so a duplicate webhook delivery for the same leg observes
     * the already-advanced state and throws InvalidOrderTransitionException
     * — same guard finalizePendingDelivery() already relies on at the
     * order level, reused here unchanged since both operate on a plain
     * DeliveryStatus value.
     */
    public function finalizePendingDeliveryLeg(OrderDeliveryLeg $leg, SupplierOutcome $outcome, ?string $supplierRef = null, mixed $supplierResponse = null, bool $resendUnsafeWithSameReference = false, bool $outcomeConfirmedFailed = false): Order
    {
        if ($outcome === SupplierOutcome::Pending) {
            throw new OrderFulfillmentException(
                "finalizePendingDeliveryLeg() cannot be called with outcome=pending for leg #{$leg->id} — a Pending leg is not yet finalized",
            );
        }

        $order = $leg->order;
        $drawdownPrice = null;

        // ADR-102 2026-10-05 addendum, decision 7: the leg and the order
        // roll-up commit together, so a leg is never saved Delivered with
        // its order left behind. The order is locked first — the same
        // order-then-legs sequence confirmComboLegsFailed() uses.
        $resolved = DB::transaction(function () use ($order, $leg, $outcome, $supplierRef, $supplierResponse, $resendUnsafeWithSameReference, $outcomeConfirmedFailed, &$drawdownPrice) {
            Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedLeg = OrderDeliveryLeg::query()->lockForUpdate()->findOrFail($leg->id);

            Log::withContext(['order_delivery_leg_id' => $lockedLeg->id, 'leg_number' => $lockedLeg->leg_number]);

            // Decision 3, leg form: no evidence column on a leg, so log only.
            if (self::contradictsFinalStatus($lockedLeg->status, $outcome)) {
                Log::error('Supplier result contradicts the combo leg\'s final status — status unchanged', [
                    'leg_status' => $lockedLeg->status->value,
                    'outcome' => $outcome->value,
                    'supplier_response' => $supplierResponse,
                ]);
            }

            if ($outcome === SupplierOutcome::Success) {
                $deliveredStatus = $this->orderStatus->finalizePendingSuccess($lockedLeg->status);

                // ADR-111 decision 2 — the async counterpart to
                // attemptLeg()'s own capture, for a Digiflazz rc=03 leg
                // whose real price only ever arrives via webhook/poll.
                $realLegCostPriceSen = $this->captureRealCostSen(
                    is_array($supplierResponse) ? $supplierResponse : null,
                    $lockedLeg->supplier,
                );

                $lockedLeg->update([
                    'status' => $deliveredStatus->value,
                    'supplier_reference' => $supplierRef ?? $lockedLeg->supplier_reference,
                    'delivered_at' => now(),
                    'real_cost_price_sen' => $realLegCostPriceSen,
                ]);

                Log::info('Combo leg finalized as delivered');

                $this->resolvePendingLegAttempt($lockedLeg->id, 'success', $supplierResponse);

                if (is_array($supplierResponse) && isset($supplierResponse['price'])) {
                    $drawdownPrice = (float) $supplierResponse['price'];
                }

                return $this->resolveComboOutcome($order->fresh());
            }

            // ADR-098 / ADR-102 decision 4 — same split-flag routing as
            // finalizePendingDelivery()'s own Failure branch.
            $requiresManualReview = $resendUnsafeWithSameReference && ! $outcomeConfirmedFailed;

            $failedStatus = $requiresManualReview
                ? $this->orderStatus->markNeedsReview($lockedLeg->status)
                : $this->orderStatus->finalizePendingFailure($lockedLeg->status);

            $lockedLeg->update(array_merge([
                'status' => $failedStatus->value,
                'failure_reason' => is_array($supplierResponse) ? ($supplierResponse['error_message'] ?? null) : null,
            ], $requiresManualReview ? [
                // ADR-103 decision 2 — same leg-level flag attemptLeg()
                // writes, populated here for the async (webhook/poll)
                // finalize path too, only when this leg actually lands
                // on NeedsReview (see attemptLeg()'s identical comment).
                'resend_unsafe_with_same_reference' => true,
            ] : []));

            $this->resolvePendingLegAttempt($lockedLeg->id, 'failed', $supplierResponse);

            Log::warning($requiresManualReview ? 'Combo leg finalized as needs-review' : 'Combo leg finalized as failed', ['supplier_response' => $supplierResponse]);

            return $this->resolveComboOutcome($order->fresh());
        });

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($order, $drawdownPrice, $leg->fresh());
        }

        return $resolved;
    }

    /**
     * ADR-032 decision 3 — the one money path out of Pending, reached
     * by a supplier webhook (primary) or the reconcile poll's own
     * check (backup, CheckSupplierDeliveryJob), never called directly
     * from fulfill() itself. $outcome must be Success or Failure — a
     * Pending order is by definition not yet finalized, so passing
     * Pending here is a caller bug, not a legitimate state.
     *
     * Idempotent by construction: OrderStatusService::finalizePendingSuccess()/
     * finalizePendingFailure() both require the order to currently be
     * Pending, so a second call (e.g. a duplicate webhook delivery)
     * observes the already-advanced state and throws
     * InvalidOrderTransitionException — same lock-then-guard pattern
     * fulfill() itself already uses for the identical PAY-2 reason.
     *
     * `$expectedSupplierId` (ADR-126 2026-10-23 addendum, decision 18): a
     * manual confirmation names the supplier it acted for; a different
     * supplier under the lock means the order was reassigned, and nothing
     * is written.
     */
    public function finalizePendingDelivery(Order $order, SupplierOutcome $outcome, ?string $supplierRef = null, mixed $supplierResponse = null, bool $resendUnsafeWithSameReference = false, bool $outcomeConfirmedFailed = false, ?int $expectedSupplierId = null): Order
    {
        if ($outcome === SupplierOutcome::Pending) {
            throw new OrderFulfillmentException(
                "finalizePendingDelivery() cannot be called with outcome=pending for order #{$order->id} — a Pending order is not yet finalized",
            );
        }

        $drawdownPrice = null;
        $contradicted = false;

        $finalized = DB::transaction(function () use ($order, $outcome, $supplierRef, $supplierResponse, $resendUnsafeWithSameReference, $outcomeConfirmedFailed, $expectedSupplierId, &$drawdownPrice, &$contradicted) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($expectedSupplierId !== null && $locked->supplier_id !== $expectedSupplierId) {
                throw ValidationException::withMessages(['order' => ['This order was moved to another supplier.']]);
            }

            Log::withContext(['reference_number' => $locked->reference_number]);

            // ADR-102 2026-10-05 addendum, decision 3: a supplier answer
            // that contradicts a terminal status is kept as evidence and
            // logged, never applied. Committed before the caller sees the
            // usual "already finalized" exception below.
            // ADR-127 decision 11: a Success on an order already
            // compensated is refused the same way, whoever reports it
            // (a webhook, the poll, Manual Queue, a supplier's `.done`):
            // the customer keeps the compensation, never both.
            if (self::contradictsFinalStatus($locked->delivery_status, $outcome)
                || ($outcome === SupplierOutcome::Success && $locked->isAlreadyCompensated())) {
                $locked->update(['supplier_response' => array_merge(
                    is_array($locked->supplier_response) ? $locked->supplier_response : [],
                    ['late_supplier_result' => is_array($supplierResponse) ? $supplierResponse : ['outcome' => $outcome->value]],
                )]);
                Log::error('Supplier result contradicts the order\'s final delivery status — kept as evidence, status unchanged', [
                    'delivery_status' => $locked->delivery_status->value,
                    'outcome' => $outcome->value,
                ]);
                $contradicted = true;

                return $locked;
            }

            if ($outcome === SupplierOutcome::Success) {
                $deliveredStatus = $this->orderStatus->finalizePendingSuccess($locked->delivery_status);

                // ADR-111 decision 2/3 — the async counterpart to
                // fulfill()'s own Success branch: a Digiflazz rc=03
                // order's real price only ever arrives here (webhook or
                // reconcile poll), never at the initial Pending response.
                $realCostPriceSen = $this->captureRealCostSen(
                    is_array($supplierResponse) ? $supplierResponse : null,
                    $locked->supplier,
                );

                $updateData = [
                    'supplier_ref' => $supplierRef ?? $locked->supplier_ref,
                    'supplier_response' => $supplierResponse ?? $locked->supplier_response,
                    'delivery_status' => $deliveredStatus->value,
                    'delivered_at' => now(),
                    'real_cost_price_sen' => $realCostPriceSen,
                ];

                if ($realCostPriceSen !== null) {
                    $updateData += $this->reconcileRealCostProfit($locked, $realCostPriceSen);
                }

                $locked->update($updateData);

                $this->creditProfit($locked);
                $this->commitInstruments($locked->id);
                $this->resolvePendingResendAttempt($locked->id, 'success', $locked->supplier_response);

                Log::info('Pending delivery finalized as delivered', ['supplier_ref' => $supplierRef]);

                // ADR-083 decision 3 — the Digiflazz webhook (or the
                // reconcile poll's own checkStatus() re-submit) carries
                // the real `price` here; a `Pending` order's own initial
                // response never did, so this is the only place a
                // Digiflazz drawdown gets recorded.
                if (is_array($supplierResponse) && isset($supplierResponse['price'])) {
                    $drawdownPrice = (float) $supplierResponse['price'];
                }

                return $locked->fresh();
            }

            // ADR-098 / ADR-102 decision 4 — a Pending order's terminal
            // Failure can itself be a confirmed-Gagal or genuinely-
            // ambiguous case (this is exactly the path the real
            // PG-JLOMUJ1H23NE incident took: createOrder() returned
            // Pending, the reconcile poll's checkStatus() got a terminal
            // rc). Same split-flag routing as fulfill()'s own
            // synchronous Failure branch — a confirmed Gagal (Digiflazz)
            // routes to Failed even when the same reference is unsafe
            // to resubmit; only a genuinely unknown outcome (Gamevion's
            // duplicate_reference — which never reaches Pending in the
            // first place — or an unexpected exception) routes to
            // NeedsReview.
            $requiresManualReview = $resendUnsafeWithSameReference && ! $outcomeConfirmedFailed;

            $failedStatus = $requiresManualReview
                ? $this->orderStatus->markNeedsReview($locked->delivery_status)
                : $this->orderStatus->finalizePendingFailure($locked->delivery_status);

            $locked->update([
                'supplier_response' => $supplierResponse ?? $locked->supplier_response,
                'delivery_status' => $failedStatus->value,
            ]);
            $this->resolvePendingResendAttempt($locked->id, 'failed', $locked->supplier_response);

            // Deliberately no voucher/retail-ledger action here — a
            // Pending order finalized as Failed (or NeedsReview) lands
            // on a state a synchronous rejection would too, so the
            // existing Failed-only voucher-issuance gate
            // (VoucherController::storeFromOrder()) applies unchanged —
            // and, for NeedsReview, is deliberately withheld until an
            // admin looks, same as every other NeedsReview case.
            // No cash was ever taken from OUR ledger for this order, so
            // there is nothing to reverse (ADR-004).
            //
            // ADR-083 decision 3 (grilled 2026-09-11): no supplier-ledger
            // action here either — a `Pending` response never carried a
            // `price` (see the Success branch above), so nothing was
            // ever recorded as drawn down for this order; a `Gagal`
            // after `Pending` writes no `REFUND`. If a real Digiflazz
            // account is later found to actually deduct saldo at
            // `Pending` submission and restore it on `Gagal`, this needs
            // a deliberate `REFUND`/`MANUAL_ADJUSTMENT` branch added
            // here — don't assume it.
            Log::warning($requiresManualReview ? 'Pending delivery finalized as needs-review' : 'Pending delivery finalized as failed', ['supplier_response' => $supplierResponse]);

            return $locked->fresh();
        });

        if ($contradicted) {
            throw new InvalidOrderTransitionException("Order #{$order->id} is already final ({$finalized->delivery_status->value}); a contradicting supplier result was recorded");
        }

        if ($drawdownPrice !== null) {
            $this->supplierFunding->recordOrderDrawdown($finalized, $drawdownPrice);
        }

        return $finalized;
    }

    /** ADR-102 2026-10-05 addendum, decision 3 — Sukses after a Gagal-backed status, or Gagal after Delivered. */
    private static function contradictsFinalStatus(DeliveryStatus $status, SupplierOutcome $outcome): bool
    {
        return $outcome === SupplierOutcome::Success
            ? in_array($status, [DeliveryStatus::Failed, DeliveryStatus::PartiallyDelivered], true)
            : $status === DeliveryStatus::Delivered;
    }

    /**
     * 2026-09-15 bugfix (Delivery Logs outcome bug A): a resend of a
     * `Pending`-outcome async order (Digiflazz rc=03) used to record its
     * `order_resend_attempts.outcome` as a hard 'failed' the moment the
     * resend's own fulfill() call returned — coercing "not yet resolved"
     * into "definitely failed" — and never revisited that row once the
     * real outcome later arrived via webhook, the scheduled reconcile
     * poll, or ADR-096's manual "Check from Supplier" button. All three
     * of those callers funnel through this same finalizePendingDelivery(),
     * so correcting the historical attempt row here (instead of in each
     * caller separately) keeps it accurate regardless of which one
     * actually resolved it.
     *
     * "The most recent still-pending attempt for this order" is
     * unambiguous, not a guess: `reference_number` is reused (never
     * regenerated) across every retry/resend of the same order
     * (ReferenceNumberService's own doc comment), and `assertResendable()`
     * blocks a NEW resend while the order is Pending — so at most one
     * order_resend_attempts row for a given order can genuinely be
     * "awaiting an async answer" at a time. A combo order never reaches
     * THIS method at all (OrderResendService::assertSameGamePackage()
     * rejects every combo resend, ADR-094 decision 10) — its `whereNull`
     * guard keeps it scoped to order-level rows regardless. A combo
     * DOES have its own leg-scoped counterpart now, resolvePendingLegAttempt()
     * below, for the retryDelivery()/attemptLeg() path resend() can
     * never reach.
     *
     * ADR-106 decision 2 (2026-09-18): this query is entirely
     * type-agnostic (no `attempt_type` filter), so it keeps working
     * unchanged now that an `initial` attempt can also land on
     * `outcome=pending` (a first-ever call that happens to hit
     * Digiflazz's async rc=03) — `recordFulfillmentAttempt()` below
     * writes that row, this method resolves it exactly like it always
     * resolved a resend's pending row. Still a genuine no-op for an
     * order whose very first attempt landed on a terminal outcome
     * (nothing pending to resolve).
     */
    private function resolvePendingResendAttempt(int $orderId, string $outcome, mixed $supplierResponse): void
    {
        OrderResendAttempt::query()
            ->where('order_id', $orderId)
            ->whereNull('order_delivery_leg_id')
            ->where('outcome', 'pending')
            ->latest('id')
            ->first()
            ?->update(['outcome' => $outcome, 'supplier_response' => $supplierResponse]);
    }

    /**
     * ADR-106 addendum (2026-09-21) — the leg-scoped twin of
     * resolvePendingResendAttempt() above: a combo leg's own `pending`
     * attempt row (written with the claim, ADR-103 decision 10) is
     * resolved here by whichever writer learns the outcome: the supplier
     * call itself, or finalizePendingDeliveryLeg() (webhook or poll). So
     * no leg leaves its attempt history stuck at `outcome=pending`.
     */
    private function resolvePendingLegAttempt(int $legId, string $outcome, mixed $supplierResponse): void
    {
        OrderResendAttempt::query()
            ->where('order_delivery_leg_id', $legId)
            ->where('outcome', 'pending')
            ->latest('id')
            ->first()
            ?->update(['outcome' => $outcome, 'supplier_response' => $supplierResponse]);
    }

    /**
     * ADR-106 decision 3, widened by its 2026-09-21 addendum: the
     * durable counterpart to the admin's synthesized "Initial Delivery"
     * row — writes an `order_resend_attempts` row for EVERY fulfillment
     * attempt now, not just the first (`attempt_type` discriminates
     * `initial` vs `retry`), called from inside fulfill()'s own
     * transaction (all three outcome branches) right after $locked's
     * delivery_status/supplier_response have been updated for this
     * call's real outcome, so it only ever commits alongside that
     * outcome — never orphaned by a mid-flight exception.
     *
     * `triggered_by`/`note` come from wherever this call originated —
     * an admin's `retryDelivery()` click, or null for every
     * system-triggered dispatch (CHIP webhook, checkout, reseller
     * placement, both reconciliation paths). `package_id`/
     * `cost_price_sen`/`standard_selling_price_sen` are the order's own
     * checkout-frozen values — a plain retry never swaps package (only
     * `OrderResendService::resend()` can, ADR-017). `price_diff_sen` is
     * always null here (decision 4) — no live-cost comparison is ever
     * made on this path, unlike a resend's real diff.
     */
    private function recordFulfillmentAttempt(Order $locked, bool $wasNotStarted, ?string $triggeredBy, ?string $note): void
    {
        OrderResendAttempt::query()->create([
            'order_id' => $locked->id,
            'attempt_type' => $wasNotStarted ? 'initial' : 'retry',
            'package_id' => $locked->package_id,
            'cost_price_sen' => $locked->cost_price,
            'standard_selling_price_sen' => $locked->standard_selling_price,
            'price_diff_sen' => null,
            'outcome' => match ($locked->delivery_status) {
                DeliveryStatus::Delivered => 'success',
                DeliveryStatus::Pending => 'pending',
                // Mirrors OrderResendService::resend()'s own match —
                // NeedsReview collapses into 'failed' here too, the
                // same established convention this table's outcome
                // column already uses for a resend attempt.
                default => 'failed',
            },
            'supplier_response' => $locked->supplier_response,
            'note' => $note,
            'triggered_by' => $triggeredBy,
        ]);
    }

    /**
     * ADR-106 addendum (2026-09-21) — the leg-scoped twin of
     * recordFulfillmentAttempt() above, closing decision 1's own
     * "non-combo only for now" deferral: a combo leg's own mutable
     * columns (`status`/`failure_reason`/`supplier_reference`) get
     * overwritten on every attempt exactly like a plain order's used to
     * before ADR-106 — this writes the leg's own durable per-attempt
     * row instead. ADR-103 decision 10: written `pending` with the claim,
     * so a killed worker still leaves it with the right `attempt_type`;
     * resolvePendingLegAttempt() closes it with the real outcome.
     *
     * `package_id` here is the LEG's own component package, not the
     * order's (a combo order's own `package_id` is the combo SKU
     * itself, meaningless per-leg). `standard_selling_price_sen` reads
     * the leg's own frozen `selling_price_sen` (ADR-107 decision 1).
     * `cost_price_sen` reads the component's LIVE cost — no frozen
     * snapshot exists for it (same reason `resolveComboOutcome()`'s own
     * profit reconciliation reads it live, see that method's doc
     * comment) — and `price_diff_sen` stays null (grill Q3, deferred):
     * this addendum closes the missing-audit-trail gap, not a
     * leg-level cost-drift reconciliation, which is ADR-107's own
     * domain.
     */
    private function recordLegAttempt(OrderDeliveryLeg $lockedLeg, bool $wasLegNotStarted, ?string $triggeredBy, ?string $note): void
    {
        OrderResendAttempt::query()->create([
            'order_id' => $lockedLeg->order_id,
            'order_delivery_leg_id' => $lockedLeg->id,
            'attempt_type' => $wasLegNotStarted ? 'initial' : 'retry',
            'package_id' => $lockedLeg->component_package_id,
            'cost_price_sen' => $lockedLeg->componentPackage->cost_price,
            'standard_selling_price_sen' => $lockedLeg->selling_price_sen,
            'price_diff_sen' => null,
            // ADR-103 decision 10: written with the claim, so a killed worker
            // still leaves it. resolvePendingLegAttempt() closes it.
            'outcome' => 'pending',
            'supplier_response' => null,
            'note' => $note,
            'triggered_by' => $triggeredBy,
        ]);
    }

    /**
     * ADR-026 decision 4a — the one exit from `needs_review` that isn't
     * a retry: an admin manually cross-referenced Gamevion's own
     * dashboard (no automated lookup exists, see this class's own
     * doc comment) and confirmed the real invoice. Deliberately requires
     * that invoice number as input, not just a confirm click — it
     * closes the exact `supplier_ref` gap that caused the ambiguity in
     * the first place, giving this order the same audit trail a normal
     * delivery would have had. Same lock/transaction discipline as
     * fulfill() itself, and the same ledger-credit + voucher-commit
     * calls a normal successful delivery makes — this order genuinely
     * is delivered now, just confirmed by a human instead of a live API
     * response.
     *
     * ADR-106 decision 3 (2026-09-18) — the third gap this ADR found:
     * this method overwrites `Order.supplier_response`/`delivery_status`
     * exactly like fulfill() does, via a third code path with no
     * `order_resend_attempts` row of its own before now. Writes an
     * `attempt_type=manual_confirm` row so the durable log shows how a
     * needs_review order actually reached Delivered — outcome is always
     * 'success' (this method exists only to confirm a delivery, never a
     * failure), `note`/`triggered_by` come from this call's own params.
     */
    public function markDeliveredManually(Order $order, string $supplierRef, float $supplierPrice, ?string $note, string $confirmedBy): Order
    {
        $delivered = DB::transaction(function () use ($order, $supplierRef, $supplierPrice, $note, $confirmedBy) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            $deliveredStatus = $this->orderStatus->markDeliveredManually($locked->delivery_status);

            // ADR-127 decision 11: re-checked under the lock; the
            // controller's own check can go stale before it.
            if ($locked->isAlreadyCompensated()) {
                throw ValidationException::withMessages([
                    'delivery_status' => ['This order has already been compensated (voucher issued/restored or wallet refunded) — it cannot be marked delivered.'],
                ]);
            }

            Log::withContext(['reference_number' => $locked->reference_number]);

            // ADR-127 PR D: the price the admin read off the supplier's
            // dashboard with the invoice, in the supplier's currency. It
            // is the real cost (ADR-111) and the drawdown, as a webhook's
            // `price` would have been.
            $supplierResponse = [
                'manually_confirmed' => true,
                'confirmed_by' => $confirmedBy,
                'note' => $note,
                'price' => $supplierPrice,
                'confirmed_at' => now()->toISOString(),
            ];
            $realCostPriceSen = $locked->supplier === null
                ? null
                : $this->captureRealCostSen($supplierResponse, $locked->supplier);

            $updateData = [
                'supplier_ref' => $supplierRef,
                'supplier_response' => $supplierResponse,
                'delivery_status' => $deliveredStatus->value,
                'delivered_at' => now(),
                'real_cost_price_sen' => $realCostPriceSen,
            ];

            if ($realCostPriceSen !== null) {
                $updateData += $this->reconcileRealCostProfit($locked, $realCostPriceSen);
            }

            $locked->update($updateData);

            OrderResendAttempt::query()->create([
                'order_id' => $locked->id,
                'attempt_type' => 'manual_confirm',
                'package_id' => $locked->package_id,
                'cost_price_sen' => $locked->cost_price,
                'standard_selling_price_sen' => $locked->standard_selling_price,
                'price_diff_sen' => null,
                'outcome' => 'success',
                'supplier_response' => $locked->supplier_response,
                'note' => $note,
                'triggered_by' => $confirmedBy,
            ]);

            $this->creditProfit($locked);
            $this->commitInstruments($locked->id);

            Log::info('Delivery manually confirmed after needs_review', [
                'supplier_ref' => $supplierRef,
                'confirmed_by' => $confirmedBy,
            ]);

            return $locked->fresh();
        });

        // After commit, as every other drawdown (ADR-083 decision 3).
        $this->supplierFunding->recordOrderDrawdown($delivered, $supplierPrice);

        return $delivered;
    }

    /**
     * ADR-026 addendum (2026-09-16, found shipping ADR-098) — the
     * NeedsReview exit decision 4c's own rationale always assumed
     * existed but was never built: "An admin must resolve the order to
     * Delivered or a genuine Failed first" before Issue Voucher
     * becomes available. Structurally necessary for a
     * transactionAlreadyFormed order (ADR-098) — retry can never change
     * that outcome, so without this, that class of order has no exit
     * at all. Lands on plain Failed; Issue Voucher (VoucherController::
     * storeFromOrder(), unchanged) is a deliberately separate,
     * admin-triggered second step — this method credits nothing and
     * touches no ledger/voucher itself.
     *
     * Unlike markDeliveredManually()'s supplier_response OVERWRITE
     * (correct there — delivery succeeded, the prior ambiguous data no
     * longer matters), this MERGES: the original error_code/
     * error_message (the actual evidence this failed) stays, with the
     * admin's own confirmation appended alongside it, not replacing it.
     */
    public function confirmDeliveryFailed(Order $order, string $note, string $confirmedBy, array $evidence = []): Order
    {
        return DB::transaction(function () use ($order, $note, $confirmedBy, $evidence) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            // ADR-094 decision 31 — on a combo, the admin is confirming the
            // ambiguous legs failed: flip them, then roll up on the locked
            // legs. A Delivered leg makes it PartiallyDelivered, never a
            // whole-order Failed (which would over-compensate the goods
            // already received). A leg still Pending is still in flight.
            $failedStatus = $locked->deliveryLegs()->exists()
                ? $this->confirmComboLegsFailed($locked)
                : $this->orderStatus->markNeedsReviewAsFailed($locked->delivery_status);

            Log::withContext(['reference_number' => $locked->reference_number]);

            $locked->update([
                'supplier_response' => array_merge(
                    is_array($locked->supplier_response) ? $locked->supplier_response : [],
                    self::adminConfirmation($note, $confirmedBy) + $evidence,
                ),
                'delivery_status' => $failedStatus->value,
            ]);

            Log::warning('Delivery confirmed genuinely failed after needs_review', [
                'confirmed_by' => $confirmedBy,
                'resulting_status' => $failedStatus->value,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * ADR-102 2026-10-05 addendum, decision 4: Confirm Failed asked the
     * supplier and its answer already settled the order (Delivered,
     * Failed or PartiallyDelivered). Keeps the admin's note beside that
     * answer; changes no status.
     */
    public function recordAdminConfirmation(Order $order, string $note, string $confirmedBy): Order
    {
        return DB::transaction(function () use ($order, $note, $confirmedBy) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            $locked->update(['supplier_response' => array_merge(
                is_array($locked->supplier_response) ? $locked->supplier_response : [],
                self::adminConfirmation($note, $confirmedBy),
            )]);

            return $locked->fresh();
        });
    }

    /** @return array{confirmed_failed_by: string, note: string, confirmed_at: string} */
    private static function adminConfirmation(string $note, string $confirmedBy): array
    {
        return ['confirmed_failed_by' => $confirmedBy, 'note' => $note, 'confirmed_at' => now()->toISOString()];
    }

    /** Caller holds the order lock. Returns the order's new status. */
    private function confirmComboLegsFailed(Order $locked): DeliveryStatus
    {
        $legs = OrderDeliveryLeg::query()->where('order_id', $locked->id)->lockForUpdate()->get();

        // ADR-103 decision 15: a leg with a live or unanswered attempt is
        // still in flight. A leg with no reference has never been sent.
        if ($legs->contains(fn (OrderDeliveryLeg $leg) => in_array($leg->status, [DeliveryStatus::Pending, DeliveryStatus::Processing], true))) {
            throw new OrderFulfillmentException(
                "Order #{$locked->id} still has a leg awaiting the supplier — refusing to confirm it failed",
            );
        }

        foreach ($legs as $leg) {
            if (in_array($leg->status, [DeliveryStatus::NeedsReview, DeliveryStatus::NotStarted], true)) {
                $leg->update(['status' => DeliveryStatus::Failed->value]);
            }
        }

        return match (DeliveryStatus::comboRollup($legs->pluck('status'))) {
            DeliveryStatus::PartiallyDelivered => $this->orderStatus->markPartiallyDelivered($locked->delivery_status),
            DeliveryStatus::Failed => $this->orderStatus->markNeedsReviewAsFailed($locked->delivery_status),
            default => throw new OrderFulfillmentException("Order #{$locked->id} has no failed leg to confirm"),
        };
    }

    /**
     * ADR-102 addendum (2026-09-29): recorded on the order (and so on the
     * ADR-106 attempt row) wherever an ambiguous outcome was auto-parked
     * at Pending instead of NeedsReview — the audit trail for decision 8.
     */
    public const AUTO_RECOVERY_NOTE = 'Ambiguous outcome — Pending, re-polled with the same reference (supplier replays a known ref).';

    /** Digiflazz: don't re-check a ref within 1 minute; the breaker cools down in 60s. */
    private const RECOVERY_POLL_DELAY_MINUTES = 2;

    private function resubmitReplaysOutcome(Order $order): bool
    {
        return SupplierAdapterFactory::resubmitReplaysOutcome($order->supplier);
    }

    /** Processing → Pending, keeping the stored reference; caller holds the row lock. */
    private function parkAmbiguousAsPending(Order $locked, array $supplierResponse): void
    {
        $locked->update([
            'delivery_status' => $this->orderStatus->markPending($locked->delivery_status)->value,
            'supplier_response' => $supplierResponse + ['auto_recovery' => self::AUTO_RECOVERY_NOTE],
        ]);
    }

    /** Public so the reconcile sweep's stale-Processing recovery reuses the same poll. */
    public function scheduleRecoveryPoll(Order $order): void
    {
        CheckSupplierDeliveryJob::dispatch($order)->delay(now()->addMinutes(self::RECOVERY_POLL_DELAY_MINUTES));
    }

    /**
     * ADR-111 decision 2/6, ADR-134 decisions 5–6: converts the supplier's
     * own real per-transaction price (`$resultData['price']`, when present)
     * into MYR sen at the supplier's landed cost rate — what its deposit
     * cost — rounded like the pool, so this order's cost is the cost its
     * drawdown gets in COGS. Returns null — never throws, never blocks
     * delivery — when no price was reported or the supplier has no
     * deposit; the caller then keeps the checkout cost for this delivery.
     */
    private function captureRealCostSen(?array $resultData, Supplier $supplier): ?int
    {
        if (! isset($resultData['price'])) {
            return null;
        }

        $sen = $this->landedCost->toCostSen($supplier, (float) $resultData['price']);

        if ($sen === null) {
            // A foreign supplier with no recorded deposit can't be priced (ADR-134 decision 4); the order keeps its checkout cost.
            Log::warning('Real-cost reconciliation: no landed cost rate for this supplier, falling back to catalog cost for this delivery', [
                'supplier_id' => $supplier->id,
            ]);
        }

        return $sen;
    }

    /**
     * ADR-111 decision 3: the one basis-agnostic residual formula,
     * `platform_profit = selling_price (frozen) − real_cost − affiliate_profit`
     * — the same identity ADR-105 decision 8 established, now fed by
     * the real per-transaction cost instead of a catalog snapshot.
     * `affiliate_profit` is never touched: whatever checkout or
     * `OrderResendService::resend()`'s own per-basis formula already
     * determined for it stays exactly as-is (decision 3's own text) —
     * only `platform_profit` absorbs the gap between what was assumed
     * and what was actually paid. Returns the fields to merge into the
     * caller's own `update()` call, never writes directly, so this
     * stays a plain calculation the caller applies alongside whatever
     * else that same call is already updating (decision 2's "no extra
     * query, no extra transaction").
     *
     * @return array{platform_profit: int, profit_reconciled_flagged: bool, cost_rate: string}
     */
    private function reconcileRealCostProfit(Order $locked, int $realCostPriceSen): array
    {
        $reconciledProfit = $locked->selling_price - $realCostPriceSen - $locked->affiliate_profit;
        $flagged = $this->isProfitDriftFlagged($locked->platform_profit, $reconciledProfit, $locked->selling_price);

        if ($flagged) {
            Log::warning('Real-cost profit reconciliation flagged this delivery', [
                'order_id' => $locked->id,
                'estimated_platform_profit' => $locked->platform_profit,
                'reconciled_platform_profit' => $reconciledProfit,
                'real_cost_price_sen' => $realCostPriceSen,
            ]);
        }

        return [
            'platform_profit' => $reconciledProfit,
            'profit_reconciled_flagged' => $flagged,
            // The profit now rests on a landed cost, whatever the checkout estimate was (ADR-134 decision 13).
            'cost_rate' => Order::COST_RATE_LANDED,
        ];
    }

    /**
     * ADR-111 decision 7: the universal visibility signal — fires on any
     * negative reconciled profit (unconditionally), OR a material drift
     * from the pre-reconciliation estimate that's BOTH more than RM1
     * (100 sen) AND more than 1% of `selling_price` (a flat sen
     * threshold alone under-protects a large order; a percentage alone
     * over-fires on a small, normally-volatile one). Starting numbers,
     * not empirically tuned — revisit once real resend/combo volume at
     * varied order sizes exists (ADR-111's own Consequence-to-track).
     */
    private function isProfitDriftFlagged(int $previousEstimatedProfit, int $reconciledProfit, int $sellingPrice): bool
    {
        if ($reconciledProfit < 0) {
            return true;
        }

        $driftSen = abs($reconciledProfit - $previousEstimatedProfit);

        return $driftSen > 100 && $driftSen > (int) round($sellingPrice * 0.01);
    }

    /**
     * Every delivered order writes exactly two order_profit credit
     * entries (PRD §8 LedgerEntry) — kept as two rows even though MVP
     * has only the single internal owner-affiliate, so this never needs
     * to change when Phase 2 onboards real third-party affiliates.
     *
     * ADR-018 decision #6: the single, explicit guard that keeps a
     * sandbox order from ever reaching the real ledger — chosen over a
     * parallel "sandbox fulfillment service" so every other line above
     * this method stays 100% shared and unduplicated between real and
     * test orders.
     */
    /**
     * ADR-125 decision 49: a manual supplier's Pending is the moment its
     * WhatsApp group is told. After commit (never post a rolled-back
     * attempt), and never allowed to fail the delivery; the notifier skips
     * a sandbox order and an API supplier.
     */
    private function postToManualSupplierAfterCommit(Order $order): void
    {
        if ($order->is_test || $order->supplier === null || $order->supplier->isApiBacked()) {
            return;
        }

        $orderId = $order->id;
        DB::afterCommit(function () use ($orderId) {
            try {
                app(SupplierGroupNotifier::class)->orderPosted($orderId);
            } catch (Throwable $e) {
                Log::warning('Supplier group post not queued', ['order_id' => $orderId, 'exception' => $e->getMessage()]);
            }
        });
    }

    /**
     * ADR-024 decision 6 + ADR-118 V9: both payment and delivery succeeded,
     * so any reserved voucher or promo redemption on the order is now
     * permanent. No-op for an order that used neither.
     */
    private function commitInstruments(int $orderId): void
    {
        $this->vouchers->commit($orderId);
        $this->campaigns->commit($orderId);
    }

    private function creditProfit(Order $order): void
    {
        if ($order->is_test) {
            return;
        }

        $this->ledger->creditOrderProfit($order);

        // ADR-116 decision 4: every path into Delivered runs through here,
        // so this is the one receipt trigger. After commit (never message
        // for a rolled-back delivery), and never allowed to fail the delivery.
        $orderId = $order->id;
        DB::afterCommit(function () use ($orderId) {
            try {
                app(CustomerNotificationService::class)->orderDelivered($orderId);
            } catch (Throwable $e) {
                Log::warning('Delivered receipt not queued', ['order_id' => $orderId, 'exception' => $e->getMessage()]);
            }
        });
    }
}
