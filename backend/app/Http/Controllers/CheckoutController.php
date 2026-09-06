<?php

namespace App\Http\Controllers;

use App\Http\Requests\Checkout\CreateCheckoutRequest;
use App\Http\Requests\Checkout\PreviewCheckoutTotalRequest;
use App\Models\Game;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PlatformSettings;
use App\Models\PlayerValidation;
use App\Services\Checkout\CheckoutFailedException;
use App\Services\Checkout\CheckoutRequest;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\DuplicateCheckoutAttemptException;
use App\Services\Fraud\BlacklistService;
use App\Services\Fraud\CheckoutVelocityGuard;
use App\Services\Membership\MembershipSessionTokenService;
use App\Services\Membership\MembershipStatus;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Pricing\PaymentMethodFeeResolver;
use App\Services\Voucher\InvalidVoucherException;
use App\Support\StorefrontBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Public, guest-checkout endpoint (ADR-011 — no Customer auth exists;
 * anyone can call this). Resolves Game/Package/Affiliate, enforces the
 * game's per-game validation_rules (ADR-005 addendum — Gamevion's
 * order endpoint has no field schema of its own, so we must know
 * whether a second field beyond Player ID is required before ever
 * reaching the supplier), re-checks player-ID validation server-side
 * when the game requires it (see assertPlayerIdIsValidated()), then
 * hands off to the already-tested
 * CheckoutService for pricing/Order-creation/payment-request logic —
 * this controller does not compute or trust any money value itself
 * (ORD-9's principle: cost/standard-selling-price come from the stored
 * Package, never from client input).
 *
 * Voucher-at-checkout (ADR-024): only `voucher_code` is ever accepted
 * from the client, passed through to CheckoutService untouched — the
 * discount amount, ownership match, and the real locked redemption are
 * all resolved server-side inside CheckoutService/VoucherService, this
 * controller never computes or trusts any of that itself either.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly PaymentGatewayFactory $gatewayFactory,
        private readonly PaymentMethodFeeResolver $fees,
        private readonly BlacklistService $blacklist,
        private readonly CheckoutVelocityGuard $velocityGuard,
        private readonly MembershipSessionTokenService $membershipSessionTokens,
        private readonly StorefrontBrand $storefrontBrand,
    ) {}

    public function store(CreateCheckoutRequest $request): JsonResponse
    {
        // ADR-028 decision 8: maintenance mode blocks new checkout
        // submissions only — browsing, order tracking, and payment/
        // webhook routes stay live on purpose, so a customer who
        // already paid can still track their order and an in-flight
        // webhook can still land and fulfil. Enforced right here, not
        // as a blanket route-group gate.
        $platformSettings = PlatformSettings::current();
        if ($platformSettings->maintenance_mode) {
            return response()->json([
                'message' => $platformSettings->maintenance_message
                    ?: 'The store is temporarily unavailable for maintenance. Please try again shortly.',
            ], 503);
        }

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

        // Guaranteed to exist + be active by CreateCheckoutRequest's
        // Rule::exists check — a race between validation and here
        // (channel deactivated mid-request) surfaces as a 500 via
        // firstOrFail(), not a silent fallback to some default
        // gateway, since that would misroute a real payment. Resolved
        // early (before the idempotency lookup below) since both the
        // replay branch and the normal-checkout branch need it.
        $paymentMethod = PaymentMethod::query()
            ->where('channel_code', $data['channel_code'])
            ->firstOrFail();
        $gateway = $this->gatewayFactory->make($paymentMethod->gateway);

        // ADR-019's checkout-level idempotency fix: any Order already
        // tagged with this key was created by an earlier request that
        // already passed every check below it (player validation,
        // blacklist/velocity) — re-running them here would be
        // redundant at best, and would double-count a velocity hit at
        // worst. This is a retry (double-click, client timeout retry)
        // of an attempt already in flight or already finished, not a
        // new checkout to re-vet.
        $existing = Order::query()->where('checkout_idempotency_key', $data['idempotency_key'])->first();
        if ($existing !== null) {
            return $this->respondForExistingOrder($existing, $gateway, $data['channel_code'], $data['channel_properties'] ?? []);
        }

        if ($game->player_validator_enabled && $game->player_validator_profile_id !== null) {
            $this->assertPlayerIdIsValidated($game, $data['player_id'], $data['server_id'] ?? null);
        }

        $this->assertNotBlacklisted($data['player_id'], $data['customer_email'], $data['customer_phone'] ?? null, $request->ip());

        // ADR-060 PR-4c: the storefront brand this request belongs to,
        // resolved from `X-Storefront-Host` by the `storefront.brand`
        // middleware (falls back to `Affiliate::primary()` for the primary
        // storefront / a header-less caller). The order — and therefore
        // the ledger profit split at fulfilment — is attributed to this
        // brand, priced against its own wholesale tier + markup.
        // Membership needs BOTH the global kill-switch and this brand's
        // own toggle (ADR-061 decision 4).
        $affiliate = $this->storefrontBrand->get();
        $membershipId = $affiliate->membershipEnabledEffective($platformSettings)
            ? $this->resolveMembershipId($request, $affiliate->id)
            : null;

        try {
            $order = $this->checkout->initiate(new CheckoutRequest(
                customerEmail: $data['customer_email'],
                customerName: $data['customer_name'],
                customerPhone: $data['customer_phone'] ?? null,
                playerId: $data['player_id'],
                serverId: $data['server_id'] ?? null,
                costPriceSen: $package->cost_price,
                standardSellingPriceSen: $package->standard_selling_price,
                packageMarkupPercent: (float) $package->markup_percent,
                affiliateMarkupPct: (float) $affiliate->markup_pct,
                tierMarkupPct: $affiliate->wholesaleTierMarkupPct(),
                paymentFeeConfig: $this->fees->resolve($data['channel_code']),
                paymentMethod: $paymentMethod->category,
                paymentGateway: $paymentMethod->gateway,
                channelCode: $data['channel_code'],
                idempotencyKey: $data['idempotency_key'],
                channelProperties: $data['channel_properties'] ?? [],
                voucherCode: $data['voucher_code'] ?? null,
                supplierProductRef: $package->supplier_package_ref,
                gameId: $game->id,
                packageId: $package->id,
                supplierId: $package->supplier_id,
                affiliateId: $affiliate->id,
                membershipId: $membershipId,
            ), $gateway);
        } catch (DuplicateCheckoutAttemptException) {
            // Lost a genuine race — a concurrent request with the same
            // key won the INSERT between our lookup above and now.
            // Treat it exactly like the lookup had found it.
            $winner = Order::query()->where('checkout_idempotency_key', $data['idempotency_key'])->firstOrFail();

            return $this->respondForExistingOrder($winner, $gateway, $data['channel_code'], $data['channel_properties'] ?? []);
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
        } catch (InvalidVoucherException) {
            // ADR-024: thrown by VoucherService::preview() before any
            // Order is created — nothing to roll back. The message is
            // deliberately generic (see preview()'s own doc comment),
            // never distinguishing "wrong owner" from "doesn't exist"
            // from the client's point of view.
            throw ValidationException::withMessages([
                'voucher_code' => ['This voucher code is not valid for this order.'],
            ]);
        }

        return $this->buildCheckoutResponse($order, $gateway, 201);
    }

    /**
     * Bug fix, 2026-08-30: read-only Package Price/Transaction Fee/
     * Voucher Discount/Total breakdown for the storefront's Order
     * Summary sidebar and Review Modal — see
     * CheckoutService::previewTotal()'s own doc comment for why this
     * exists (the displayed total never included the transaction fee
     * before this). No Order is created, no payment gateway is called,
     * no voucher is locked — matches store()'s own Game/Package
     * active-status gate, but skips player-ID validation, blacklist,
     * and velocity checks, none of which apply to a price display.
     */
    public function previewTotal(PreviewCheckoutTotalRequest $request): JsonResponse
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

        $affiliate = $this->storefrontBrand->get();
        $platformSettings = PlatformSettings::current();
        $membershipId = $affiliate->membershipEnabledEffective($platformSettings)
            ? $this->resolveMembershipId($request, $affiliate->id)
            : null;

        try {
            $preview = $this->checkout->previewTotal(
                costPriceSen: $package->cost_price,
                standardSellingPriceSen: $package->standard_selling_price,
                packageMarkupPercent: (float) $package->markup_percent,
                affiliateMarkupPct: (float) $affiliate->markup_pct,
                tierMarkupPct: $affiliate->wholesaleTierMarkupPct(),
                affiliateId: $affiliate->id,
                paymentFeeConfig: $this->fees->resolve($data['channel_code']),
                membershipId: $membershipId,
                voucherCode: $data['voucher_code'] ?? null,
                customerEmail: $data['customer_email'] ?? '',
                customerPhone: $data['customer_phone'] ?? null,
            );
        } catch (InvalidVoucherException) {
            // Same generic message as store()'s own catch — a voucher
            // that expired/was redeemed elsewhere between the storefront's
            // own vouchers/preview call and this totals re-fetch.
            throw ValidationException::withMessages([
                'voucher_code' => ['This voucher code is not valid for this order.'],
            ]);
        }

        return response()->json([
            'selling_price_sen' => $preview->sellingPriceSen,
            'member_discount_percent' => $preview->memberDiscountPercent,
            'voucher_discount_sen' => $preview->voucherDiscountSen,
            'transaction_fee_sen' => $preview->transactionFeeSen,
            'final_amount_sen' => $preview->finalAmountSen,
        ]);
    }

    /**
     * Idempotent-replay path for an Order already tagged with the
     * incoming request's idempotency_key (ADR-019). If it already has a
     * payment_ref, this is a pure replay of an attempt that already
     * succeeded — no new work, just the same response shape a fresh
     * checkout would have returned. If it doesn't, the previous attempt
     * created the Order but never reached a successful payment (gateway
     * error, or the process died in between) — retry just the payment
     * leg via CheckoutService::resume() against this same Order, never
     * a new one.
     */
    private function respondForExistingOrder(Order $order, PaymentGateway $gateway, string $channelCode, array $channelProperties): JsonResponse
    {
        // ADR-024 decision #5: an order already settled entirely by a
        // voucher has payment_ref permanently null and payment_status
        // already Paid — resume() (which calls the gateway) is neither
        // needed nor safe to call for it. Both conditions together are
        // the same "never pending, never needs the gateway" signal
        // settleWithVoucher() itself relies on.
        if ($order->payment_ref === null && $order->payment_status === PaymentStatus::Paid) {
            return $this->buildCheckoutResponse($order, $gateway, 200);
        }

        if ($order->payment_ref === null) {
            try {
                $order = $this->checkout->resume($order, $gateway, $channelCode, $channelProperties);
            } catch (CheckoutFailedException $e) {
                Log::warning('Checkout resume failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                throw ValidationException::withMessages([
                    'payment' => [$e->getMessage()],
                ]);
            }
        }

        return $this->buildCheckoutResponse($order, $gateway, 200);
    }

    /**
     * Shared response shape for a fresh checkout and an idempotent
     * replay alike. ADR-024 decision #5: an order settled entirely by
     * a voucher has no payment_ref at all — calling
     * `$gateway->getPayment(null)` for it would be meaningless (no
     * gateway was ever involved), so `payment_actions` stays an empty
     * array and the storefront's own signal to skip any redirect is
     * `payment_status === "paid"` immediately in this response, rather
     * than a separate flag — a normal (non-voucher-settled) order is
     * structurally never already Paid at this point, since that only
     * ever happens later via webhook/reconciliation.
     */
    private function buildCheckoutResponse(Order $order, PaymentGateway $gateway, int $status): JsonResponse
    {
        $actions = [];

        if ($order->payment_ref !== null) {
            $payment = $gateway->getPayment($order->payment_ref);
            $actions = $payment->success ? ($payment->data['actions'] ?? []) : [];
        }

        return response()->json([
            'order_number' => $order->order_number,
            'final_amount' => $order->final_amount,
            'payment_status' => $order->payment_status,
            'payment_actions' => $actions,
        ], $status);
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
     * ADR-027 Phase 6, base ADR decision 10: personalization happens
     * once, silently, at "Proceed to Pay" — a session-recognized member
     * gets member pricing with no extra step; anyone without a token
     * (or an invalid/expired one) checks out exactly as a guest always
     * has. Gated by the caller on `Affiliate::membershipEnabledEffective()`
     * (ADR-061 decision 4 — the global kill switch AND the brand's own
     * toggle, seeded off) — the same gate
     * `CatalogController` already applies to `member_price_sen`, so a
     * pre-launch/disabled membership feature never silently applies
     * member pricing at checkout even for an account with a still-valid
     * session token from earlier testing. Same `Authorization: Bearer` convention
     * `MembershipController::resolveEmail()` already uses — deliberately
     * not a `CreateCheckoutRequest` field, since a header (not a body
     * field the storefront must remember to set) matches how every
     * other `/membership`-authenticated call already works.
     *
     * Checks `expires_at` explicitly rather than trusting `status`
     * alone — no job anywhere yet flips a lapsed membership's `status`
     * to Expired (MembershipStatus's own doc comment describes the
     * intent, not a built mechanism), so `status` alone isn't reliable
     * proof a membership is still genuinely current.
     */
    private function resolveMembershipId(Request $request, int $affiliateId): ?int
    {
        $token = $request->bearerToken();

        if ($token === null) {
            return null;
        }

        $session = $this->membershipSessionTokens->resolve($token);

        // ADR-061 decision 5: a session token minted on another brand's
        // storefront never applies member pricing here — treated exactly
        // like a missing/expired token (silent guest fallback).
        if ($session === null || $session['affiliate_id'] !== $affiliateId) {
            return null;
        }

        return Membership::query()
            ->where('affiliate_id', $affiliateId)
            ->where('email', $session['email'])
            ->where('status', MembershipStatus::Active)
            ->where('expires_at', '>=', now())
            ->value('id');
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
