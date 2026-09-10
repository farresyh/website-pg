<?php

namespace App\Services\Reseller\Webhook;

use App\Models\Reseller;
use App\Models\ResellerWebhook;
use Illuminate\Support\Str;

/**
 * ADR-084 PR-3 decision 4: the ONE seam that creates, rotates, and
 * removes a `Reseller` (wallet) account's delivery-webhook endpoint —
 * the portal (`ResellerPortal\WebhookController`) and admin
 * (`Admin\ResellerWebhookController`) both call this; neither touches the
 * `secret` directly.
 *
 * The `secret` is shown in plaintext exactly once — in `setEndpoint()`'s
 * (on first creation) or `rotateSecret()`'s return value. It is stored
 * `encrypted` (see `ResellerWebhook`), not hashed, because every outbound
 * delivery must re-derive it to sign the body (`sign()` below).
 */
final class ResellerWebhookService
{
    private const SECRET_PREFIX = 'pgwh_';

    /**
     * Create the endpoint (first call) or update its URL (subsequent
     * calls). A fresh secret is generated only on creation — an existing
     * endpoint keeps its secret through a URL change; use
     * `rotateSecret()` to replace it deliberately.
     *
     * @return array{webhook: ResellerWebhook, secret: string|null} `secret` is non-null only when it was just generated
     */
    public function setEndpoint(Reseller $reseller, string $url): array
    {
        $webhook = $reseller->webhook;

        if ($webhook === null) {
            $plainText = self::generateSecret();

            $webhook = ResellerWebhook::query()->create([
                'reseller_id' => $reseller->id,
                'url' => $url,
                'secret' => $plainText,
                'is_active' => true,
            ]);

            $reseller->setRelation('webhook', $webhook);

            return ['webhook' => $webhook, 'secret' => $plainText];
        }

        $webhook->update(['url' => $url]);

        return ['webhook' => $webhook, 'secret' => null];
    }

    /** Replace the signing secret. Returns the new plaintext — shown once. */
    public function rotateSecret(ResellerWebhook $webhook): string
    {
        $plainText = self::generateSecret();

        $webhook->update(['secret' => $plainText]);

        return $plainText;
    }

    public function setActive(ResellerWebhook $webhook, bool $isActive): void
    {
        $webhook->update(['is_active' => $isActive]);
    }

    public function remove(ResellerWebhook $webhook): void
    {
        // Delivery-log rows key off `reseller_id`/`order_id`, not the
        // webhook row — the dead-letter history survives an endpoint
        // being removed and re-added.
        $webhook->delete();
    }

    /**
     * The `X-Hub-Signature-256` header value for a raw JSON body — the
     * exact scheme `DigiflazzWebhookController` / `OpenWaWebhookController`
     * verify on the inbound side, kept as one HMAC convention across the
     * codebase.
     */
    public static function sign(string $secret, string $rawBody): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, $secret);
    }

    private static function generateSecret(): string
    {
        return self::SECRET_PREFIX.Str::random(48);
    }
}
