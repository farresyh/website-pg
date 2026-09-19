<?php

namespace App\Services\Payment\Chip;

use App\Models\PaymentGateway;

/**
 * ADR-110 PR-C — the one place that reads `payment_gateways` (DB,
 * founder-editable via `/middleware/payment-gateways`) with a
 * `config('services.chip')` (`.env`) fallback per-field. Originally
 * inlined into `AppServiceProvider`'s `payment-gateway.chip` binding;
 * extracted so the two manual smoke-test commands
 * (`app:chip-smoke-test`, `app:chip-webhook-smoke-test`) resolve
 * credentials identically to real production traffic instead of
 * reading `config('services.chip')` directly — found live: those two
 * commands would have silently broken (empty secret_key) the moment
 * `.env`'s `CHIP_SECRET_KEY`/`CHIP_BRAND_ID` are removed as part of
 * this ADR's own cutover, even though the real checkout/webhook path
 * was already safe.
 */
final class ChipCredentialResolver
{
    /**
     * @return array{base_url: string, secret_key: string, brand_id: string}
     */
    public function resolve(): array
    {
        $config = config('services.chip');
        $dbConfig = PaymentGateway::query()->where('gateway_key', 'chip')->first()?->api_config ?? [];

        return [
            'base_url' => $dbConfig['base_url'] ?? $config['base_url'],
            'secret_key' => (string) ($dbConfig['secret_key'] ?? $config['secret_key']),
            'brand_id' => (string) ($dbConfig['brand_id'] ?? $config['brand_id']),
        ];
    }
}
