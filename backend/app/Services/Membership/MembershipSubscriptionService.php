<?php

namespace App\Services\Membership;

use App\Models\MembershipCheckoutAttempt;
use App\Models\MembershipPlan;
use App\Models\PaymentMethod;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentRequest;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\PaymentMethodFeeResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ADR-068 — the self-serve membership subscription flow: turn a
 * verified member's tier choice into a CHIP checkout, then (on the
 * webhook / reconcile side) turn a paid attempt into a real membership
 * via the existing `MembershipFeeService::recordFeePaid()` seam.
 *
 * `initiate()` mirrors `CheckoutService::initiate()` — one synchronous
 * gateway call on the customer path (the sanctioned exception to
 * "never call a gateway inline"), an amount computed and snapshotted
 * server-side (ORD-9), and a short-window dedup so a slow webhook can't
 * lead a customer into paying twice (S4).
 */
final class MembershipSubscriptionService
{
    /** S4 — a pending attempt younger than this is reused, not duplicated. */
    private const DEDUP_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly PaymentMethodFeeResolver $fees,
        private readonly CheckoutTotalService $totals,
        private readonly MembershipFeeService $membershipFees,
    ) {}

    /**
     * @param  array<string, mixed>  $channelProperties
     */
    public function initiate(
        int $resellerId,
        string $email,
        int $planId,
        string $channelCode,
        array $channelProperties,
        string $clientIdempotencyKey,
    ): MembershipCheckoutAttempt {
        // S9 — HTTP retry of the same request: return the row the first
        // call created (with its checkout_url if the gateway leg got
        // that far; the resume path below fills it in otherwise).
        $existing = MembershipCheckoutAttempt::query()
            ->where('idempotency_key', $clientIdempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->ensureCheckoutUrl($existing, $channelProperties);
        }

        // S4 — a different request, but a fresh pending attempt for this
        // member already has a live CHIP purchase: hand back that one
        // rather than open a second the member could also pay.
        $recent = MembershipCheckoutAttempt::query()
            ->where('reseller_id', $resellerId)
            ->where('email', $email)
            ->where('status', MembershipCheckoutAttemptStatus::Pending->value)
            ->where('created_at', '>=', now()->subMinutes(self::DEDUP_WINDOW_MINUTES))
            ->latest('id')
            ->first();

        if ($recent !== null) {
            return $this->ensureCheckoutUrl($recent, $channelProperties);
        }

        $plan = MembershipPlan::query()->findOrFail($planId);
        $paymentMethod = PaymentMethod::query()
            ->where('channel_code', $channelCode)
            ->where('is_active', true)
            ->firstOrFail();

        // S6 — the channel fee, on the same round-half-away-from-zero
        // formula every order uses, but nothing else from the order
        // total path (no voucher, no velocity).
        $feeConfig = $this->fees->resolve($channelCode);
        $totalChargedSen = $this->totals->calculate($plan->fee_sen, 0, $feeConfig)->finalAmount;

        $attempt = MembershipCheckoutAttempt::query()->create([
            'reseller_id' => $resellerId,
            'email' => $email,
            'membership_plan_id' => $plan->id,
            'fee_sen' => $plan->fee_sen,
            'total_charged_sen' => $totalChargedSen,
            'channel_code' => $channelCode,
            'subscription_number' => $this->generateSubscriptionNumber(),
            'idempotency_key' => $clientIdempotencyKey,
            'status' => MembershipCheckoutAttemptStatus::Pending->value,
        ]);

        return $this->requestPayment($attempt, $paymentMethod->gateway, $channelProperties);
    }

    /**
     * ADR-068 decision 3 / S14 — a paid attempt becomes a membership
     * through the one shared seam. Idempotent: `recordFeePaid` dedups on
     * `subscription_number`, and the status flip is a plain set, so the
     * webhook and the reconcile sweep can both run this for the same
     * attempt without doubling anything.
     */
    public function completePaidAttempt(MembershipCheckoutAttempt $attempt): void
    {
        $this->membershipFees->recordFeePaid(
            $attempt->reseller_id,
            $attempt->email,
            $attempt->membership_plan_id,
            $attempt->fee_sen,
            null,
            null,
            $attempt->subscription_number,
        );

        if ($attempt->status !== MembershipCheckoutAttemptStatus::Paid) {
            $attempt->update(['status' => MembershipCheckoutAttemptStatus::Paid->value]);
        }
    }

    /**
     * @param  array<string, mixed>  $channelProperties
     */
    private function requestPayment(
        MembershipCheckoutAttempt $attempt,
        string $gatewayName,
        array $channelProperties,
    ): MembershipCheckoutAttempt {
        $storefront = rtrim((string) config('services.storefront.url'), '/');
        $channelProperties['success_return_url'] = "{$storefront}/membership?checkout=success";
        $channelProperties['failure_return_url'] = "{$storefront}/membership?checkout=failed";

        $payment = $this->gateways->make($gatewayName)->createPayment(new PaymentRequest(
            referenceId: $attempt->subscription_number,
            amountSen: $attempt->total_charged_sen,
            currency: 'MYR',
            country: 'MY',
            channelCode: $attempt->channel_code,
            channelProperties: $channelProperties,
            description: "PekanGame membership — {$attempt->membershipPlan->name}",
            customer: new PaymentCustomer(
                referenceId: $attempt->subscription_number,
                givenNames: 'Member',
                email: $attempt->email,
            ),
        ));

        if (! $payment->success) {
            // S3 — no attempt is ever left `pending` with no CHIP
            // purchase behind it.
            $attempt->update(['status' => MembershipCheckoutAttemptStatus::Failed->value]);

            Log::warning('Membership subscription gateway call failed', [
                'subscription_number' => $attempt->subscription_number,
                'error_code' => $payment->errorCode,
                'error_message' => $payment->errorMessage,
            ]);

            throw new MembershipSubscriptionException('The payment provider could not start this subscription. Please try again.');
        }

        $attempt->update([
            'payment_ref' => $payment->data['payment_request_id'] ?? null,
            'checkout_url' => $this->checkoutUrlFrom($payment->data),
        ]);

        return $attempt->fresh();
    }

    /**
     * S9 — a retry landed on a row whose first `createPayment()` call
     * never recorded a checkout_url (lost response). Re-call the gateway
     * with the same `subscription_number` as the CHIP `reference` so
     * CHIP returns the existing purchase rather than opening a second.
     *
     * @param  array<string, mixed>  $channelProperties
     */
    private function ensureCheckoutUrl(
        MembershipCheckoutAttempt $attempt,
        array $channelProperties,
    ): MembershipCheckoutAttempt {
        if ($attempt->checkout_url !== null
            || $attempt->status !== MembershipCheckoutAttemptStatus::Pending) {
            return $attempt;
        }

        $paymentMethod = PaymentMethod::query()
            ->where('channel_code', $attempt->channel_code)
            ->first();

        if ($paymentMethod === null) {
            return $attempt;
        }

        return $this->requestPayment($attempt, $paymentMethod->gateway, $channelProperties);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkoutUrlFrom(array $data): ?string
    {
        foreach ($data['actions'] ?? [] as $action) {
            if (($action['type'] ?? null) === 'REDIRECT') {
                return $action['value'] ?? null;
            }
        }

        return null;
    }

    private function generateSubscriptionNumber(): string
    {
        return 'MS-'.Str::upper(Str::random(10));
    }
}
