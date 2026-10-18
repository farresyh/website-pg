<?php

namespace Tests\Support;

use App\Models\Order;
use App\Models\OrderCredential;
use App\Models\PlatformSettings;

/** ADR-124: an order's login as checkout (item 113) will store it. Needs AdminOrderControllerFixtures. */
trait OrderCredentialFixtures
{
    private function credentialFor(Order $order, array $overrides = []): OrderCredential
    {
        return OrderCredential::query()->create(array_merge([
            'order_id' => $order->id,
            'fields' => [['key' => 'username', 'label' => 'Username', 'value' => 'player_one']],
            'secret_fields' => [['key' => 'password', 'label' => 'Password']],
            'secrets' => ['password' => 'hunter2-secret'],
            'secrets_set_at' => now(),
            'consented_at' => now(),
            'consent_text' => PlatformSettings::DEFAULT_CREDENTIAL_CONSENT_TEXT,
        ], $overrides));
    }
}
