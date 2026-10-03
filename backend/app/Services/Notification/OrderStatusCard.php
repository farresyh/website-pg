<?php

namespace App\Services\Notification;

use App\Models\Order;
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
 * holding the order number, never the full email or phone.
 */
final class OrderStatusCard
{
    /** @param  array{name: string, host: string}  $brand */
    public static function render(Order $order, array $brand): string
    {
        $player = $order->player_id.($order->server_id ? " ({$order->server_id})" : '');
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
            DeliveryStatus::Delivered => ["✅ Paid · {$paid}", '✅ Processed', "✅ Delivered to Player ID {$player}"],
            DeliveryStatus::Failed => ["✅ Paid · {$paid}", "❌ Delivery failed · Player ID {$player}"],
            DeliveryStatus::NeedsReview => ["✅ Paid · {$paid}", '🔎 Under review', "○ Delivery to Player ID {$player}"],
            DeliveryStatus::PartiallyDelivered => ["✅ Paid · {$paid}", "⚠️ Partly delivered · Player ID {$player}"],
            default => ["✅ Paid · {$paid}", '⏳ Processing', "○ Delivery to Player ID {$player}"],
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
            DeliveryStatus::Delivered => "Your top-up is in your game account. Enjoyed it? Leave a quick review:\nhttps://{$brand['host']}/order/status/{$order->order_number}",
            DeliveryStatus::Failed => "We couldn't complete this order. Our team is on it, and if it can't be delivered you'll receive a voucher for the full amount here.",
            DeliveryStatus::NeedsReview => "Our team is checking this order manually. We'll message you here as soon as it's resolved.",
            // ADR-094 decision 39 — never says why (combo stays opaque).
            DeliveryStatus::PartiallyDelivered => "Part of this order couldn't be completed. The part that didn't go through will be returned to you as a voucher here.",
            default => "We'll message you here once it's delivered. Thanks for your patience!",
        };
    }
}
