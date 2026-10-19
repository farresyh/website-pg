<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Models\PlatformSettings;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;

/**
 * ADR-116 2026-09-30 addendum: the one WhatsApp layout for an order,
 * used for the reply to any customer message that carries an order number and
 * for the Delivered receipt. It is a three-step timeline in the same language
 * as the order page's own stepper (Payment → Processing → Delivered),
 * deliberately not a competitor's "label: value" list.
 *
 * It shows only what the public track-order page already shows to anyone
 * holding the order number, never the full email or phone. A login order
 * (ADR-125 decisions 24/31) shows its masked login instead of a Player ID,
 * and its Delivered line asks the buyer to change their password.
 */
final class OrderStatusCard
{
    /** @param  array{name: string, host: string}  $brand */
    public static function render(Order $order, array $brand): string
    {
        $player = ($order->isLoginOrder() ? 'login ' : 'Player ID ').$order->publicPlayerLabel();
        $item = ($order->package?->name ?? 'Top up').($order->game?->name ? ' · '.$order->game->name : '');

        return implode("\n", [
            "*{$brand['name']}* · Order {$order->order_number}",
            '',
            ...self::timeline($order, $player),
            '',
            $item,
            '',
            self::closingLine($order, $brand),
        ]);
    }

    /** @return list<string> */
    private static function timeline(Order $order, string $player): array
    {
        $amount = 'RM'.number_format($order->final_amount / 100, 2);
        $method = strtoupper((string) ($order->payment_method ?: ''));
        $paid = $amount.($method !== '' ? " via {$method}" : '');

        if ($order->payment_status === PaymentStatus::Failed) {
            return ["❌ Payment failed · {$amount}"];
        }

        if ($order->payment_status !== PaymentStatus::Paid) {
            return ["⏳ Awaiting payment · {$amount}", '○ Processing', '○ Delivery'];
        }

        return match ($order->delivery_status) {
            DeliveryStatus::Delivered => ["✅ Paid · {$paid}", '✅ Processed', "✅ Delivered to {$player}"],
            DeliveryStatus::Failed => ["✅ Paid · {$paid}", "❌ Delivery failed · {$player}"],
            DeliveryStatus::NeedsReview => ["✅ Paid · {$paid}", '🔎 Under review', "○ Delivery to {$player}"],
            DeliveryStatus::PartiallyDelivered => ["✅ Paid · {$paid}", "⚠️ Partly delivered · {$player}"],
            default => ["✅ Paid · {$paid}", '⏳ Processing', "○ Delivery to {$player}"],
        };
    }

    /** @param  array{name: string, host: string}  $brand */
    private static function closingLine(Order $order, array $brand): string
    {
        if ($order->payment_status === PaymentStatus::Failed) {
            return 'Your payment did not go through, so this order will not be processed. You can place a new order anytime.';
        }

        if ($order->payment_status !== PaymentStatus::Paid) {
            return "We haven't received payment for this order yet. Once it's paid, we'll start processing it right away.";
        }

        return match ($order->delivery_status) {
            DeliveryStatus::Delivered => ($order->isLoginOrder()
                ? 'Your top-up is done. We delete your login within about '.PlatformSettings::current()->credential_close_grace_hours.' hours. Please change your game password now.'
                : 'Your top-up is in your game account.')
                ." Enjoyed it? Leave a quick review:\nhttps://{$brand['host']}/order/status/{$order->order_number}",
            // Item 116: OrderSettlementService refunds final_amount − transaction_fee.
            DeliveryStatus::Failed => "We couldn't complete this order. Our team is on it, and if it can't be delivered you'll receive a voucher here for what you paid, excluding the payment fee.",
            DeliveryStatus::NeedsReview => "Our team is checking this order manually. We'll message you here as soon as it's resolved.",
            // ADR-094 decision 39 — never says why (combo stays opaque).
            DeliveryStatus::PartiallyDelivered => "Part of this order couldn't be completed. The part that didn't go through will be returned to you as a voucher here.",
            default => "We'll message you here once it's delivered. Thanks for your patience!",
        };
    }
}
