<?php

namespace App\Services\Fulfillment;

use App\Models\MembershipQuotaDebit;
use App\Models\Order;
use App\Models\OrderDeliveryLeg;
use App\Models\ResellerBotOrderNotification;
use App\Models\VoucherRedemption;
use App\Services\Ledger\LedgerOwnerType;
use App\Services\Ledger\LedgerService;
use App\Services\Membership\MembershipQuotaService;
use App\Services\Notification\CustomerNotificationService;
use App\Services\OpenWa\OpenWaClient;
use App\Services\Order\DeliveryStatus;
use App\Services\Reseller\Bot\ResellerBotReplyFormatter;
use App\Services\Reseller\Webhook\ResellerWebhookDispatcher;
use App\Services\Reseller\Webhook\ResellerWebhookEvent;
use App\Services\Voucher\VoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR-094 2026-10-04 addendum, decisions 30-37 — the one compensation
 * path for a Failed or PartiallyDelivered order, on every channel. The
 * instrument comes from the order (a Reseller wallet order gets a
 * `wallet_refund`; everything else a voucher), never from which button
 * was pressed. An ordinary package is the same formula with u = 1.
 *
 * Amounts are a formula, never admin-typed (decision 33):
 *   u       = Σ failed legs' frozen selling_price_sen ÷ Σ all legs' (1 without legs)
 *   U_cash  = round((final_amount − transaction_fee) × u); a wallet
 *             order refunds round(final_amount × u) — what left the wallet
 *   U_vouch = round(voucher_discount × u)
 * Voucher: give U_vouch back to the paid-with voucher, issue U_cash as a
 * new one. Wallet: refund U_cash + U_vouch (U_vouch is 0 there).
 *
 * A PartiallyDelivered order also credits its delivered legs' profit
 * here, in the same transaction (decision 35):
 *   affiliate_profit = A − round(A × u)
 *   platform_profit  = (selling_price − U) − Σ delivered legs' cost − affiliate_profit
 * Compensation never changes delivery_status.
 */
final class OrderSettlementService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly VoucherService $vouchers,
        private readonly MembershipQuotaService $quota,
        private readonly CustomerNotificationService $notifications,
        private readonly OpenWaClient $openWa,
        private readonly ResellerWebhookDispatcher $webhooks,
    ) {}

    /**
     * The amounts settle() would use — for the admin Order Detail.
     *
     * @return array{instrument: string, cash_sen: int, voucher_restore_sen: int, total_sen: int}
     */
    public function preview(Order $order): array
    {
        $amounts = $this->amounts($order);

        return [
            'instrument' => $order->wallet_reseller_id !== null ? 'wallet' : 'voucher',
            'cash_sen' => $amounts['cash'],
            'voucher_restore_sen' => $amounts['voucher'],
            'total_sen' => $amounts['cash'] + $amounts['voucher'],
        ];
    }

    public function settle(Order $order, int $adminUserId, ?string $reason = null): SettlementResult
    {
        $result = DB::transaction(function () use ($order, $adminUserId, $reason) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->delivery_status->isCompensable()) {
                throw new OrderFulfillmentException("Order #{$locked->id} is {$locked->delivery_status->value} — only a failed or partially delivered order can be compensated");
            }

            if ($locked->isAlreadyCompensated()) {
                throw new OrderFulfillmentException("Order #{$locked->id} has already been compensated");
            }

            $amounts = $this->amounts($locked);
            $this->restoreQuota($locked, $amounts['num'], $amounts['den']);

            if ($locked->delivery_status === DeliveryStatus::PartiallyDelivered) {
                $this->creditDeliveredProfit($locked, $amounts);
            }

            if ($locked->wallet_reseller_id !== null) {
                $refund = $amounts['cash'] + $amounts['voucher'];
                $this->ledger->credit(LedgerOwnerType::ResellerWallet, $locked->wallet_reseller_id, $refund, 'wallet_refund', 'order', $locked->id);

                return new SettlementResult(voucher: null, walletRefundSen: $refund);
            }

            $this->vouchers->restore($locked->id, $amounts['voucher']);

            $voucher = $amounts['cash'] === 0 ? null : $this->vouchers->issue(
                customerEmail: $locked->customer_email,
                customerPhone: $locked->customer_phone,
                amount: $amounts['cash'],
                reason: $reason ?? $this->defaultReason($locked),
                expiresAt: null,
                createdBy: $adminUserId,
                approvedBy: null,
                // ADR-060 PR-4d: a compensation voucher inherits the order's brand.
                affiliateId: $locked->affiliate_id,
                orderId: $locked->id,
            );

            return new SettlementResult(voucher: $voucher, walletRefundSen: 0);
        });

        Log::info('Order compensated', [
            'order_id' => $order->id,
            'voucher_id' => $result->voucher?->id,
            'wallet_refund_sen' => $result->walletRefundSen,
            'admin_user_id' => $adminUserId,
        ]);

        $this->notify($order->fresh(), $result);

        return $result;
    }

    /**
     * @return array{num: int, den: int, cash: int, voucher: int}
     */
    private function amounts(Order $order): array
    {
        [$num, $den] = $this->undeliveredShare($order);
        $share = fn (int $sen): int => (int) round($sen * $num / $den);

        // A wallet refund gives back what left the wallet (final_amount);
        // a retail voucher never refunds the payment-gateway fee.
        $cashPaid = $order->wallet_reseller_id !== null
            ? $order->final_amount
            : $order->final_amount - $order->transaction_fee;

        return [
            'num' => $num,
            'den' => $den,
            'cash' => $share($cashPaid),
            'voucher' => $share((int) $order->voucher_discount),
        ];
    }

    /** @return array{0: int, 1: int} */
    private function undeliveredShare(Order $order): array
    {
        if ($order->delivery_status !== DeliveryStatus::PartiallyDelivered) {
            return [1, 1];
        }

        $legs = $order->deliveryLegs()->get();
        $den = (int) $legs->sum('selling_price_sen');

        if ($den <= 0) {
            // A leg seeded before selling_price_sen existed — no proportion is derivable.
            throw new OrderFulfillmentException("Order #{$order->id} has no frozen leg prices — cannot apportion a partial delivery");
        }

        return [(int) $legs->where('status', DeliveryStatus::Failed)->sum('selling_price_sen'), $den];
    }

    /** @param array{num: int, den: int, cash: int, voucher: int} $amounts */
    private function creditDeliveredProfit(Order $locked, array $amounts): void
    {
        $deliveredCost = (int) OrderDeliveryLeg::query()
            ->where('order_id', $locked->id)
            ->where('status', DeliveryStatus::Delivered->value)
            ->with('componentPackage:id,cost_price')
            ->get()
            ->sum(fn (OrderDeliveryLeg $leg) => $leg->costSen());

        $affiliateProfit = $locked->affiliate_profit - (int) round($locked->affiliate_profit * $amounts['num'] / $amounts['den']);
        $platformProfit = ($locked->selling_price - $amounts['cash'] - $amounts['voucher']) - $deliveredCost - $affiliateProfit;

        $locked->update([
            'platform_profit' => $platformProfit,
            'affiliate_profit' => $affiliateProfit,
            'profit_reconciled_flagged' => $platformProfit < 0,
        ]);

        $this->ledger->creditOrderProfit($locked);
    }

    private function restoreQuota(Order $locked, int $num, int $den): void
    {
        $debited = MembershipQuotaDebit::query()->where('order_id', $locked->id)->value('amount_sen');

        if ($debited !== null) {
            $this->quota->restore($locked->id, (int) round($debited * $num / $den));
        }
    }

    private function defaultReason(Order $order): string
    {
        return $order->delivery_status === DeliveryStatus::PartiallyDelivered
            ? "Partial delivery - refund voucher for the undelivered part of order {$order->order_number}"
            : "Delivery failed - refund voucher for order {$order->order_number}";
    }

    /** After commit, so a rolled-back settlement never messages anyone. */
    private function notify(Order $order, SettlementResult $result): void
    {
        if ($order->wallet_reseller_id === null) {
            if ($result->voucher !== null) {
                $this->notifications->voucherIssued($result->voucher);
            } elseif (VoucherRedemption::query()->where('order_id', $order->id)->where('status', 'restored')->exists()) {
                $this->notifications->voucherRestored($order);
            }

            return;
        }

        // ADR-076 decision 6: compensation never touches delivery_status,
        // so it's invisible to the status listeners — notify explicitly,
        // once (refund_notified_at).
        $notification = ResellerBotOrderNotification::query()
            ->where('order_id', $order->id)
            ->whereNull('refund_notified_at')
            ->first();

        if ($notification !== null) {
            $this->openWa->sendText($notification->whatsapp_group_id, ResellerBotReplyFormatter::refundNotice($order));
            $notification->update(['refund_notified_at' => now()]);
        }

        // ADR-084 PR-3 decision 4 — the API channel's counterpart.
        $this->webhooks->dispatch($order, ResellerWebhookEvent::OrderRefunded);
    }
}
