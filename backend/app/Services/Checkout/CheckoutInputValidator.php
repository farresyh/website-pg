<?php

namespace App\Services\Checkout;

use App\Models\Game;

/**
 * ADR-097 decision 19/8 — the ONE rule (presence + zone-value-
 * restriction) shared by all three order-placement channels
 * (storefront `CheckoutController`, `ResellerApi\OrderController`,
 * `ResellerBotService::handleOrder()`), replacing what would otherwise
 * be three independent copies of the same if-chain. The wire attribute
 * is always `server_id` — even when the game's `extra_field` is
 * `zone_id` — matching every existing caller's own payload key, so the
 * returned `field` is a constant, not a per-`extra_field` value.
 *
 * Each caller shapes its OWN response from the returned
 * `{field, message}` (or `null` = valid) — a 422 form-error array, a
 * `ResellerApiException::validationFailed()` wrap, or a WhatsApp reply
 * text — this class only ever decides WHETHER a value is acceptable,
 * never how a channel reports it.
 */
final class CheckoutInputValidator
{
    /**
     * @return array{field: string, message: string}|null null = valid
     */
    public function validate(Game $game, ?string $value): ?array
    {
        $extraField = $game->validation_rules['extra_field'] ?? null;

        if ($extraField === null) {
            return null;
        }

        if ($value === null || $value === '') {
            return [
                'field' => 'server_id',
                'message' => "This game requires a {$this->fieldLabel($extraField)}.",
            ];
        }

        if ($extraField === 'zone_id') {
            $options = $game->zoneOptions();

            if ($options !== null && ! in_array($value, $options, true)) {
                return [
                    'field' => 'server_id',
                    'message' => 'Invalid Zone ID. Valid options: '.implode(', ', $options).'.',
                ];
            }
        }

        return null;
    }

    private function fieldLabel(string $extraField): string
    {
        return match ($extraField) {
            'zone_id' => 'Zone ID',
            'server_id' => 'Server ID',
            default => 'additional field',
        };
    }
}
