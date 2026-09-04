<?php

namespace App\Services\Reseller;

use App\Jobs\FulfillOrderJob;
use App\Models\Affiliate;
use App\Models\Order;
use App\Models\Reseller;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\OrderNumberService;
use App\Services\Order\PaymentStatus;
use App\Services\Pricing\PricingBasis;
use App\Services\Pricing\PricingService;
use Illuminate\Database\QueryException;
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
        private readonly PricingService $pricing,
        private readonly OrderNumberService $orderNumbers,
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
     * Pricing reuses `PricingService::calculateForAffiliate()` with
     * `affiliateMarkupPct = 0.0` — the exact "wholesale base = cost ×
     * (1 + tierMarkupPct/100), no markup layered on top" shape ADR-073
     * decision 1 calls for (its own "same math as ADR-056 decision 2"),
     * and `affiliateMarkupPct = 0` makes `affiliateProfit` come back 0
     * for free — decision 6's "no reseller_profit line" is satisfied by
     * construction, not a branch to remember.
     */
    public function placeOrder(Reseller $reseller, ResellerOrderPlacementRequest $request): Order
    {
        if (! $reseller->is_active) {
            throw new ResellerInactiveException("Reseller #{$reseller->id} is deactivated.");
        }

        $existing = Order::query()->where('checkout_idempotency_key', $request->idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($reseller->reseller_tier_id === null) {
            throw new NoResellerTierAssignedException("Reseller #{$reseller->id} has no wallet tier assigned.");
        }

        $tier = $reseller->tier;
        $pricing = $this->pricing->calculateForAffiliate(
            $request->costPriceSen,
            $request->standardSellingPriceSen,
            (float) $tier->markup_percent,
            0.0,
        );

        $primaryAffiliateId = Affiliate::primary()->id;

        try {
            $order = DB::transaction(function () use ($reseller, $request, $pricing, $primaryAffiliateId) {
                $order = Order::query()->create([
                    'order_number' => $this->orderNumbers->generate(),
                    'checkout_idempotency_key' => $request->idempotencyKey,
                    'customer_email' => $reseller->email ?? "wallet+reseller-{$reseller->id}@pekangame.internal",
                    'customer_name' => $reseller->business_name,
                    'customer_phone' => $reseller->phone,
                    'player_id' => $request->playerId,
                    'server_id' => $request->serverId,
                    'game_id' => $request->gameId,
                    'package_id' => $request->packageId,
                    'supplier_id' => $request->supplierId,
                    'supplier_product_ref' => $request->supplierProductRef,
                    'affiliate_id' => $primaryAffiliateId,
                    'wallet_reseller_id' => $reseller->id,
                    'pricing_basis' => PricingBasis::ResellerWallet->value,
                    'cost_price' => $pricing->costPrice,
                    'standard_selling_price' => $pricing->standardSellingPrice,
                    'selling_price' => $pricing->sellingPrice,
                    'voucher_discount' => 0,
                    'transaction_fee' => 0,
                    'final_amount' => $pricing->sellingPrice,
                    'platform_profit' => $pricing->platformProfit,
                    'affiliate_profit' => $pricing->affiliateProfit,
                    'payment_status' => PaymentStatus::Paid->value,
                    'paid_at' => now(),
                    'delivery_status' => DeliveryStatus::NotStarted->value,
                    'payment_method' => 'wallet',
                ]);

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
                    $pricing->sellingPrice,
                    'wallet_debit',
                    referenceType: 'order',
                    referenceId: $order->id,
                );

                return $order;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                // Lost a genuine race — a concurrent request with the
                // same idempotency key won the INSERT between our lookup
                // above and now. Same no-op-replay outcome as finding it
                // up front, never a second debit.
                return Order::query()->where('checkout_idempotency_key', $request->idempotencyKey)->firstOrFail();
            }

            throw $e;
        }

        // ADR-073 decision 4: dispatched only after the transaction above
        // has committed — config/queue.php sets after_commit => false on
        // every connection including redis, so a job dispatched from
        // inside the transaction risks a worker grabbing it before the
        // commit is visible. Mirrors CheckoutService::settleWithVoucher().
        FulfillOrderJob::dispatch($order->fresh());

        return $order->fresh();
    }

    /** Mirrors CheckoutService::isUniqueConstraintViolation()'s own check. */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
