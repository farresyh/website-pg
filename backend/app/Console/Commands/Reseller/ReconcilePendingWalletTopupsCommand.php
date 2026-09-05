<?php

namespace App\Console\Commands\Reseller;

use App\Models\PaymentMethod;
use App\Models\WalletTopupAttempt;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Reseller\WalletTopupAttemptStatus;
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
 */
#[Signature('app:reconcile-pending-wallet-topups')]
#[Description('Ask CHIP for the real status of stuck-pending self-serve wallet top-ups and complete or expire them.')]
class ReconcilePendingWalletTopupsCommand extends Command
{
    public function handle(PaymentGatewayFactory $gatewayFactory, ResellerWalletService $wallets): int
    {
        $pendingAfterMinutes = (int) config('services.wallet_topup_reconciliation.pending_after_minutes');

        $attempts = WalletTopupAttempt::query()
            ->where('status', WalletTopupAttemptStatus::Pending->value)
            ->where('created_at', '<=', now()->subMinutes($pendingAfterMinutes))
            ->get();

        $this->info("Checking {$attempts->count()} stuck-pending wallet top-up attempt(s)...");

        foreach ($attempts as $attempt) {
            $this->reconcile($attempt, $gatewayFactory, $wallets);
        }

        return self::SUCCESS;
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
            $this->expireIfPastWindow($attempt);

            return;
        }

        $gatewayName = PaymentMethod::query()->where('channel_code', $attempt->channel_code)->value('gateway');

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
            PaymentStatus::Paid => $this->recover($attempt, $wallets, $amountSen),
            PaymentStatus::Failed => $attempt->update(['status' => WalletTopupAttemptStatus::Failed->value]),
            default => $this->expireIfPastWindow($attempt),
        };
    }

    private function recover(WalletTopupAttempt $attempt, ResellerWalletService $wallets, mixed $amountSen): void
    {
        // Defense-in-depth, same as the sibling commands' own recover():
        // a mismatch (a missing amount included) is enough not to
        // silently credit the wallet.
        if ($amountSen !== $attempt->total_charged_sen) {
            Log::error('Wallet top-up reconciliation: amount mismatch, refusing to credit', [
                'expected_sen' => $attempt->total_charged_sen,
                'received_sen' => $amountSen,
            ]);

            return;
        }

        $wallets->completeTopup($attempt);

        Log::info('Wallet top-up reconciliation: recovered a stuck-pending top-up');
    }

    private function expireIfPastWindow(WalletTopupAttempt $attempt): void
    {
        if ($attempt->expires_at->isPast()) {
            $attempt->update(['status' => WalletTopupAttemptStatus::Expired->value]);

            Log::info('Wallet top-up reconciliation: expired an abandoned attempt', [
                'expires_at' => $attempt->expires_at->toIso8601String(),
            ]);
        }
    }
}
