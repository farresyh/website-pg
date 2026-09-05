<?php

namespace App\Services\Reseller;

use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentRequest;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\PaymentMethodFeeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ADR-073 decision 3(a) / PR-G: the self-serve CHIP wallet top-up flow —
 * a `Reseller` portal account's own Wallet screen turns a requested
 * amount into a real CHIP checkout. Mirrors
 * `MembershipSubscriptionService::initiate()`'s shape closely (one
 * synchronous gateway call on the customer path, an amount snapshotted
 * server-side, ORD-9) but with PR-G planning addendum decision 8's own,
 * stricter "exactly one pending row per Reseller" guard in place of that
 * service's own softer time-window dedup — checked inside the same
 * transaction that creates the row (not a separate pre-check subject to
 * a race), by locking this reseller's own `('reseller_wallet', id)`
 * `ledger_accounts` row as the mutex — the same discipline
 * `LedgerService::debit()` and `ResellerOrderPlacementService` already
 * use for this exact reseller's own money.
 */
final class ResellerWalletTopupService
{
    /** PR-G planning addendum decision 3: minimum RM10, no maximum. */
    public const MIN_AMOUNT_SEN = 1000;

    /** PR-G planning addendum decision 8. */
    public const EXPIRY_MINUTES = 30;

    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly PaymentMethodFeeResolver $fees,
        private readonly CheckoutTotalService $totals,
    ) {}

    /**
     * @param  array<string, mixed>  $channelProperties
     */
    public function initiate(
        Reseller $reseller,
        int $amountSen,
        string $channelCode,
        array $channelProperties,
    ): WalletTopupAttempt {
        if ($amountSen < self::MIN_AMOUNT_SEN) {
            throw new InvalidWalletTopupAmountException(
                'Minimum top-up amount is '.self::MIN_AMOUNT_SEN.' sen.',
            );
        }

        $paymentMethod = PaymentMethod::query()
            ->where('channel_code', $channelCode)
            ->where('is_active', true)
            ->firstOrFail();

        // S6-style channel fee — the same round-half-away-from-zero
        // formula every order/membership charge uses, borne by the
        // reseller on top of the amount actually credited (ADR-073
        // decision 3(a)).
        $feeConfig = $this->fees->resolve($channelCode);
        $totalChargedSen = $this->totals->calculate($amountSen, 0, $feeConfig)->finalAmount;

        $attempt = DB::transaction(function () use ($reseller, $amountSen, $totalChargedSen, $channelCode) {
            // Lock this reseller's own wallet ledger_accounts row as a
            // pure mutex (it holds no balance itself) — serializes the
            // "is there already a pending attempt" check + insert for
            // this one reseller, so two concurrent top-up requests can't
            // both pass the check before either commits.
            LedgerAccount::query()
                ->where('owner_type', LedgerOwnerType::ResellerWallet->value)
                ->where('owner_id', $reseller->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingPending = WalletTopupAttempt::query()
                ->where('reseller_id', $reseller->id)
                ->where('status', WalletTopupAttemptStatus::Pending->value)
                ->where('expires_at', '>', now())
                ->first();

            if ($existingPending !== null) {
                throw new PendingWalletTopupAlreadyExistsException(
                    "Reseller #{$reseller->id} already has a pending top-up attempt (#{$existingPending->id}).",
                );
            }

            return WalletTopupAttempt::query()->create([
                'reseller_id' => $reseller->id,
                'reference' => self::generateReference(),
                'amount_sen' => $amountSen,
                'total_charged_sen' => $totalChargedSen,
                'channel_code' => $channelCode,
                'status' => WalletTopupAttemptStatus::Pending->value,
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            ]);
        });

        return $this->requestPayment($attempt, $paymentMethod->gateway, $channelProperties);
    }

    /**
     * @param  array<string, mixed>  $channelProperties
     */
    private function requestPayment(
        WalletTopupAttempt $attempt,
        string $gatewayName,
        array $channelProperties,
    ): WalletTopupAttempt {
        $portal = rtrim((string) config('services.reseller_portal.url'), '/');
        $channelProperties['success_return_url'] = "{$portal}/wallet?topup=success";
        $channelProperties['failure_return_url'] = "{$portal}/wallet?topup=failed";

        $payment = $this->gateways->make($gatewayName)->createPayment(new PaymentRequest(
            referenceId: $attempt->reference,
            amountSen: $attempt->total_charged_sen,
            currency: 'MYR',
            country: 'MY',
            channelCode: $attempt->channel_code,
            channelProperties: $channelProperties,
            description: "PekanGame reseller wallet top-up ({$attempt->reference})",
            customer: new PaymentCustomer(
                referenceId: $attempt->reference,
                givenNames: $attempt->reseller->business_name,
                email: $attempt->reseller->email,
            ),
        ));

        if (! $payment->success) {
            // S3-style — no attempt is ever left `pending` with no CHIP
            // purchase behind it.
            $attempt->update(['status' => WalletTopupAttemptStatus::Failed->value]);

            Log::warning('Wallet top-up gateway call failed', [
                'reference' => $attempt->reference,
                'error_code' => $payment->errorCode,
                'error_message' => $payment->errorMessage,
            ]);

            throw new WalletTopupCheckoutFailedException('The payment provider could not start this top-up. Please try again.');
        }

        $attempt->update([
            'chip_payment_ref' => $payment->data['payment_request_id'] ?? null,
            'checkout_url' => $this->checkoutUrlFrom($payment->data),
        ]);

        return $attempt->fresh();
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

    private static function generateReference(): string
    {
        return 'WT-'.Str::upper(Str::random(10));
    }
}
