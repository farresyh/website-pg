<?php

namespace App\Services\Fulfillment;

use App\Models\Order;
use App\Models\OrderResendAttempt;
use App\Models\Package;
use App\Models\PlayerValidation;
use App\Services\Order\DeliveryStatus;
use App\Services\Pricing\PricingService;
use Illuminate\Validation\ValidationException;

/**
 * ADR-017: the richer counterpart to OrderFulfillmentService's own
 * plain idempotent resend (ORD-7's retryDelivery) — lets an admin swap
 * to a different Package (same Game only, decision #1), reconciles
 * that package's live cost against what the customer actually paid
 * (decision #3), and records every attempt in `order_resend_attempts`
 * (decision #4), not just the latest one.
 *
 * Deliberately does not touch OrderFulfillmentService's own interface
 * — this only prepares the Order row (package/profit fields) before
 * delegating the actual supplier call and delivery-status transition
 * to it unchanged, same "deepen the module, don't reshape the seam"
 * discipline as ADR-001/ADR-006/ADR-014.
 */
final class OrderResendService
{
    public function __construct(
        private readonly OrderFulfillmentService $fulfillment,
        private readonly PricingService $pricing,
    ) {
    }

    /**
     * @throws ValidationException when the target package isn't a
     *         same-game swap (decision #1), isn't active, the order
     *         isn't currently in a resendable state, or the game
     *         requires player-ID validation that hasn't happened
     *         recently (decision #6).
     */
    public function resend(Order $order, Package $targetPackage, ?string $note, ?string $triggeredBy): Order
    {
        $this->assertResendable($order);
        $this->assertSameGamePackage($order, $targetPackage);
        $this->assertPlayerIdIsValidatedIfRequired($order);

        // Decision #3: the live reconciliation figures, captured at
        // the moment of this attempt — never the order's own frozen
        // cost_price/reseller_cost_price (decision #2, untouched).
        $liveCostPrice = $targetPackage->cost_price;
        $liveResellerCostPrice = $targetPackage->reseller_cost_price;
        $priceDiff = $liveCostPrice - $order->cost_price;

        // Decision #5: platform_profit/reseller_profit are recomputed
        // from this attempt's live package economics — same formula
        // PricingService already applies at checkout, reused here
        // rather than invented fresh — and set on Order *before*
        // calling fulfill(), so that if (and only if) this attempt is
        // the one that actually succeeds, creditProfit() inside
        // fulfill() credits the ledger with these exact figures.
        // final_amount/selling_price/transaction_fee are never part
        // of this update — decision #2/#5's immutability line.
        $breakdown = $this->pricing->calculate($liveCostPrice, $liveResellerCostPrice, (float) $order->reseller_markup_pct);

        $order->update([
            'package_id' => $targetPackage->id,
            'supplier_id' => $targetPackage->supplier_id,
            'supplier_product_ref' => $targetPackage->supplier_package_ref,
            'platform_profit' => $breakdown->platformProfit,
            'reseller_profit' => $breakdown->resellerProfit,
        ]);

        $result = $this->fulfillment->fulfill($order->fresh());

        OrderResendAttempt::query()->create([
            'order_id' => $result->id,
            'package_id' => $targetPackage->id,
            'cost_price_sen' => $liveCostPrice,
            'reseller_cost_price_sen' => $liveResellerCostPrice,
            'price_diff_sen' => $priceDiff,
            'outcome' => $result->delivery_status === DeliveryStatus::Delivered ? 'success' : 'failed',
            'supplier_response' => $result->supplier_response,
            'note' => $note,
            'triggered_by' => $triggeredBy,
        ]);

        return $result;
    }

    /**
     * Mirrors OrderController::retryDelivery()'s own guard exactly —
     * ADR-017 doesn't loosen it, it only adds package-swap/reconciliation
     * on top of the same "only a failed (or, per ADR-026, needs_review)
     * delivery can be resent" rule.
     */
    private function assertResendable(Order $order): void
    {
        if (! in_array($order->delivery_status, [DeliveryStatus::Failed, DeliveryStatus::NeedsReview], true)) {
            throw ValidationException::withMessages([
                'delivery_status' => ['Only an order with a failed or needs-review delivery can be resent.'],
            ]);
        }
    }

    private function assertSameGamePackage(Order $order, Package $targetPackage): void
    {
        if ($targetPackage->game_id !== $order->game_id) {
            throw ValidationException::withMessages([
                'package_id' => ['The selected package must belong to the same game as this order.'],
            ]);
        }

        if (! $targetPackage->is_active) {
            throw ValidationException::withMessages([
                'package_id' => ['This package is not currently active.'],
            ]);
        }
    }

    /**
     * Decision #6: reuses the exact same `player_validations` check
     * CheckoutController::assertPlayerIdIsValidated() already enforces
     * at checkout — same data-tuple match (game/player_id/server_id),
     * same rolling window — rather than a session/token mechanism this
     * architecture doesn't have (ADR-005's newest addendum's own
     * reasoning, unchanged here).
     */
    private function assertPlayerIdIsValidatedIfRequired(Order $order): void
    {
        $game = $order->game;

        if ($game === null || ! $game->player_validator_enabled || $game->player_validator_profile_id === null) {
            return;
        }

        $windowMinutes = (int) config('services.player_validation.checkout_window_minutes', 30);

        $validated = PlayerValidation::query()
            ->where('game_id', $game->id)
            ->where('player_id', $order->player_id)
            ->where('server_id', $order->server_id)
            ->where('status', 'valid')
            ->where('validated_at', '>=', now()->subMinutes($windowMinutes))
            ->exists();

        if (! $validated) {
            throw ValidationException::withMessages([
                'player_id' => ['Please re-validate this Player ID before resending.'],
            ]);
        }
    }
}
