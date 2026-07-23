<?php

namespace App\Services\Pricing;

use RuntimeException;

/**
 * Thrown when Package/Reseller pricing config would make the platform
 * lose money on a sale — always a config mistake, never a valid state.
 */
final class InvalidPricingConfigException extends RuntimeException
{
}
