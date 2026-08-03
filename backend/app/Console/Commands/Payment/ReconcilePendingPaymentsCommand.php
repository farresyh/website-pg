<?php

namespace App\Console\Commands\Payment;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ADR-021 (PAY-3) — catches the case a Xendit webhook never covers: an
 * order stuck at payment_status=pending because the webhook was never
 * delivered (dropped, customer closed the tab before a redirect-based
 * channel completed, etc.). Runs on a schedule (see routes/console.php),
 * same inert-until-real-cron pattern as SyncSupplierPricesJob.
 *
 * Resolves the gateway per-order via PaymentGatewayFactory, keyed on
 * the Order's own `payment_gateway` snapshot (ADR-022's newest
 * addendum, decision 1) — not a single fixed binding — since different
 * orders may now have gone through different gateways. If an order
 * somehow has no recorded `payment_gateway` (should be structurally
 * near-impossible after CheckoutService::initiate() started stamping
 * it), it is skipped and logged at error level as a checkout bug
 * signal, never guessed at a default gateway — a wrong guess would
 * silently re-misroute exactly the order this fix exists to protect.
 *
 * Resolution is terminal-status-driven, not time-driven — acts on the
 * gateway's own answer, never guesses from elapsed time alone. Matches
 * on `PaymentResponse::$status` (a typed PaymentStatus), never a raw
 * gateway-specific string (Xendit's SUCCEEDED/EXPIRED/... vs. CHIP's
 * paid/error/...) — each PaymentGateway::getPayment() implementation
 * normalizes its own vocabulary into that enum before returning, the
 * same discipline parseWebhookEvent() already had (a real gap found
 * and closed while building ChipGateway, ADR-022's newest addendum).
 * The 24h cap is a fallback safety net for "still ambiguous", not the
 * primary decision driver.
 */
#[Signature('app:reconcile-pending-payments')]
#[Description('Ask each order\'s own payment gateway for its real status and recover or fail stuck payment_status=pending orders accordingly.')]
class ReconcilePendingPaymentsCommand extends Command
{
    public function handle(PaymentGatewayFactory $gatewayFactory): int
    {
        $pendingAfterMinutes = (int) config('services.payment_reconciliation.pending_after_minutes');
        $flagAfterHours = (int) config('services.payment_reconciliation.flag_after_hours');

        $orders = Order::query()
            ->where('payment_status', PaymentStatus::Pending->value)
            ->where('created_at', '<=', now()->subMinutes($pendingAfterMinutes))
            ->whereNotNull('payment_ref')
            ->get();

        $this->info("Checking {$orders->count()} stuck-pending order(s)...");

        foreach ($orders as $order) {
            $this->reconcile($order, $gatewayFactory, $flagAfterHours);
        }

        return self::SUCCESS;
    }

    private function reconcile(Order $order, PaymentGatewayFactory $gatewayFactory, int $flagAfterHours): void
    {
        Log::withContext(['order_number' => $order->order_number, 'payment_request_id' => $order->payment_ref]);

        if ($order->payment_gateway === null) {
            Log::error('Payment reconciliation: order has no payment_gateway recorded — checkout stamping bug, investigate CheckoutService', [
                'order_number' => $order->order_number,
                'payment_request_id' => $order->payment_ref,
            ]);

            return;
        }

        $gateway = $gatewayFactory->make($order->payment_gateway);

        $payment = $gateway->getPayment($order->payment_ref);

        if (! $payment->success) {
            Log::warning('Payment reconciliation: gateway lookup failed', [
                'error_code' => $payment->errorCode,
                'error_message' => $payment->errorMessage,
            ]);

            return;
        }

        match ($payment->status) {
            PaymentStatus::Paid => $this->recover($order),
            PaymentStatus::Failed => $order->update(['payment_status' => PaymentStatus::Failed->value]),
            // PaymentStatus::Pending, or null (a gateway that somehow
            // didn't set it) — both genuinely ambiguous, never guessed at.
            default => $this->flagIfStale($order, $flagAfterHours, $payment->data['status'] ?? null),
        };
    }

    /**
     * Mirrors XenditWebhookController's own success branch exactly —
     * same PAY-2 duplicate-processing guard (a late webhook could have
     * already flipped this order to Paid and dispatched fulfillment
     * before this run got to it).
     */
    private function recover(Order $order): void
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            return;
        }

        $order->update(['payment_status' => PaymentStatus::Paid->value]);

        FulfillOrderJob::dispatch($order->fresh());

        Log::info('Payment reconciliation: recovered a stuck-pending order');
    }

    private function flagIfStale(Order $order, int $flagAfterHours, ?string $status): void
    {
        if ($order->created_at->lte(now()->subHours($flagAfterHours))) {
            Log::warning('Payment reconciliation: order flagged for review — no terminal answer from gateway', [
                'gateway_status' => $status,
                'age_hours' => $order->created_at->diffInHours(now()),
            ]);
        }
    }
}
