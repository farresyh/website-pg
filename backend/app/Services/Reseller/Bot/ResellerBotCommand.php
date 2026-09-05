<?php

namespace App\Services\Reseller\Bot;

/** A parsed WhatsApp command — `ResellerBotCommandParser`'s one output shape. */
final class ResellerBotCommand
{
    public function __construct(
        public readonly ResellerBotCommandType $type,
        public readonly string $raw,
        public readonly ?string $gameCode = null,
        public readonly ?string $productCode = null,
        public readonly ?string $playerId = null,
        public readonly ?string $serverId = null,
        public readonly ?string $orderNumber = null,
    ) {}
}
