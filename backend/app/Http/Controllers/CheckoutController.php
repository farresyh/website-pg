<?php

namespace App\Http\Controllers;

use App\Http\Requests\Checkout\CreateCheckoutRequest;
use App\Models\Game;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlayerValidation;
use App\Models\Reseller;
use App\Services\Checkout\CheckoutFailedException;
use App\Services\Checkout\CheckoutRequest;
use App\Services\Checkout\CheckoutService;
use App\Services\Fraud\BlacklistService;
use App\Services\Fraud\CheckoutVelocityGuard;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Pricing\PaymentMethodFeeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Public, guest-checkout endpoint (ADR-011 — no Customer auth exists;
 * anyone can call this). Resolves Game/Package/Reseller, enforces the
 * game's per-game validation_rules (ADR-005 addendum — Gamevion's
 * order endpoint has no field schema of its own, so we must know
 * whether a second field beyond Player ID is required before ever
 * reaching the supplier), re-checks player-ID validation server-side
 * when the game requires it (see assertPlayerIdIsValidated()), then
 * hands off to the already-tested
 * CheckoutService for pricing/Order-creation/payment-request logic —
 * this controller does not compute or trust any money value itself
 * (ORD-9's principle: cost/reseller-cost come from the stored
 * Package, never from client input).
 *
 * No voucher-at-checkout support yet (VoucherService::redeem() exists
 * but isn't wired here) — deliberately out of scope for this pass, see
 * docs/prd.md §14.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentGatewayFactory $gatewayFactory,
        private readonly PaymentMethodFeeResolver $fees,
        private readonly BlacklistService $blacklist,
        private readonly CheckoutVelocityGuard $velocityGuard,
    ) {
    }

    public function store(CreateCheckoutRequest $request): JsonResponse
    {
        $data = $request->validated();

        $game = Game::query()->findOrFail($data['game_id']);
        $package = Package::query()->findOrFail($data['package_id']);

        if ($package->game_id !== $game->id) {
            throw ValidationException::withMessages([
                'package_id' => ['This package does not belong to the selected game.'],
            ]);
        }

        if (! $game->is_active || ! $package->is_active) {
            throw ValidationException::withMessages([
                'package_id' => ['This package is not currently available.'],
            ]);
        }

        $extraField = $game->validation_rules['extra_field'] ?? null;

        if ($extraField !== null && empty($data['server_id'])) {
            throw ValidationException::withMessages([
                'server_id' => ["This game requires a {$this->fieldLabel($extraField)}."],
            ]);
        }

        if ($game->player_validator_enabled && $game->player_validator_profile_id !== null) {
            $this->assertPlayerIdIsValidated($game, $data['player_id'], $data['server_id'] ?? null);
        }

        $this->assertNotBlacklisted($data['player_id'], $data['customer_email'], $data['customer_phone'] ?? null, $request->ip());

        // PRD §8 / ADR-013: exactly one Reseller row for MVP (the
        // platform owner, markup_pct=0) — see Reseller::platformOwner()
        // for the firstOrCreate safety-net rationale.
        $reseller = Reseller::platformOwner();

        // Guaranteed to exist + be active by CreateCheckoutRequest's
        // Rule::exists check — a race between validation and here
        // (channel deactivated mid-request) surfaces as a 500 via
        // firstOrFail(), not a silent fallback to some default
        // gateway, since that would misroute a real payment.
        $paymentMethod = PaymentMethod::query()
            ->where('channel_code', $data['channel_code'])
            ->firstOrFail();
        $gateway = $this->gatewayFactory->make($paymentMethod->gateway);

        try {
            $order = $this->checkout->initiate(new CheckoutRequest(
                customerEmail: $data['customer_email'],
                customerName: $data['customer_name'],
                customerPhone: $data['customer_phone'] ?? null,
                playerId: $data['player_id'],
                serverId: $data['server_id'] ?? null,
                costPriceSen: $package->cost_price,
                resellerCostPriceSen: $package->reseller_cost_price,
                resellerMarkupPct: (float) $reseller->markup_pct,
                paymentFeeConfig: $this->fees->resolve($data['channel_code']),
                paymentMethod: $paymentMethod->category,
                channelCode: $data['channel_code'],
                channelProperties: $data['channel_properties'] ?? [],
                supplierProductRef: $package->supplier_package_ref,
                gameId: $game->id,
                packageId: $package->id,
                supplierId: $package->supplier_id,
                resellerId: $reseller->id,
            ), $gateway);
        } catch (CheckoutFailedException $e) {
            // ADR-019: previously silent — no record anywhere of *why*
            // a checkout failed. game_id/package_id/channel_code are
            // enough to reproduce without logging customer PII.
            Log::warning('Checkout failed', [
                'game_id' => $game->id,
                'package_id' => $package->id,
                'channel_code' => $data['channel_code'],
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'payment' => [$e->getMessage()],
            ]);
        }

        // CheckoutService::initiate() only persists payment_ref onto
        // the Order, not the gateway's checkout-URL/QR "actions"
        // payload — fetched separately here via the same resolved
        // gateway (getPayment(), already implemented and tested)
        // rather than changing CheckoutService's own tested contract.
        $payment = $gateway->getPayment($order->payment_ref);

        return response()->json([
            'order_number' => $order->order_number,
            'final_amount' => $order->final_amount,
            'payment_status' => $order->payment_status,
            'payment_actions' => $payment->success ? ($payment->data['actions'] ?? []) : [],
        ], 201);
    }

    private function fieldLabel(string $extraField): string
    {
        return match ($extraField) {
            'zone_id' => 'Zone ID',
            'server_id' => 'Server ID',
            default => 'additional field',
        };
    }

    /**
     * PlayerValidationController's `POST /api/games/{game}/validate-player`
     * only ever gated the storefront wizard's own "Proceed to Payment"
     * button client-side — a UI nicety, not a security boundary, since
     * guest checkout (ADR-011) has no session to actually own that gate.
     * A direct `POST /api/checkout` call could always skip validation
     * entirely. Re-check server-side here: the exact game/player_id
     * (/server_id) tuple must have a recent `status=valid` row, matching
     * on data rather than on any per-session token — deliberately not
     * stricter than that (founder call, 2026-07-27 audit follow-up).
     */
    private function assertPlayerIdIsValidated(Game $game, string $playerId, ?string $serverId): void
    {
        $windowMinutes = (int) config('services.player_validation.checkout_window_minutes', 30);

        $validated = PlayerValidation::query()
            ->where('game_id', $game->id)
            ->where('player_id', $playerId)
            ->where('server_id', $serverId)
            ->where('status', 'valid')
            ->where('validated_at', '>=', now()->subMinutes($windowMinutes))
            ->exists();

        if (! $validated) {
            throw ValidationException::withMessages([
                'player_id' => ['Please validate this Player ID before checking out.'],
            ]);
        }
    }

    /**
     * ADR-007 / FRAUD-2/4, per prd.md §7.1 step 5 - checked before
     * payment or supplier submission. The velocity guard is checked
     * first: an IP already over FRAUD-4's threshold is rejected
     * without even running the blacklist query, since by then it's
     * already demonstrated probing behavior regardless of whether
     * this particular attempt happens to match an entry. The rejection
     * message is deliberately generic either way - never reveals
     * *why* (blacklisted vs. rate-limited), so a real fraudster can't
     * use the response to distinguish "wrong value, try another" from
     * "you're rate-limited, wait it out."
     */
    private function assertNotBlacklisted(string $playerId, string $email, ?string $phone, ?string $ip): void
    {
        if ($ip !== null && $this->velocityGuard->tooManyRecentHits($ip)) {
            throw ValidationException::withMessages([
                'player_id' => ['This order cannot be processed.'],
            ]);
        }

        $entry = $this->blacklist->check($playerId, $email, $phone);

        if ($entry === null) {
            return;
        }

        $this->blacklist->recordHit($entry, $playerId, $email, $phone, $ip);

        if ($ip !== null) {
            $this->velocityGuard->recordHit($ip);
        }

        Log::warning('Checkout blocked by blacklist', [
            'blacklist_entry_id' => $entry->id,
        ]);

        throw ValidationException::withMessages([
            'player_id' => ['This order cannot be processed.'],
        ]);
    }
}
