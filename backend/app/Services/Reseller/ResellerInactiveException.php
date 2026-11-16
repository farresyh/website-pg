<?php

namespace App\Services\Reseller;

use RuntimeException;

/**
 * ADR-073 decision 4, ADR-142 decision 16: a deactivated or closed reseller
 * is refused. Carries the one denial, so every channel says the same thing:
 * `getMessage()` in English, `$denial->messageMs` for the bot.
 */
final class ResellerInactiveException extends RuntimeException
{
    public function __construct(public readonly ResellerDenial $denial)
    {
        parent::__construct($denial->message);
    }
}
