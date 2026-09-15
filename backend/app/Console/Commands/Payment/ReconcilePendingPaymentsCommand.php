<?php

namespace App\Console\Commands\Payment;

use App\Models\Order;
use App\Services\Order\PaymentStatus;
use App\Services\Payment\PaymentReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * ADR-021 (PAY-3) — catches the case a Xendit webhook never covers: an
 * order stuck at payment_status=pending because the webhook was never
 * delivered (dropped, customer closed the tab before a redirect-based
 * channel completed, etc.). Runs on a schedule (see routes/console.php),
 * same inert-until-real-cron pattern as SyncSupplierPricesJob.
 *
 * ADR-096 decision 7: the actual per-order gateway-check + recover/fail
 * logic now lives in PaymentReconciliationService, shared with the new
 * admin "Check from Gateway" manual-poll action — this command is a
 * thin wrapper: select the stale-pending orders, reconcile each, flag
 * for review if still ambiguous past the configured window.
 *
 * Resolution is terminal-status-driven, not time-driven — acts on the
 * gateway's own answer, never guesses from elapsed time alone. Matches
 * on `PaymentResponse::$status` (a typed PaymentStatus), never a raw
 * gateway-specific string. The 24h cap is a fallback safety net for
 * "still ambiguous", not the primary decision driver.
 */
#[Signature('app:reconcile-pending-payments')]
#[Description('Ask each order\'s own payment gateway for its real status and recover or fail stuck payment_status=pending orders accordingly.')]
class ReconcilePendingPaymentsCommand extends Command
{
    public function handle(PaymentReconciliationService $reconciliation): int
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
            $result = $reconciliation->reconcileOrder($order);

            if ($result['lookup_succeeded'] && ! $result['applied']) {
                $reconciliation->flagIfStale($order, $flagAfterHours, $result['data']['status'] ?? null);
            }
        }

        return self::SUCCESS;
    }
}
