<?php

namespace App\Services\Notification;

use App\Models\Order;
use App\Services\Order\DeliveryStatus;
use App\Services\Order\PaymentStatus;

/**
 * ADR-116 2026-09-30 addendum: the one WhatsApp layout for an order,
 * used for the reply to any customer message that carries an order number and
 * for the Delivered receipt. It shows only what the public track-order page
 * already shows to anyone holding the order number, never the full email or
 * phone.
 */
final class OrderStatusCard
{
    /** @param  array{name: string, host: string}  $brand */
    public static function render(Order $order, array $brand): string
    {
        $player = $order->player_id.($order->server_id ? " ({$order->server_id})" : '');

        $lines = [
            "📦 *Order: {$order->order_number}*",
            "🏪 Store: {$brand['name']}",
            '🎮 Game: '.($order->game?->name ?? '—'),
            '💎 Package: '.($order->package?->name ?? 'Top up'),
            "👤 Player ID: {$player}",
            '💰 Amount: RM'.number_format($order->final_amount / 100, 2),
            '💳 Payment: '.strtoupper((string) ($order->payment_method ?: '—')),
            '🔄 Payment status: '.self::paymentStatus($order->payment_status),
            '🚀 Delivery status: '.self::deliveryStatus($order),
            '',
            self::closingLine($order, $brand),
        ];

        return implode("\n", $lines);
    }

    private static function paymentStatus(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Paid => '✅ Payment received',
            PaymentStatus::Failed => '❌ Payment failed',
            default => '⏳ Awaiting payment',
        };
    }

    private static function deliveryStatus(Order $order): string
    {
        return match ($order->delivery_status) {
            DeliveryStatus::Delivered => '✅ Delivered',
            DeliveryStatus::Failed => '❌ Failed',
            DeliveryStatus::NeedsReview => '🔎 Under review',
            default => $order->payment_status === PaymentStatus::Paid ? '⏳ Processing' : '— Not started',
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
            DeliveryStatus::Delivered => "Your top-up is in your game account. Enjoyed it? Leave a quick review: https://{$brand['host']}/order/status/{$order->order_number}",
            DeliveryStatus::Failed => "We couldn't complete this order. Our team is on it, and if it can't be delivered you'll receive a voucher for the full amount here.",
            DeliveryStatus::NeedsReview => "Our team is checking this order manually. We'll message you here as soon as it's resolved.",
            default => "Your order is being processed. We'll message you here once it's delivered. Thanks for your patience!",
        };
    }
}
