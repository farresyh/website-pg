<?php

namespace App\Support;

use App\Models\ResellerWebhook;
use App\Models\ResellerWebhookDelivery;

/**
 * ADR-084 PR-3: the one shape the portal (`ResellerPortal\WebhookController`)
 * and admin (`Admin\ResellerWebhookController`) both return for the
 * webhook config and its delivery log — so the two screens can never
 * drift. `secret` is never in either shape; it is shown once, at
 * set/rotate time, straight from the service return value.
 */
final class ResellerWebhookPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function webhook(?ResellerWebhook $webhook): ?array
    {
        if ($webhook === null) {
            return null;
        }

        return [
            'url' => $webhook->url,
            'is_active' => $webhook->is_active,
            'created_at' => $webhook->created_at?->toIso8601String(),
            'updated_at' => $webhook->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function delivery(ResellerWebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'event' => $delivery->event,
            'event_id' => $delivery->event_id,
            'order_number' => $delivery->order?->order_number,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'last_response_code' => $delivery->last_response_code,
            'next_retry_at' => $delivery->next_retry_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
            'updated_at' => $delivery->updated_at?->toIso8601String(),
        ];
    }
}
