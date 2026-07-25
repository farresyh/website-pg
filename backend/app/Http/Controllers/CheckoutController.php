<?php

namespace App\Http\Controllers;

use App\Http\Requests\Checkout\CreateCheckoutRequest;
use App\Models\Game;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Services\Checkout\CheckoutFailedException;
use App\Services\Checkout\CheckoutRequest;
use App\Services\Checkout\CheckoutService;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Pricing\PaymentMethodFeeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Public, guest-checkout endpoint (ADR-011 — no Customer auth exists;
 * anyone can call this). Resolves Game/Package/Reseller, enforces the
 * game's per-game validation_rules (ADR-005 addendum — Gamevion's
 * order endpoint has no field schema of its own, so we must know
 * whether a second field beyond Player ID is required before ever
 * reaching the supplier), then hands off to the already-tested
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

        // PRD §8: exactly one Reseller row for MVP (the platform owner,
        // markup_pct=0) — seeded by DatabaseSeeder, firstOrCreate here
        // as a safety net so checkout never hard-fails on a fresh DB
        // that skipped seeding (same "documented stopgap" precedent as
        // SyncSupplierProductsCommand's Supplier row auto-creation).
        $reseller = Reseller::query()->firstOrCreate(
            ['business_name' => 'Platform Owner'],
            ['markup_pct' => 0, 'status' => 'active'],
        );

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
}
