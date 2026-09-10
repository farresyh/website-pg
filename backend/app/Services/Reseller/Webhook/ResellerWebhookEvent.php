<?php

namespace App\Services\Reseller\Webhook;

use App\Services\Order\DeliveryStatus;

/**
 * ADR-084 PR-3 decision 4: the three delivery-webhook event types. The
 * string value is the external contract — it appears verbatim in the
 * `event` field of every webhook body and in the docs' event catalogue.
 */
enum ResellerWebhookEvent: string
{
    case OrderDelivered = 'order.delivered';
    case OrderFailed = 'order.failed';
    case OrderRefunded = 'order.refunded';

    /**
     * The event a terminal `delivery_status` maps to, or null for a
     * non-terminal state the webhook does not emit for (`needs_review`,
     * `processing`, `pending`). `order.refunded` is never reached this
     * way — it is dispatched explicitly from the admin wallet-refund
     * action, which deliberately never touches `delivery_status`.
     */
    public static function forDeliveryStatus(DeliveryStatus $status): ?self
    {
        return match ($status) {
            DeliveryStatus::Delivered => self::OrderDelivered,
            DeliveryStatus::Failed => self::OrderFailed,
            default => null,
        };
    }
}
