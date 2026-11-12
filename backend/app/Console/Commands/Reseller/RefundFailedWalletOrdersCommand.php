<?php

namespace App\Console\Commands\Reseller;

use App\Models\Order;
use App\Services\Backup\BackupFailureAlerter;
use App\Services\Fulfillment\OrderSettlementService;
use App\Services\Order\DeliveryStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR-139 decisions 26–29 (ADR-073 decision 7, 2026-11-08 addendum): a wallet
 * order that ends Failed goes back to the reseller's Purchase Balance by
 * itself. A sweep, not a model event, so every path into Failed is covered
 * without hooking any of them; `settle()` is the one idempotent,
 * lock-guarded compensation seam and refuses a repeat.
 *
 * Only Failed: PartiallyDelivered stays an admin decision, and NeedsReview /
 * Pending are never refunded (ADR-102). The query skips what `settle()`
 * refuses (a sandbox order, a closed reseller: `walletReseller` excludes
 * soft-deleted rows), or the task would fail on it every minute. It also
 * skips an order carrying a late Success from its supplier (decision 29).
 */
#[Signature('app:refund-failed-wallet-orders')]
#[Description('Refund every uncompensated Failed reseller-wallet order to its Purchase Balance.')]
class RefundFailedWalletOrdersCommand extends Command
{
    public const ALERT_AFTER_FAILED_RUNS = 3;

    public const REASON = 'Automatic refund: supplier confirmed failed';

    public function handle(OrderSettlementService $settlement, BackupFailureAlerter $alerter): int
    {
        $orders = Order::query()
            ->needsAction()
            ->where('delivery_status', DeliveryStatus::Failed->value)
            ->whereNotNull('wallet_reseller_id')
            ->where('is_test', false)
            ->whereHas('walletReseller')
            // Decision 29: a late Success may mean the credit arrived; OrderFulfillmentService alerts, a person decides.
            ->withoutLateSupplierResult()
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            $key = "wallet-auto-refund-failures:{$order->id}";

            try {
                $settlement->settle($order, null, self::REASON);
                Cache::forget($key);
            } catch (Throwable $e) {
                Log::error('Automatic wallet refund failed', ['order_id' => $order->id, 'exception' => $e->getMessage()]);

                Cache::add($key, 0, now()->addDay());
                if (Cache::increment($key) === self::ALERT_AFTER_FAILED_RUNS) {
                    $alerter->alert(
                        'Automatic wallet refund keeps failing',
                        "Order #{$order->id} ({$order->order_number}) failed and could not be refunded to its reseller's Purchase Balance in ".self::ALERT_AFTER_FAILED_RUNS." runs: {$e->getMessage()}. Refund it from the order page.",
                        'Wallet refund',
                    );
                }
            }
        }

        return self::SUCCESS;
    }
}
