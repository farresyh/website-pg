<?php

namespace App\Services\Reseller;

/**
 * ADR-142 decision 16: why a reseller is refused, worded once for every
 * channel. `message` is English (portal login, REST API); `messageMs` is the
 * bot's Malay reply.
 */
final readonly class ResellerDenial
{
    public function __construct(
        public ResellerDeactivationReason $reason,
        public string $message,
        public string $messageMs,
    ) {}
}
