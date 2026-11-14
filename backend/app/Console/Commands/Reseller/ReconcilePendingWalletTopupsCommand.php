<?php

namespace App\Console\Commands\Reseller;

use App\Models\PaymentMethod;
use App\Models\ResellerBotWalletTopup;
use App\Models\WalletTopupAttempt;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\AttemptPaymentOutcome;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Reseller\Bot\ResellerBotWalletTopupNotifier;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\WalletTopupAttemptStatus;
use App\Services\Reseller\WalletTopupEndCause;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * PR-G planning addendum decision 9 — the self-serve wallet-top-up
 * counterpart to ReconcilePendingPaymentsCommand/
 * ReconcilePendingMembershipPaymentsCommand: catch a
 * wallet_topup_attempts row stuck at `pending` because the CHIP webhook
 * was never delivered. Webhook delivery stays the primary path; this is
 * the same backstop every other CHIP-triggered flow (orders, Membership)
 * already has, not new architecture.
 *
 * Terminal-status-driven, same as its siblings: acts on the gateway's
 * own answer (via getPayment()), and only falls back to the attempt's
 * own hard `expires_at` (decision 8's 30-minute window) for a
 * genuinely-abandoned attempt — never guesses from elapsed time alone
 * while the gateway might still have a live answer.
 *
 * Item 40 (2026-09-27 money-critical audit) also folded in here: a
 * `.topupbaki` Bot top-up can be correctly charged and credited but miss
 * its WhatsApp "berjaya" reply if `ResellerBotWalletTopupNotifier::notifyPaid()`
 * ran before `ResellerBotWalletTopup::updateOrCreate()` recorded the
 * tracking row (`ResellerBotService::handleTopupBaki()` creates it right
 * after `initiate()` returns) — notifyPaid() finds no row yet and silently
 * no-ops, with nothing else ever re-checking it. Rather than closing that
 * one specific ordering gap (which would need a nullable FK plus orphan-row
 * cleanup on every `initiate()` failure path, for a window that's otherwise
 * unreachable — CHIP can't report a purchase paid before the customer has
 * even received its checkout_url), this sweeps for ANY bot top-up left
 * un-notified despite its attempt already being Paid — self-healing
 * against that race and any other cause (a worker crash, OpenWA being
 * briefly down) alike, on the same schedule this command already runs.
 */
#[Signature('app:reconcile-pending-wallet-topups')]
#[Description('Ask CHIP for the real status of stuck-pending self-serve wallet top-ups and complete or expire them.')]
class ReconcilePendingWalletTopupsCommand extends Command
{
    public function handle(
        PaymentGatewayFactory $gatewayFactory,
        ResellerWalletService $wallets,
        ResellerBotWalletTopupNotifier $botNotifier,
    ): int {
        $pendingAfterMinutes = (int) config('services.wallet_topup_reconciliation.pending_after_minutes');

        $attempts = WalletTopupAttempt::query()
            ->where('status', WalletTopupAttemptStatus::Pending->value)
            ->where('created_at', '<=', now()->subMinutes($pendingAfterMinutes))
            ->get();

        $this->info("Checking {$attempts->count()} stuck-pending wallet top-up attempt(s)...");

        foreach ($attempts as $attempt) {
            $this->reconcile($attempt, $gatewayFactory, $wallets);
        }

        $this->recheckRecentlyEnded($gatewayFactory, $wallets);

        $this->notifyMissedBotTopups($botNotifier);

        return self::SUCCESS;
    }

    /**
     * ADR-135 decision 10: the webhook credits a payment that lands after an
     * attempt ended (cancelled, failed, expired), but this sweep otherwise only
     * looks at `pending`. So a late payment whose webhook was lost would wait
     * for the month-end settlement. Ask CHIP once more about every attempt that
     * ended in the last day and had a purchase; a paid one goes through the
     * same paid seam as the webhook.
     *
     * ponytail: re-asks each ended attempt on every run for 24 h (one CHIP GET
     * per attempt per run). Fine at today's volume; past a few hundred ended
     * top-ups a day, re-check once at ~1 h and once at ~24 h instead.
     */
    private function recheckRecentlyEnded(PaymentGatewayFactory $gatewayFactory, ResellerWalletService $wallets): void
    {
        $ended = WalletTopupAttempt::query()
            ->whereIn('status', [WalletTopupAttemptStatus::Failed->value, WalletTopupAttemptStatus::Expired->value])
            ->whereNotNull('chip_payment_ref')
            ->where('updated_at', '>=', now()->subDay())
            ->get();

        foreach ($ended as $attempt) {
            $gatewayName = PaymentMethod::forChannel($attempt->channel_code)->value('gateway');
            $payment = $gatewayName === null ? null : $gatewayFactory->make($gatewayName)->getPayment($attempt->chip_payment_ref);

            if ($payment?->success && $payment->status === PaymentStatus::Paid) {
                Log::warning('Wallet top-up reconciliation: a top-up that had ended was paid', ['wallet_topup_reference' => $attempt->reference]);
                $this->recover($attempt, $wallets, $payment->data['amount_sen'] ?? null, $payment->data['fee_sen'] ?? null);
            }
        }
    }

    private function notifyMissedBotTopups(ResellerBotWalletTopupNotifier $botNotifier): void
    {
        $missed = ResellerBotWalletTopup::query()
            ->whereNull('notified_at')
            ->whereHas('attempt', fn ($query) => $query->where('status', WalletTopupAttemptStatus::Paid->value))
            ->with('attempt')
            ->get();

        if ($missed->isEmpty()) {
            return;
        }

        $this->info("Sending {$missed->count()} missed Bot top-up confirmation(s)...");

        foreach ($missed as $row) {
            $botNotifier->notifyPaid($row->attempt);
        }
    }

    private function reconcile(
        WalletTopupAttempt $attempt,
        PaymentGatewayFactory $gatewayFactory,
        ResellerWalletService $wallets,
    ): void {
        Log::withContext([
            'wallet_topup_reference' => $attempt->reference,
            'payment_request_id' => $attempt->chip_payment_ref,
        ]);

        // No CHIP purchase id recorded — the createPayment() response was
        // lost. There is no client-retry path for a wallet top-up (unlike
        // membership's ensureCheckoutUrl()) — all a background sweep can
        // safely do is expire it once the hard window passes.
        if ($attempt->chip_payment_ref === null) {
            $this->expireIfPastWindow($attempt, $wallets);

            return;
        }

        $gatewayName = PaymentMethod::forChannel($attempt->channel_code)->value('gateway');

        if ($gatewayName === null) {
            Log::error('Wallet top-up reconciliation: no gateway for channel', ['channel_code' => $attempt->channel_code]);

            return;
        }

        $payment = $gatewayFactory->make($gatewayName)->getPayment($attempt->chip_payment_ref);

        if (! $payment->success) {
            Log::warning('Wallet top-up reconciliation: gateway lookup failed', [
                'error_code' => $payment->errorCode,
                'error_message' => $payment->errorMessage,
            ]);

            return;
        }

        $amountSen = is_array($payment->data) ? ($payment->data['amount_sen'] ?? null) : null;

        match ($payment->status) {
            PaymentStatus::Paid => $this->recover($attempt, $wallets, $amountSen, $payment->data['fee_sen'] ?? null),
            PaymentStatus::Failed => $wallets->markEnded($attempt, WalletTopupEndCause::fromGateway($payment)),
            default => $this->expireIfPastWindow($attempt, $wallets),
        };
    }

    private function recover(WalletTopupAttempt $attempt, ResellerWalletService $wallets, mixed $amountSen, ?int $feeSen = null): void
    {
        // ADR-135 decision 11: the amount check and the credit live in the one seam.
        $outcome = $wallets->applyPaid($attempt, is_int($amountSen) ? $amountSen : null, $feeSen);

        if ($outcome === AttemptPaymentOutcome::Applied || $outcome === AttemptPaymentOutcome::AppliedToClosedAccount) {
            Log::info('Wallet top-up reconciliation: recovered a stuck-pending top-up');
        }
    }

    private function expireIfPastWindow(WalletTopupAttempt $attempt, ResellerWalletService $wallets): void
    {
        if ($attempt->expires_at->isPast()) {
            $wallets->markEnded($attempt, WalletTopupEndCause::Expired);

            Log::info('Wallet top-up reconciliation: expired an abandoned attempt', [
                'expires_at' => $attempt->expires_at->toIso8601String(),
            ]);
        }
    }
}
