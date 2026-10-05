<?php

namespace App\Services\Checkout;

use App\Models\Game;

/**
 * ADR-097 decision 19 — the ONE rule shared by every path that sends a
 * player ID to a supplier or a paid validator (storefront
 * `CheckoutController`, `ResellerApi\OrderController`,
 * `ResellerBotService` `.order`/`.checkid`, admin Resend with an ID
 * correction, `PlayerValidationController`). The 2026-10-05 addendum
 * (decisions 27-28) widened it from "extra field present + zone in
 * list" to each game's full contract:
 *
 * - `player_id`: `player_id_format` `numeric` (default) = digits only;
 *   `text` = letters, digits and `# . _ -` (a Riot ID).
 * - `server_id` by `extra_field`: null = must be absent; `server_id` =
 *   required, digits only; `zone_id` = required, in `zone_options` when
 *   a list exists.
 * - Both: no whitespace, max 64 characters. Leading/trailing whitespace
 *   never reaches here — Laravel's global `TrimStrings` trims HTTP
 *   input and the Bot parser splits on whitespace — so any whitespace
 *   left is inside the value.
 *
 * The wire attribute for the extra field is always `server_id`, even
 * for a zone. Each caller shapes its OWN response from the returned
 * `{field, reason, message}` (null = valid): a 422 form error, a
 * `ResellerApiException::validationFailed()` wrap, or a WhatsApp reply
 * (the Bot words it in BM from `reason`). This class only decides
 * WHETHER the input is acceptable, never how a channel reports it.
 */
final class CheckoutInputValidator
{
    public const MAX_LENGTH = 64;

    /**
     * @return array{field: 'player_id'|'server_id', reason: string, message: string}|null null = valid
     */
    public function validate(Game $game, ?string $playerId, ?string $serverId): ?array
    {
        $rules = $game->validation_rules ?? [];

        return $this->checkPlayerId($playerId, $game->playerIdFormat() === 'text')
            ?? $this->checkExtraField($rules['extra_field'] ?? null, $serverId, $game->zoneOptions());
    }

    private function checkPlayerId(?string $value, bool $text): ?array
    {
        $label = 'User ID';

        if ($value === null || $value === '') {
            return $this->error('player_id', 'required', "{$label} is required.");
        }

        if ($common = $this->checkCommon('player_id', $label, $value)) {
            return $common;
        }

        if ($text) {
            return preg_match('/^[A-Za-z0-9#._-]+$/', $value) === 1
                ? null
                : $this->error('player_id', 'text_chars', "{$label} may only contain letters, digits and # . _ -");
        }

        return ctype_digit($value) ? null : $this->error('player_id', 'digits_only', "{$label} must contain digits only.");
    }

    /** @param  list<string>|null  $zoneOptions */
    private function checkExtraField(?string $extraField, ?string $value, ?array $zoneOptions): ?array
    {
        $present = $value !== null && $value !== '';

        if ($extraField === null) {
            return $present ? $this->error('server_id', 'not_taken', 'This game does not take a Server ID.') : null;
        }

        $label = $extraField === 'zone_id' ? 'Zone ID' : 'Server ID';

        if (! $present) {
            return $this->error('server_id', 'required', "This game requires a {$label}.");
        }

        if ($common = $this->checkCommon('server_id', $label, $value)) {
            return $common;
        }

        if ($extraField === 'zone_id') {
            return $zoneOptions === null || in_array($value, $zoneOptions, true)
                ? null
                : $this->error('server_id', 'invalid_zone', 'Invalid Zone ID. Valid options: '.implode(', ', $zoneOptions).'.');
        }

        return ctype_digit($value) ? null : $this->error('server_id', 'digits_only', "{$label} must contain digits only.");
    }

    private function checkCommon(string $field, string $label, string $value): ?array
    {
        if (preg_match('/\s/u', $value) === 1) {
            return $this->error($field, 'whitespace', "{$label} must not contain spaces.");
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            return $this->error($field, 'too_long', "{$label} must not be longer than ".self::MAX_LENGTH.' characters.');
        }

        return null;
    }

    /** @return array{field: 'player_id'|'server_id', reason: string, message: string} */
    private function error(string $field, string $reason, string $message): array
    {
        return ['field' => $field, 'reason' => $reason, 'message' => $message];
    }
}
