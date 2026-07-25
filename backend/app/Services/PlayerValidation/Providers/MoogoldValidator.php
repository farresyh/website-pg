<?php

namespace App\Services\PlayerValidation\Providers;

use App\Services\PlayerValidation\PlayerValidationResult;
use App\Services\PlayerValidation\PlayerValidator;
use App\Services\PlayerValidation\ProviderUnavailableException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * MooGold's unofficial WooCommerce id-validation-new plugin
 * (docs/prd.md's research, confirmed live 2026-07-25) — the most
 * fragile of the three: no structured fields, everything bundled into
 * a free-text `message` string, and the shape isn't even internally
 * consistent (a "Country" line is sometimes present, sometimes not,
 * for the identical account — confirmed by re-testing the same ID
 * twice). Last in the fallback chain: only reached when both
 * AcidGameShop and Nexone are unavailable.
 *
 * `status` (not `icon`) is the real validity flag — `icon` stays
 * "success" even on a confirmed-invalid ID (confirmed live), so it
 * must never be used to decide validity.
 *
 * The `text-*` field names are WooCommerce-generated and, per the
 * founder's own research, may not be stable across MooGold's
 * checkout-form builds — hardcoded here because they're confirmed
 * working live today; if MooGold starts failing where the other two
 * providers succeed, this is the first place to check.
 */
final class MoogoldValidator implements PlayerValidator
{
    private const PLAYER_ID_FIELD = 'text-5f6f144f8ffee';

    private const SERVER_ID_FIELD = 'text-1601115253775';

    private const PRODUCT_ID = '15145';

    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 8,
    ) {}

    public function validate(string $playerId, ?string $serverId): PlayerValidationResult
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->asForm()
                ->post('/wp-content/plugins/id-validation-new/id-validation-ajax.php', [
                    'attribute_amount' => '',
                    self::PLAYER_ID_FIELD => $playerId,
                    self::SERVER_ID_FIELD => $serverId,
                    'quantity' => 1,
                    'add-to-cart' => self::PRODUCT_ID,
                    'product_id' => self::PRODUCT_ID,
                    'variation_id' => 0,
                ]);
        } catch (Throwable $e) {
            throw new ProviderUnavailableException('MooGold request failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new ProviderUnavailableException("MooGold returned unexpected HTTP {$response->status()}");
        }

        $status = $response->json('status');

        if ($status === null) {
            throw new ProviderUnavailableException('MooGold response missing status field');
        }

        if ($status !== 'true') {
            return PlayerValidationResult::invalid('moogold');
        }

        $message = (string) $response->json('message', '');

        return PlayerValidationResult::valid(
            provider: 'moogold',
            nickname: $this->extractLine($message, 'In-Game Nickname'),
            countryCode: $this->extractLine($message, 'Country'),
        );
    }

    private function extractLine(string $message, string $label): ?string
    {
        if (preg_match('/'.preg_quote($label, '/').':\s*(.+)/', $message, $match)) {
            return trim($match[1]);
        }

        return null;
    }
}
