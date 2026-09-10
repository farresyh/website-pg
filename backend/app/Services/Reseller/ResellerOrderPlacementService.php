<?php

namespace App\Services\Reseller;

use App\Jobs\FulfillOrderJob;
use App\Models\Affiliate;
use App\Models\Order;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DuplicateOrderException;
use App\Services\Order\OrderDraft;
use App\Services\Order\OrderFactory;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\OrderPricingResolver;
use Illuminate\Support\Facades\DB;

/**
 * ADR-073 decision 4: the ONE internal order-placement contract both the
 * Reseller API (ADR-074) and Reseller Bot (ADR-075) channels call —
 * their money/tier/order logic is byte-for-byte identical, only the
 * transport and identification mechanism differ (ADR-072 decision 1).
 * Neither channel exists yet (PR-E/PR-F) — this service has no HTTP
 * route of its own in this PR, same shape `CheckoutService` had before
 * `CheckoutController` existed.
 */
final class ResellerOrderPlacementService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly OrderPricingResolver $pricingResolver,
        private readonly OrderFactory $orderFactory,
    ) {}

    /**
     * Contract order, per decision 4: is_active gate → idempotency
     * check (no-op replay, never a second debit) → resolve tier price →
     * one `DB::transaction()` (lock the wallet ledger account, check
     * balance, debit, create the Order) → dispatch `FulfillOrderJob`
     * only after that transaction commits (`config/queue.php`'s
     * `after_commit => false` — the same race `CheckoutService::
     * settleWithVoucher()` already avoids).
     *
     * Pricing goes through `OrderPricingResolver::resolveResellerWallet()`
     * (ADR-060 PR-4b — the same seam the storefront checkout uses, so the
     * three inline `calculateForAffiliate(…, 0.0)` copies in this channel
     * are gone): wholesale base `cost × (1 + tier%)`, no affiliate margin,
     * `affiliateProfit = 0` by construction (ADR-073 decision 6), and the
     * tier markup snapshotted onto `orders.wholesale_markup_pct`.
     *
     * ADR-084 PR-1 decision 5: returns a `ResellerOrderPlacementResult` so
     * a caller can tell a fresh placement from an idempotent replay. When
     * `$request->payloadHash` is set (Reseller API), a replay whose stored
     * hash differs throws `IdempotencyKeyPayloadMismatchException` — the
     * same key was reused for a genuinely different order.
     */
    public function placeOrder(Reseller $reseller, ResellerOrderPlacementRequest $request): ResellerOrderPlacementResult
    {
        if (! $reseller->is_active) {
            throw new ResellerInactiveException("Reseller #{$reseller->id} is deactivated.");
        }

        $existing = Order::query()->where('checkout_idempotency_key', $request->idempotencyKey)->first();
        if ($existing !== null) {
            $this->assertPayloadMatches($existing, $request->payloadHash);

            return new ResellerOrderPlacementResult($existing, wasReplay: true);
        }

        if ($reseller->reseller_tier_id === null) {
            throw new NoResellerTierAssignedException("Reseller #{$reseller->id} has no wallet tier assigned.");
        }

        $tier = $reseller->tier;
        $pricing = $this->pricingResolver->resolveResellerWallet(
            $request->costPriceSen,
            $request->standardSellingPriceSen,
            (float) $tier->markup_percent,
        );

        $primaryAffiliateId = Affiliate::primary()->id;

        try {
            $order = DB::transaction(function () use ($reseller, $request, $pricing, $primaryAffiliateId) {
                $order = $this->orderFactory->create(new OrderDraft(
                    pricing: $pricing,
                    idempotencyKey: $request->idempotencyKey,
                    customerEmail: $reseller->email ?? "wallet+reseller-{$reseller->id}@pekangame.internal",
                    customerName: $reseller->business_name,
                    customerPhone: $reseller->phone,
                    playerId: $request->playerId,
                    serverId: $request->serverId,
                    affiliateId: $primaryAffiliateId,
                    paymentStatus: PaymentStatus::Paid,
                    paidAt: now(),
                    paymentMethod: 'wallet',
                    gameId: $request->gameId,
                    packageId: $request->packageId,
                    supplierId: $request->supplierId,
                    supplierProductRef: $request->supplierProductRef,
                    walletResellerId: $reseller->id,
                    resellerApiIdempotencyPayloadHash: $request->payloadHash,
                ));

                // ADR-073 decision 4: debit AFTER the Order exists (not
                // before, as the decision's prose ordering literally
                // reads) so the ledger entry carries a real
                // reference_type/reference_id back to it — same trail
                // every other order_profit/withdrawal credit already
                // leaves. Atomicity is identical either way: a thrown
                // InsufficientBalanceException rolls back this whole
                // transaction, so a rejected debit still leaves no Order
                // row committed anywhere — the decision's actual load-
                // bearing requirement.
                $this->ledger->debit(
                    LedgerOwnerType::ResellerWallet,
                    $reseller->id,
                    $pricing->sellingPriceSen,
                    'wallet_debit',
                    referenceType: 'order',
                    referenceId: $order->id,
                );

                return $order;
            });
        } catch (DuplicateOrderException) {
            // Lost a genuine race — a concurrent request with the same
            // idempotency key won the INSERT between our lookup above and
            // now (OrderFactory maps the unique-constraint violation to
            // this). Same no-op-replay outcome as finding it up front,
            // never a second debit — the transaction rolled back before
            // `debit()` ran.
            $raced = Order::query()->where('checkout_idempotency_key', $request->idempotencyKey)->firstOrFail();
            $this->assertPayloadMatches($raced, $request->payloadHash);

            return new ResellerOrderPlacementResult($raced, wasReplay: true);
        }

        // ADR-073 decision 4: dispatched only after the transaction above
        // has committed — config/queue.php sets after_commit => false on
        // every connection including redis, so a job dispatched from
        // inside the transaction risks a worker grabbing it before the
        // commit is visible. Mirrors CheckoutService::settleWithVoucher().
        FulfillOrderJob::dispatch($order->fresh());

        return new ResellerOrderPlacementResult($order->fresh(), wasReplay: false);
    }

    /**
     * ADR-084 PR-1 decision 5. No-op when the caller supplied no hash (the
     * Bot channel) or when the stored order predates payload hashing —
     * only a real mismatch of two present hashes is a conflict.
     */
    private function assertPayloadMatches(Order $existing, ?string $payloadHash): void
    {
        if ($payloadHash === null || $existing->reseller_api_idempotency_payload_hash === null) {
            return;
        }

        if (! hash_equals($existing->reseller_api_idempotency_payload_hash, $payloadHash)) {
            throw new IdempotencyKeyPayloadMismatchException(
                "Idempotency key {$existing->checkout_idempotency_key} was reused with a different payload.",
            );
        }
    }
}
