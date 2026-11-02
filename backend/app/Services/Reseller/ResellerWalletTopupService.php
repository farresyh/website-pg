<?php

namespace App\Services\Reseller;

use App\Models\LedgerAccount;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\WalletTopupAttempt;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentCustomer;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\PaymentRequest;
use App\Services\Pricing\CheckoutTotalService;
use App\Services\Pricing\PaymentMethodFeeResolver;
use App\Support\MalaysiaTime;
use Illuminate\Database\Eloquent\Builder;
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
    /** PR-G planning addendum decision 3: minimum RM10. The maximum and the balance cap are ADR-131 settings. */
    public const MIN_AMOUNT_SEN = 1000;

    /** PR-G planning addendum decision 8. */
    public const EXPIRY_MINUTES = 30;

    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly PaymentMethodFeeResolver $fees,
        private readonly CheckoutTotalService $totals,
        private readonly ResellerWalletService $wallets,
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
        // ADR-133 decision 20: an account that cannot order is not funded. Checked on
        // the start only, never in completeTopup(): a top-up already paid still credits.
        // An assigned tier can only be swapped, never cleared, so no lock is needed.
        if ($reseller->reseller_tier_id === null) {
            throw new NoResellerTierAssignedException(
                'Your account has no pricing set up yet, so you cannot add balance. Contact PekanGame to set it up.',
            );
        }

        if ($amountSen < self::MIN_AMOUNT_SEN) {
            throw new InvalidWalletTopupAmountException(
                'The minimum you can add is RM'.number_format(self::MIN_AMOUNT_SEN / 100, 2).'.',
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

        // ADR-110 2026-10-29 addendum: before the lock below says "already pending",
        // fail a pending attempt whose purchase CHIP has ended for good (the reseller
        // cancelled at the bank). Done here, not inside the transaction, so no CHIP
        // call is made while the wallet account is locked.
        $this->failPendingIfFinal($reseller);

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

            $existingPending = self::livePending($reseller)->first();

            if ($existingPending !== null) {
                throw new PendingWalletTopupAlreadyExistsException($existingPending);
            }

            // ADR-131 decision 5: the per-top-up maximum and the balance cap, under the
            // mutex locked above and before any gateway call.
            $this->wallets->assertWithinLimits($reseller, $amountSen);

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
     * ADR-135 decision 1: the reseller's live pending top-up, with or without a
     * link. A database read only: it runs on every portal balance and dashboard
     * load and the bot's `.baki`, so it never waits on CHIP.
     */
    public function currentPending(Reseller $reseller): ?WalletTopupAttempt
    {
        return self::livePending($reseller)->latest('id')->first();
    }

    /**
     * ADR-135 decision 2: the stored link, verified with CHIP first, so a dead
     * link is never handed out (ADR-110 2026-10-29).
     *
     * @throws WalletTopupNotPayableException
     */
    public function resume(Reseller $reseller, string $reference): string
    {
        $attempt = $this->ownAttempt($reseller, $reference);

        if ($attempt->status !== WalletTopupAttemptStatus::Pending || $attempt->expires_at->isPast()) {
            throw new WalletTopupNotPayableException('This top-up has ended. Start a new one.');
        }

        if ($attempt->checkout_url === null || $attempt->chip_payment_ref === null) {
            throw new WalletTopupNotPayableException('This top-up is still being set up and expires by itself at '.self::expiryLabel($attempt).'.');
        }

        $answer = $this->gatewayFor($attempt)?->getPayment($attempt->chip_payment_ref);

        if ($answer?->isFinalFailure()) {
            $this->wallets->markEnded($attempt, WalletTopupEndCause::fromGateway($answer));

            throw new WalletTopupNotPayableException('This top-up has ended. Start a new one.');
        }

        if ($answer?->status === PaymentStatus::Paid) {
            throw new WalletTopupNotPayableException('This top-up is already paid. Your balance will show shortly.');
        }

        return $attempt->checkout_url;
    }

    /**
     * ADR-135 decision 5: cancel is decided by CHIP, never locally. Asks CHIP to
     * cancel (outside any lock), then re-reads the purchase and acts only on that
     * answer. Never credits: a paid purchase is left to the webhook and reconcile.
     */
    public function cancel(Reseller $reseller, string $reference, WalletTopupEndCause $cause): WalletTopupCancelOutcome
    {
        $attempt = $this->ownAttempt($reseller, $reference);

        if ($attempt->status !== WalletTopupAttemptStatus::Pending) {
            return $this->outcomeOf($attempt);
        }

        if ($attempt->chip_payment_ref === null) {
            return WalletTopupCancelOutcome::NoPurchaseYet;
        }

        $gateway = $this->gatewayFor($attempt);

        if ($gateway === null) {
            return WalletTopupCancelOutcome::InFlight;
        }

        // The answer is not read: CHIP's 400 for "already cancelled" or
        // "already paid" lands in the same decision as a 200.
        $gateway->cancelPayment($attempt->chip_payment_ref);
        $answer = $gateway->getPayment($attempt->chip_payment_ref);

        if ($answer->status === PaymentStatus::Paid) {
            return WalletTopupCancelOutcome::AlreadyPaid;
        }

        if (! $answer->isFinalFailure()) {
            return WalletTopupCancelOutcome::InFlight;
        }

        $ended = $this->wallets->markEnded($attempt, $answer->endedByExpiry() ? WalletTopupEndCause::Expired : $cause);

        if (! $ended) {
            // The webhook, reconcile or a second cancel got there first.
            return $this->outcomeOf($attempt->fresh());
        }

        Log::info('Wallet top-up cancelled by reseller', [
            'reseller_id' => $reseller->id,
            'wallet_topup_reference' => $attempt->reference,
            'cause' => $cause->value,
        ]);

        return WalletTopupCancelOutcome::Cancelled;
    }

    private function outcomeOf(WalletTopupAttempt $attempt): WalletTopupCancelOutcome
    {
        return $attempt->status === WalletTopupAttemptStatus::Paid
            ? WalletTopupCancelOutcome::AlreadyPaid
            : WalletTopupCancelOutcome::AlreadyEnded;
    }

    /** Another reseller's reference is a 404, never a hint that it exists. */
    private function ownAttempt(Reseller $reseller, string $reference): WalletTopupAttempt
    {
        return WalletTopupAttempt::query()
            ->where('reseller_id', $reseller->id)
            ->where('reference', $reference)
            ->firstOrFail();
    }

    /** @return Builder<WalletTopupAttempt> */
    private static function livePending(Reseller $reseller): Builder
    {
        return WalletTopupAttempt::query()
            ->where('reseller_id', $reseller->id)
            ->where('status', WalletTopupAttemptStatus::Pending->value)
            ->where('expires_at', '>', now());
    }

    private function gatewayFor(WalletTopupAttempt $attempt): ?PaymentGateway
    {
        $gatewayName = PaymentMethod::query()->where('channel_code', $attempt->channel_code)->value('gateway');

        return $gatewayName === null ? null : $this->gateways->make($gatewayName);
    }

    /** The attempt's end time as the reseller reads it: MYT, H:i. */
    public static function expiryLabel(WalletTopupAttempt $attempt): string
    {
        return $attempt->expires_at->copy()->setTimezone(MalaysiaTime::TIMEZONE)->format('H:i');
    }

    private function failPendingIfFinal(Reseller $reseller): void
    {
        $pending = self::livePending($reseller)->whereNotNull('chip_payment_ref')->first();
        $answer = $pending === null ? null : $this->gatewayFor($pending)?->getPayment($pending->chip_payment_ref);

        if ($answer?->isFinalFailure()) {
            $this->wallets->markEnded($pending, WalletTopupEndCause::fromGateway($answer));
        }
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
            description: "PekanGame purchase balance ({$attempt->reference})",
            customer: new PaymentCustomer(
                referenceId: $attempt->reference,
                givenNames: $attempt->reseller->business_name,
                email: $attempt->reseller->email,
            ),
        ));

        if (! $payment->success) {
            // S3-style — no attempt is ever left `pending` with no CHIP
            // purchase behind it.
            $this->wallets->markEnded($attempt, WalletTopupEndCause::CheckoutFailed);

            Log::warning('Wallet top-up gateway call failed', [
                'reference' => $attempt->reference,
                'error_code' => $payment->errorCode,
                'error_message' => $payment->errorMessage,
            ]);

            throw new WalletTopupCheckoutFailedException('The payment provider could not start this payment. Please try again.');
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
