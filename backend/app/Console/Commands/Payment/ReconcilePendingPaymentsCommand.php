<?php

namespace App\Console\Commands\Payment;

use App\Jobs\FulfillOrderJob;
use App\Models\Order;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentGateway;
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
 * Deliberately resolves the gateway via the default PaymentGateway
 * binding, not App\Services\Payment\PaymentGatewayFactory — Order never
 * persists the specific channel_code it checked out with (only the
 * coarser `payment_method` category), so there is no stored value to
 * feed the factory. XenditWebhookController already makes the same
 * simplification for the same reason. Only one gateway ('xendit') is
 * bound today; revisit both call sites together if a second is ever
 * added.
 *
 * Resolution is terminal-status-driven, not time-driven — acts on
 * Xendit's own answer (confirmed against docs.xendit.co: SUCCEEDED /
 * EXPIRED / FAILED / CANCELED are real terminal Payment Request
 * statuses), never guesses from elapsed time alone. The 24h cap is a
 * fallback safety net for "still ambiguous", not the primary decision
 * driver.
 */
#[Signature('app:reconcile-pending-payments')]
#[Description('Ask Xendit for the real status of orders stuck at payment_status=pending and recover or fail them accordingly.')]
class ReconcilePendingPaymentsCommand extends Command
{
    public function handle(PaymentGateway $gateway): int
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
            $this->reconcile($order, $gateway, $flagAfterHours);
        }

        return self::SUCCESS;
    }

    private function reconcile(Order $order, PaymentGateway $gateway, int $flagAfterHours): void
    {
        Log::withContext(['order_number' => $order->order_number, 'payment_request_id' => $order->payment_ref]);

        $payment = $gateway->getPayment($order->payment_ref);

        if (! $payment->success) {
            Log::warning('Payment reconciliation: gateway lookup failed', [
                'error_code' => $payment->errorCode,
                'error_message' => $payment->errorMessage,
            ]);

            return;
        }

        $status = $payment->data['status'] ?? null;

        match ($status) {
            'SUCCEEDED' => $this->recover($order),
            'EXPIRED', 'FAILED', 'CANCELED' => $order->update(['payment_status' => PaymentStatus::Failed->value]),
            default => $this->flagIfStale($order, $flagAfterHours, $status),
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
