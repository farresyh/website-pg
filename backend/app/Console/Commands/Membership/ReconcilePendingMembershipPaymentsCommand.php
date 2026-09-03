<?php

namespace App\Console\Commands\Membership;

use App\Models\MembershipCheckoutAttempt;
use App\Models\PaymentMethod;
use App\Services\Membership\MembershipCheckoutAttemptStatus;
use App\Services\Membership\MembershipSubscriptionService;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-068 decision 10 — the self-serve membership-subscription
 * counterpart to ReconcilePendingPaymentsCommand: catch a
 * membership_checkout_attempts row stuck at `pending` because the CHIP
 * webhook was never delivered. A sibling command, not an extension of
 * that one, because its query is `orders`-coupled (S16).
 *
 * Terminal-status-driven, same as the order reconcile: acts on the
 * gateway's own answer (via getPayment(), harder to forge than a
 * webhook delivery), and only uses elapsed time as the fallback that
 * marks a genuinely-abandoned attempt `expired`.
 */
#[Signature('app:reconcile-pending-membership-payments')]
#[Description('Ask CHIP for the real status of stuck-pending self-serve membership subscription payments and complete or expire them.')]
class ReconcilePendingMembershipPaymentsCommand extends Command
{
    public function handle(PaymentGatewayFactory $gatewayFactory, MembershipSubscriptionService $subscriptions): int
    {
        $pendingAfterMinutes = (int) config('services.membership_reconciliation.pending_after_minutes');
        $expireAfterHours = (int) config('services.membership_reconciliation.expire_after_hours');

        $attempts = MembershipCheckoutAttempt::query()
            ->where('status', MembershipCheckoutAttemptStatus::Pending->value)
            ->where('created_at', '<=', now()->subMinutes($pendingAfterMinutes))
            ->get();

        $this->info("Checking {$attempts->count()} stuck-pending membership attempt(s)...");

        foreach ($attempts as $attempt) {
            $this->reconcile($attempt, $gatewayFactory, $subscriptions, $expireAfterHours);
        }

        return self::SUCCESS;
    }

    private function reconcile(
        MembershipCheckoutAttempt $attempt,
        PaymentGatewayFactory $gatewayFactory,
        MembershipSubscriptionService $subscriptions,
        int $expireAfterHours,
    ): void {
        Log::withContext([
            'subscription_number' => $attempt->subscription_number,
            'payment_request_id' => $attempt->payment_ref,
        ]);

        // No CHIP purchase id recorded — the createPayment() response was
        // lost (S9). The client-retry path (`ensureCheckoutUrl`) is the
        // real recovery here; from a background sweep all we can safely
        // do is expire a genuinely stale one.
        if ($attempt->payment_ref === null) {
            $this->expireIfStale($attempt, $expireAfterHours, 'no payment_ref');

            return;
        }

        $gatewayName = PaymentMethod::query()->where('channel_code', $attempt->channel_code)->value('gateway');

        if ($gatewayName === null) {
            Log::error('Membership reconciliation: no gateway for channel', ['channel_code' => $attempt->channel_code]);

            return;
        }

        $payment = $gatewayFactory->make($gatewayName)->getPayment($attempt->payment_ref);

        if (! $payment->success) {
            Log::warning('Membership reconciliation: gateway lookup failed', [
                'error_code' => $payment->errorCode,
                'error_message' => $payment->errorMessage,
            ]);

            return;
        }

        $amountSen = is_array($payment->data) ? ($payment->data['amount_sen'] ?? null) : null;

        match ($payment->status) {
            PaymentStatus::Paid => $this->recover($attempt, $subscriptions, $amountSen),
            PaymentStatus::Failed => $attempt->update(['status' => MembershipCheckoutAttemptStatus::Failed->value]),
            default => $this->expireIfStale($attempt, $expireAfterHours, is_array($payment->data) ? ($payment->data['status'] ?? null) : null),
        };
    }

    private function recover(MembershipCheckoutAttempt $attempt, MembershipSubscriptionService $subscriptions, mixed $amountSen): void
    {
        // Defense-in-depth, same as ReconcilePendingPaymentsCommand's own
        // recover(): a mismatch (a missing amount included) is enough not
        // to silently activate a membership.
        if ($amountSen !== $attempt->total_charged_sen) {
            Log::error('Membership reconciliation: amount mismatch, refusing to activate', [
                'expected_sen' => $attempt->total_charged_sen,
                'received_sen' => $amountSen,
            ]);

            return;
        }

        $subscriptions->completePaidAttempt($attempt);

        Log::info('Membership reconciliation: recovered a stuck-pending subscription');
    }

    private function expireIfStale(MembershipCheckoutAttempt $attempt, int $expireAfterHours, ?string $reason): void
    {
        if ($attempt->created_at->lte(now()->subHours($expireAfterHours))) {
            $attempt->update(['status' => MembershipCheckoutAttemptStatus::Expired->value]);

            Log::info('Membership reconciliation: expired an abandoned subscription attempt', [
                'reason' => $reason,
                'age_hours' => $attempt->created_at->diffInHours(now()),
            ]);
        }
    }
}
